<?php

declare(strict_types=1);

namespace Mykholy\Flowguard\Tests\Concurrency;

use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Mykholy\Flowguard\Outbox\DatabaseOutboxRecorder;
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
}
