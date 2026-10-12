<?php

declare(strict_types=1);

namespace Mykholy\Flowguard\Tests\Concurrency;

use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Mykholy\Flowguard\Domain\OperationIdentity;
use Mykholy\Flowguard\Domain\OperationStatus;
use Mykholy\Flowguard\Infrastructure\DatabaseOperationStore;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once __DIR__.'/bootstrap.php';

/** This suite is run only against an explicitly provisioned disposable CI database. */
final class DatabaseRaceTest extends TestCase
{
    private Connection $db;

    private string $table;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = \flowguardConcurrencyConnection();
        $this->table = 'fg_race_'.bin2hex(random_bytes(6));
        $this->db->getSchemaBuilder()->create($this->table, function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('scope', 120);
            $table->char('key_hash', 64);
            $table->char('payload_hash', 64);
            $table->char('claim_hash', 64);
            $table->string('status', 32);
            $table->json('result_json')->nullable();
            $table->string('reconciliation_reason', 120)->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->unique(['scope', 'key_hash']);
        });
    }

    protected function tearDown(): void
    {
        try {
            $this->db->getSchemaBuilder()->dropIfExists($this->table);
        } finally {
            parent::tearDown();
        }
    }

    public function test_parallel_claims_allow_exactly_one_owner(): void
    {
        $results = $this->race(16, 'parallel-'.bin2hex(random_bytes(8)));
        self::assertCount(16, $results);
        self::assertSame(1, count(array_filter($results, static fn (array $result): bool => $result['acquired'] === true)));
        self::assertCount(1, array_unique(array_column($results, 'id')));
        self::assertSame(1, $this->db->table($this->table)->count());
        self::assertSame(OperationStatus::Processing->value, $this->db->table($this->table)->value('status'));
    }

    public function test_orphaned_owner_cannot_be_reclaimed_or_replayed(): void
    {
        $key = 'orphan-'.bin2hex(random_bytes(8));
        $results = $this->race(12, $key);
        self::assertSame(1, count(array_filter($results, static fn (array $result): bool => $result['acquired'] === true)));
        // Owner process has exited and lost its token. No new process can own that operation.
        $after = (new DatabaseOperationStore($this->db, $this->table))
            ->acquire(OperationIdentity::from('payments.capture', $key, ['amount_minor' => 1200, 'currency' => 'EGP']));
        self::assertFalse($after->acquired());
        self::assertSame(OperationStatus::Processing, $after->operation->status);
    }

    public function test_storage_failure_never_grants_a_claim(): void
    {
        $store = new DatabaseOperationStore($this->db, 'fg_race_'.bin2hex(random_bytes(6)));
        $this->expectException(\Throwable::class);
        $store->acquire(OperationIdentity::from('payments.capture', 'missing-table', []));
    }

    /** @return list<array{acquired: bool, id: string, status: string}> */
    private function race(int $count, string $key): array
    {
        if (! function_exists('proc_open')) {
            self::markTestSkipped('proc_open is required for multiprocess race testing.');
        }
        $gate = sys_get_temp_dir().'/flowguard-gate-'.bin2hex(random_bytes(12));
        $env = array_merge($_ENV, [
            'FLOWGUARD_CONCURRENCY_TEST' => '1',
            'FLOWGUARD_TEST_DATABASE' => 'flowguard_ci',
            'FLOWGUARD_TEST_DRIVER' => (string) getenv('FLOWGUARD_TEST_DRIVER'),
            'FLOWGUARD_TEST_HOST' => (string) getenv('FLOWGUARD_TEST_HOST'),
            'FLOWGUARD_TEST_PORT' => (string) getenv('FLOWGUARD_TEST_PORT'),
            'FLOWGUARD_TEST_USERNAME' => (string) getenv('FLOWGUARD_TEST_USERNAME'),
            'FLOWGUARD_TEST_PASSWORD' => (string) getenv('FLOWGUARD_TEST_PASSWORD'),
            'FLOWGUARD_TEST_TABLE' => $this->table,
            'FLOWGUARD_TEST_GATE' => $gate,
            'FLOWGUARD_TEST_KEY' => $key,
        ]);

        $workers = [];
        try {
            for ($i = 0; $i < $count; $i++) {
                $pipes = [];
                $process = proc_open([PHP_BINARY, __DIR__.'/worker.php'], [
                    0 => ['pipe', 'r'],
                    1 => ['pipe', 'w'],
                    2 => ['pipe', 'w'],
                ], $pipes, dirname(__DIR__, 2), $env);
                if (! is_resource($process)) {
                    throw new RuntimeException('Cannot spawn race test worker.');
                }
                fclose($pipes[0]);
                $workers[] = [$process, $pipes];
            }
            // Every worker can now reach the database independently.
            touch($gate);
            $results = [];
            foreach ($workers as [$process, $pipes]) {
                $out = stream_get_contents($pipes[1]);
                $err = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                $exit = proc_close($process);
                if ($exit !== 0) {
                    throw new RuntimeException('Concurrency worker failed: '.$err);
                }
                $decoded = json_decode((string) $out, true, 512, JSON_THROW_ON_ERROR);
                if (! is_array($decoded)) {
                    throw new RuntimeException('Worker did not return a JSON operation snapshot.');
                }
                $results[] = $decoded;
            }
            $workers = [];

            return $results;
        } finally {
            @unlink($gate);
            // Close any workers left after an exception (without leaking processes).
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
