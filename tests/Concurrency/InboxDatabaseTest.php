<?php

declare(strict_types=1);

namespace Mykholy\Flowguard\Tests\Concurrency;

use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Mykholy\Flowguard\Inbox\DatabaseInboxRecorder;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once __DIR__.'/bootstrap.php';

final class InboxDatabaseTest extends TestCase
{
    private Connection $db;

    private string $inbox;

    private string $outbox;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = \flowguardConcurrencyConnection();
        $suffix = bin2hex(random_bytes(6));
        $this->inbox = 'fg_inbox_'.$suffix;
        $this->outbox = 'fg_outbox_'.$suffix;

        $this->db->getSchemaBuilder()->create($this->outbox, function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('scope', 120);
            $table->char('event_key_hash', 64);
            $table->string('topic', 120);
            $table->string('subject_ref', 128);
            $table->string('status', 20);
            $table->timestamps(6);
            $table->unique(['scope', 'event_key_hash']);
        });

        $outbox = $this->outbox;
        $this->db->getSchemaBuilder()->create($this->inbox, function (Blueprint $table) use ($outbox): void {
            $table->uuid('id')->primary();
            $table->string('scope', 120);
            $table->char('event_key_hash', 64);
            $table->char('payload_hash', 64);
            $table->uuid('outbox_id');
            $table->timestamps(6);
            $table->unique(['scope', 'event_key_hash']);
            $table->foreign('outbox_id')->references('id')->on($outbox)->restrictOnDelete();
        });
    }

    protected function tearDown(): void
    {
        try {
            $this->db->getSchemaBuilder()->dropIfExists($this->inbox);
            $this->db->getSchemaBuilder()->dropIfExists($this->outbox);
        } finally {
            parent::tearDown();
        }
    }

    public function test_duplicate_delivery_has_one_receipt_and_one_signal(): void
    {
        $recorder = new DatabaseInboxRecorder($this->db, $this->inbox, $this->outbox);
        $first = $recorder->receive(
            'tenant.42:webhooks', 'gateway-101', ['a' => 1, 'b' => 2],
            'payment.received', 'payment-101',
        );
        $second = $recorder->receive(
            'tenant.42:webhooks', 'gateway-101', ['b' => 2, 'a' => 1],
            'payment.received', 'payment-101',
        );

        self::assertFalse($first->duplicate);
        self::assertTrue($second->duplicate);
        self::assertSame($first->id, $second->id);
        self::assertSame(1, $this->db->table($this->inbox)->count());
        self::assertSame(1, $this->db->table($this->outbox)->count());
    }

    public function test_six_independent_receivers_store_only_one_event(): void
    {
        if (! function_exists('proc_open')) {
            self::markTestSkipped('proc_open is required for independent-process testing.');
        }

        $gate = sys_get_temp_dir().'/flowguard-inbox-gate-'.bin2hex(random_bytes(12));
        $env = array_merge($_ENV, [
            'FLOWGUARD_CONCURRENCY_TEST' => '1',
            'FLOWGUARD_TEST_DATABASE' => 'flowguard_ci',
            'FLOWGUARD_TEST_DRIVER' => (string) getenv('FLOWGUARD_TEST_DRIVER'),
            'FLOWGUARD_TEST_HOST' => (string) getenv('FLOWGUARD_TEST_HOST'),
            'FLOWGUARD_TEST_PORT' => (string) getenv('FLOWGUARD_TEST_PORT'),
            'FLOWGUARD_TEST_USERNAME' => (string) getenv('FLOWGUARD_TEST_USERNAME'),
            'FLOWGUARD_TEST_PASSWORD' => (string) getenv('FLOWGUARD_TEST_PASSWORD'),
            'FG_INBOX_TABLE' => $this->inbox,
            'FG_OUTBOX_TABLE' => $this->outbox,
            'FG_INBOX_GATE' => $gate,
        ]);

        $workers = [];

        try {
            for ($i = 0; $i < 6; $i++) {
                $pipes = [];
                $process = proc_open([PHP_BINARY, __DIR__.'/inbox-worker.php'], [
                    0 => ['pipe', 'r'],
                    1 => ['pipe', 'w'],
                    2 => ['pipe', 'w'],
                ], $pipes, dirname(__DIR__, 2), $env);

                if (! is_resource($process)) {
                    throw new RuntimeException('Unable to start inbox worker.');
                }

                fclose($pipes[0]);
                $workers[] = [$process, $pipes];
            }

            touch($gate);
            $results = [];

            foreach ($workers as [$process, $pipes]) {
                $output = stream_get_contents($pipes[1]);
                $error = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                $exit = proc_close($process);

                if ($exit !== 0) {
                    throw new RuntimeException('Inbox worker failed: '.$error);
                }

                $results[] = json_decode((string) $output, true, 512, JSON_THROW_ON_ERROR);
            }

            $workers = [];

            self::assertCount(6, $results);
            self::assertSame(1, count(array_filter($results, static fn (array $r): bool => $r['duplicate'] === false)));
            self::assertSame(1, $this->db->table($this->inbox)->count());
            self::assertSame(1, $this->db->table($this->outbox)->count());
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
