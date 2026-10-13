<?php

declare(strict_types=1);

namespace Mykholy\Flowguard\Tests\Concurrency;

use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Mykholy\Flowguard\Outbox\DatabaseOutboxLeaseStore;
use Mykholy\Flowguard\Outbox\DatabaseOutboxRecorder;
use Mykholy\Flowguard\Outbox\LeaseLost;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once __DIR__.'/bootstrap.php';

final class OutboxDatabaseTest extends TestCase
{
    private Connection $db;

    private string $outbox;

    private string $orders;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = \flowguardConcurrencyConnection();
        $suffix = bin2hex(random_bytes(6));
        $this->outbox = 'fg_outbox_'.$suffix;
        $this->orders = 'fg_orders_'.$suffix;

        $this->db->getSchemaBuilder()->create($this->orders, function (Blueprint $table): void {
            $table->id();
            $table->string('status', 30);
        });

        $this->db->getSchemaBuilder()->create($this->outbox, function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('scope', 120);
            $table->char('event_key_hash', 64);
            $table->string('topic', 120);
            $table->string('subject_ref', 128);
            $table->string('status', 20);
            $table->unsignedInteger('delivery_attempts')->default(0);
            $table->char('lease_hash', 64)->nullable();
            $table->dateTime('lease_expires_at', 6)->nullable();
            $table->dateTime('available_at', 6)->nullable();
            $table->dateTime('delivered_at', 6)->nullable();
            $table->string('last_failure_code', 120)->nullable();
            $table->timestamps(6);
            $table->unique(['scope', 'event_key_hash']);
        });
    }

    protected function tearDown(): void
    {
        try {
            $this->db->getSchemaBuilder()->dropIfExists($this->outbox);
            $this->db->getSchemaBuilder()->dropIfExists($this->orders);
        } finally {
            parent::tearDown();
        }
    }

    public function test_business_change_and_event_commit_on_the_same_connection(): void
    {
        $receipt = $this->db->transaction(function () {
            $this->db->table($this->orders)->insert(['status' => 'paid']);

            return (new DatabaseOutboxRecorder($this->db, $this->outbox))
                ->record('tenant.42:orders', 'paid-event-1', 'order.paid', 'order-1');
        });

        self::assertSame(1, $this->db->table($this->orders)->count());
        self::assertSame(1, $this->db->table($this->outbox)->count());
        self::assertSame($receipt->id, $this->db->table($this->outbox)->value('id'));
        self::assertSame('pending', $this->db->table($this->outbox)->value('status'));
    }

    public function test_failed_business_transaction_discards_the_outbox_event(): void
    {
        try {
            $this->db->transaction(function (): void {
                $this->db->table($this->orders)->insert(['status' => 'failed']);
                (new DatabaseOutboxRecorder($this->db, $this->outbox))
                    ->record('tenant.42:orders', 'event-rolled-back', 'order.failed', 'order-2');

                throw new RuntimeException('Abort transaction.');
            });
            self::fail('Transaction should have failed.');
        } catch (RuntimeException $exception) {
            self::assertSame('Abort transaction.', $exception->getMessage());
        }

        self::assertSame(0, $this->db->table($this->orders)->count());
        self::assertSame(0, $this->db->table($this->outbox)->count());
    }

    public function test_duplicate_event_identity_fails_at_database_unique_constraint(): void
    {
        $recorder = new DatabaseOutboxRecorder($this->db, $this->outbox);

        $this->db->transaction(function () use ($recorder): void {
            $recorder->record('tenant.42:orders', 'duplicate-1', 'order.paid', 'order-1');
        });

        try {
            $this->db->transaction(function () use ($recorder): void {
                $recorder->record('tenant.42:orders', 'duplicate-1', 'order.paid', 'order-1');
            });
            self::fail('Expected a unique constraint failure.');
        } catch (QueryException) {
            // No second outbox record was committed.
        }

        self::assertSame(1, $this->db->table($this->outbox)->count());
    }

    public function test_expired_leases_rotate_ownership_on_this_database(): void
    {
        $recorder = new DatabaseOutboxRecorder($this->db, $this->outbox);
        $this->db->transaction(function () use ($recorder): void {
            $recorder->record('tenant.42:orders', 'lease-reclaim', 'order.paid', 'order-1');
        });

        $store = new DatabaseOutboxLeaseStore($this->db, $this->outbox);
        $first = $store->reserve(5);
        self::assertNotNull($first);

        $this->db->table($this->outbox)->update(['lease_expires_at' => '2020-01-01 00:00:00.000000']);

        $second = $store->reserve(60);
        self::assertNotNull($second);
        self::assertSame(2, $second->attempt);

        try {
            $store->acknowledge($first);
            self::fail('A displaced owner must not acknowledge delivery.');
        } catch (LeaseLost) {
            // The second reservation remains valid.
        }

        $store->acknowledge($second);
        self::assertSame('delivered', $this->db->table($this->outbox)->value('status'));
    }

    public function test_parallel_workers_reserve_a_message_only_once(): void
    {
        if (! function_exists('proc_open')) {
            self::markTestSkipped('proc_open is required for the reservation race.');
        }

        $recorder = new DatabaseOutboxRecorder($this->db, $this->outbox);
        $this->db->transaction(function () use ($recorder): void {
            $recorder->record('tenant.42:orders', 'parallel-delivery', 'order.paid', 'order-1');
        });

        $gate = sys_get_temp_dir().'/flowguard-outbox-'.bin2hex(random_bytes(12));
        $env = array_merge($_ENV, [
            'FLOWGUARD_CONCURRENCY_TEST' => '1',
            'FLOWGUARD_TEST_DATABASE' => 'flowguard_ci',
            'FLOWGUARD_TEST_DRIVER' => (string) getenv('FLOWGUARD_TEST_DRIVER'),
            'FLOWGUARD_TEST_HOST' => (string) getenv('FLOWGUARD_TEST_HOST'),
            'FLOWGUARD_TEST_PORT' => (string) getenv('FLOWGUARD_TEST_PORT'),
            'FLOWGUARD_TEST_USERNAME' => (string) getenv('FLOWGUARD_TEST_USERNAME'),
            'FLOWGUARD_TEST_PASSWORD' => (string) getenv('FLOWGUARD_TEST_PASSWORD'),
            'FG_OUTBOX_RACE_TABLE' => $this->outbox,
            'FG_OUTBOX_RACE_GATE' => $gate,
        ]);

        $workers = [];

        try {
            for ($i = 0; $i < 6; $i++) {
                $pipes = [];
                $process = proc_open([PHP_BINARY, __DIR__.'/outbox-worker.php'], [
                    0 => ['pipe', 'r'],
                    1 => ['pipe', 'w'],
                    2 => ['pipe', 'w'],
                ], $pipes, dirname(__DIR__, 2), $env);

                if (! is_resource($process)) {
                    throw new RuntimeException('Could not start outbox race worker.');
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
                    throw new RuntimeException('Outbox race worker failed: '.$error);
                }

                $results[] = json_decode((string) $output, true, 512, JSON_THROW_ON_ERROR);
            }

            $workers = [];
            self::assertCount(6, $results);
            self::assertSame(1, count(array_filter($results, static fn (array $r): bool => $r['reserved'] === true)));
            self::assertSame(1, (int) $this->db->table($this->outbox)->value('delivery_attempts'));
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

    public function test_reservation_skips_the_first_row_locked_by_another_connection(): void
    {
        $recorder = new DatabaseOutboxRecorder($this->db, $this->outbox);

        $this->db->transaction(function () use ($recorder): void {
            $recorder->record('tenant.42:orders', 'held-by-transaction', 'order.paid', 'order-1');
            $recorder->record('tenant.42:orders', 'eligible-during-lock', 'order.paid', 'order-2');
        });

        $firstId = (string) $this->db->table($this->outbox)
            ->orderBy('created_at')
            ->orderBy('id')
            ->value('id');

        $this->db->beginTransaction();

        try {
            $held = $this->db->table($this->outbox)
                ->where('id', $firstId)
                ->lockForUpdate()
                ->first();

            self::assertNotNull($held);

            // A genuinely separate database connection must not wait for the
            // first message when another pending record is available.
            $other = \flowguardConcurrencyConnection();
            $lease = (new DatabaseOutboxLeaseStore($other, $this->outbox))->reserve(60);

            self::assertNotNull($lease);
            self::assertNotSame($firstId, $lease->id);
            self::assertSame(1, $lease->attempt);

            (new DatabaseOutboxLeaseStore($other, $this->outbox))->acknowledge($lease);
        } finally {
            $this->db->rollBack();
        }

        self::assertSame('pending', $this->db->table($this->outbox)->where('id', $firstId)->value('status'));
        self::assertSame(1, $this->db->table($this->outbox)->where('status', 'delivered')->count());
    }

    public function test_killed_worker_leaves_recoverable_fenced_lease(): void
    {
        if (! function_exists('proc_open')) {
            self::markTestSkipped('proc_open is required for crash injection.');
        }

        $this->db->transaction(function (): void {
            (new DatabaseOutboxRecorder($this->db, $this->outbox))
                ->record('tenant.42:orders', 'crash-after-reservation', 'order.paid', 'order-42');
        });

        $ready = sys_get_temp_dir().'/flowguard-crash-'.bin2hex(random_bytes(12));
        $env = array_merge($_ENV, [
            'FLOWGUARD_CONCURRENCY_TEST' => '1',
            'FLOWGUARD_TEST_DATABASE' => 'flowguard_ci',
            'FLOWGUARD_TEST_DRIVER' => (string) getenv('FLOWGUARD_TEST_DRIVER'),
            'FLOWGUARD_TEST_HOST' => (string) getenv('FLOWGUARD_TEST_HOST'),
            'FLOWGUARD_TEST_PORT' => (string) getenv('FLOWGUARD_TEST_PORT'),
            'FLOWGUARD_TEST_USERNAME' => (string) getenv('FLOWGUARD_TEST_USERNAME'),
            'FLOWGUARD_TEST_PASSWORD' => (string) getenv('FLOWGUARD_TEST_PASSWORD'),
            'FG_CRASH_OUTBOX_TABLE' => $this->outbox,
            'FG_CRASH_READY_PATH' => $ready,
        ]);

        $pipes = [];
        $process = proc_open([PHP_BINARY, __DIR__.'/outbox-crash-worker.php'], [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, dirname(__DIR__, 2), $env);

        if (! is_resource($process)) {
            throw new RuntimeException('Unable to launch fault-injection worker.');
        }

        fclose($pipes[0]);

        try {
            $deadline = microtime(true) + 15;
            while (! file_exists($ready) && microtime(true) < $deadline) {
                usleep(10000);
            }

            if (! file_exists($ready)) {
                throw new RuntimeException('Worker failed to acquire the lease before timeout.');
            }

            proc_terminate($process);
            self::assertSame('leased', $this->db->table($this->outbox)->value('status'));
            self::assertSame(1, (int) $this->db->table($this->outbox)->value('delivery_attempts'));

            // Simulate passage of the TTL without slowing the test.
            $this->db->table($this->outbox)->update([
                'lease_expires_at' => '2020-01-01 00:00:00.000000',
            ]);

            $lease = (new DatabaseOutboxLeaseStore($this->db, $this->outbox))->reserve(60);
            self::assertNotNull($lease);
            self::assertSame(2, $lease->attempt);

            (new DatabaseOutboxLeaseStore($this->db, $this->outbox))->acknowledge($lease);
            self::assertSame('delivered', $this->db->table($this->outbox)->value('status'));
        } finally {
            @unlink($ready);

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
