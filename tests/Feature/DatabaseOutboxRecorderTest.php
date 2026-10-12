<?php

declare(strict_types=1);

namespace Mykholy\Flowguard\Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use InvalidArgumentException;
use LogicException;
use Mykholy\Flowguard\Outbox\DatabaseOutboxRecorder;
use RuntimeException;

final class DatabaseOutboxRecorderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('flowguard_test_orders', function (Blueprint $table): void {
            $table->id();
            $table->string('number', 36)->unique();
        });
    }

    private function recorder(): DatabaseOutboxRecorder
    {
        return new DatabaseOutboxRecorder(DB::connection());
    }

    public function test_business_write_and_outbox_message_commit_together(): void
    {
        $message = DB::transaction(function () {
            DB::table('flowguard_test_orders')->insert(['number' => 'order-100']);
            
            return $this->recorder()->record('tenant.42:orders', 'order-100:created', 'order.created', 'order-100');
        });

        self::assertSame(1, DB::table('flowguard_test_orders')->count());
        self::assertSame(1, DB::table('flowguard_outbox')->count());
        self::assertSame($message->id, DB::table('flowguard_outbox')->value('id'));
        self::assertSame('pending', DB::table('flowguard_outbox')->value('status'));
    }

    public function test_rollback_discards_the_message_and_the_business_write(): void
    {
        try {
            DB::transaction(function (): void {
                DB::table('flowguard_test_orders')->insert(['number' => 'order-101']);
                $this->recorder()->record('tenant.42:orders', 'order-101:created', 'order.created', 'order-101');

                throw new RuntimeException('Simulated business transaction failure.');
            });
            self::fail('Expected transaction to fail.');
        } catch (RuntimeException $exception) {
            self::assertSame('Simulated business transaction failure.', $exception->getMessage());
        }

        self::assertSame(0, DB::table('flowguard_test_orders')->count());
        self::assertSame(0, DB::table('flowguard_outbox')->count());
    }

    public function test_implicit_autocommit_is_rejected(): void
    {
        $this->expectException(LogicException::class);
        $this->recorder()->record('tenant.42:orders', 'event-1', 'order.created', 'order-100');
    }

    public function test_database_uniqueness_rejects_the_same_event_twice(): void
    {
        DB::transaction(function (): void {
            $recorder = $this->recorder();
            $recorder->record('tenant.42:orders', 'same-event', 'order.created', 'order-100');

            $this->expectException(QueryException::class);
            $recorder->record('tenant.42:orders', 'same-event', 'order.created', 'order-100');
        });
    }

    public function test_event_identity_is_scoped_per_tenant(): void
    {
        DB::transaction(function (): void {
            $recorder = $this->recorder();
            $recorder->record('tenant.1:orders', 'event-1', 'order.created', 'order-1');
            $recorder->record('tenant.2:orders', 'event-1', 'order.created', 'order-1');
        });

        self::assertSame(2, DB::table('flowguard_outbox')->count());
    }

    public function test_opaque_event_id_is_not_saved_in_plaintext(): void
    {
        DB::transaction(function (): void {
            $this->recorder()->record('tenant.42:orders', 'private-id-100', 'order.created', 'order-100');
        });

        $row = DB::table('flowguard_outbox')->sole();
        self::assertSame(hash('sha256', 'private-id-100'), $row->event_key_hash);
        self::assertFalse(property_exists($row, 'event_id'));
    }

    public function test_invalid_scope_is_rejected_before_write(): void
    {
        $this->expectException(InvalidArgumentException::class);

        DB::transaction(function (): void {
            $this->recorder()->record('tenant 42', 'event-1', 'order.created', 'order-100');
        });
    }
}
