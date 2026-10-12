<?php

declare(strict_types=1);

namespace Mykholy\Flowguard\Tests\Concurrency;

use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once __DIR__.'/bootstrap.php';

/** Four independent PHP processes compete for one manual classification. */
final class ManualResolutionRaceTest extends TestCase
{
    private Connection $db;

    private string $ops;

    private string $evidence;

    private string $decisions;

    private string $operationId;

    private string $evidenceId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = \flowguardConcurrencyConnection();
        $suffix = bin2hex(random_bytes(6));
        $this->ops = 'fg_res_ops_'.$suffix;
        $this->evidence = 'fg_res_ev_'.$suffix;
        $this->decisions = 'fg_res_dec_'.$suffix;
        $this->operationId = '11111111-1111-4111-8111-'.$suffix;
        $this->evidenceId = '22222222-2222-4222-8222-'.$suffix;

        $this->db->getSchemaBuilder()->create($this->ops, function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('scope', 120);
            $table->string('status', 32);
            $table->string('reconciliation_reason', 120)->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        $ops = $this->ops;
        $this->db->getSchemaBuilder()->create($this->evidence, function (Blueprint $table) use ($ops): void {
            $table->uuid('id')->primary();
            $table->uuid('operation_id');
            $table->string('actor_reference', 120);
            $table->string('outcome', 32);
            $table->string('source', 120);
            $table->string('evidence_code', 120);
            $table->dateTime('observed_at', 6);
            $table->boolean('provider_reference_present');
            $table->timestamps(6);
            $table->foreign('operation_id')->references('id')->on($ops)->restrictOnDelete();
        });

        $ev = $this->evidence;
        $this->db->getSchemaBuilder()->create($this->decisions, function (Blueprint $table) use ($ops, $ev): void {
            $table->uuid('id')->primary();
            $table->uuid('operation_id')->unique();
            $table->uuid('evidence_id');
            $table->string('status', 32);
            $table->string('initiator_reference', 120);
            $table->string('approver_reference', 120);
            $table->string('approval_reference', 128);
            $table->timestamps(6);
            $table->foreign('operation_id')->references('id')->on($ops)->restrictOnDelete();
            $table->foreign('evidence_id')->references('id')->on($ev)->restrictOnDelete();
        });

        $now = now();
        $this->db->table($this->ops)->insert([
            'id' => $this->operationId,
            'scope' => 'payments.capture',
            'status' => 'requires_reconciliation',
            'reconciliation_reason' => 'provider_timeout',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->db->table($this->evidence)->insert([
            'id' => $this->evidenceId,
            'operation_id' => $this->operationId,
            'actor_reference' => 'reviewer.one',
            'outcome' => 'confirmed_success',
            'source' => 'gateway.status',
            'evidence_code' => 'capture_found',
            'observed_at' => $now->format('Y-m-d H:i:s.u'),
            'provider_reference_present' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    protected function tearDown(): void
    {
        try {
            foreach ([$this->decisions, $this->evidence, $this->ops] as $table) {
                $this->db->getSchemaBuilder()->dropIfExists($table);
            }
        } finally {
            parent::tearDown();
        }
    }

    public function test_four_reviewers_result_in_one_audit_and_one_transition(): void
    {
        if (! function_exists('proc_open')) {
            self::markTestSkipped('proc_open required for independent worker contention.');
        }

        $gate = sys_get_temp_dir().'/flowguard-resolution-'.bin2hex(random_bytes(12));
        $env = array_merge($_ENV, [
            'FLOWGUARD_CONCURRENCY_TEST' => '1',
            'FLOWGUARD_TEST_DATABASE' => 'flowguard_ci',
            'FLOWGUARD_TEST_DRIVER' => (string) getenv('FLOWGUARD_TEST_DRIVER'),
            'FLOWGUARD_TEST_HOST' => (string) getenv('FLOWGUARD_TEST_HOST'),
            'FLOWGUARD_TEST_PORT' => (string) getenv('FLOWGUARD_TEST_PORT'),
            'FLOWGUARD_TEST_USERNAME' => (string) getenv('FLOWGUARD_TEST_USERNAME'),
            'FLOWGUARD_TEST_PASSWORD' => (string) getenv('FLOWGUARD_TEST_PASSWORD'),
            'FG_RES_OPS' => $this->ops,
            'FG_RES_EV' => $this->evidence,
            'FG_RES_DEC' => $this->decisions,
            'FG_RES_ID' => $this->operationId,
            'FG_RES_EVIDENCE_ID' => $this->evidenceId,
            'FG_RES_GATE' => $gate,
        ]);

        $workers = [];

        try {
            for ($i = 0; $i < 4; $i++) {
                $pipes = [];
                $process = proc_open([PHP_BINARY, __DIR__.'/resolution-worker.php'], [
                    0 => ['pipe', 'r'],
                    1 => ['pipe', 'w'],
                    2 => ['pipe', 'w'],
                ], $pipes, dirname(__DIR__, 2), $env);

                if (! is_resource($process)) {
                    throw new RuntimeException('Unable to start concurrent resolution worker.');
                }
                fclose($pipes[0]);
                $workers[] = [$process, $pipes];
            }

            touch($gate);
            $results = [];
            foreach ($workers as [$process, $pipes]) {
                $out = stream_get_contents($pipes[1]);
                $err = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                $exit = proc_close($process);

                if ($exit !== 0) {
                    throw new RuntimeException('Concurrent resolution worker failed: '.$err);
                }

                $results[] = json_decode((string) $out, true, 512, JSON_THROW_ON_ERROR);
            }
            $workers = [];

            self::assertCount(4, $results);
            self::assertSame(1, count(array_filter($results, static fn (array $result): bool => $result['resolved'] === true)));
            self::assertSame(1, $this->db->table($this->decisions)->count());
            self::assertSame('reconciled_success', $this->db->table($this->ops)->value('status'));
        } finally {
            @unlink($gate);
            foreach ($workers as [$process, $pipes]) {
                if (is_resource($process)) {
                    proc_terminate($process);
                }
                foreach ($pipes as $pipe) {
                    if (is_resource($pipe)) {
                        fclose($pipe);
                    }
                }
                if (is_resource($process)) {
                    proc_close($process);
                }
            }
        }
    }
}
