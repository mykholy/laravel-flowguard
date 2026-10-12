<?php

declare(strict_types=1);

namespace Mykholy\Flowguard\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Mykholy\Flowguard\Outbox\DatabaseOutboxLeaseStore;
use Mykholy\Flowguard\Outbox\DatabaseOutboxRecorder;
use Mykholy\Flowguard\Outbox\LeaseLost;
use Mykholy\Flowguard\Outbox\OutboxLease;

final class DatabaseOutboxLeaseStoreTest extends TestCase
{
    private function store(int $maxAttempts = 10): DatabaseOutboxLeaseStore
    {
        return new DatabaseOutboxLeaseStore(DB::connection(), maxAttempts: $maxAttempts);
    }

    private function record(): string
    {
        return DB::transaction(function (): string {
            return (new DatabaseOutboxRecorder(DB::connection()))
                ->record('tenant.42:orders', 'order-42:paid', 'order.paid', 'order-42')->id;
        });
    }

    public function test_acknowledgement_finishes_the_delivery_once(): void
    {
        $id = $this->record();
        $store = $this->store();
        $lease = $store->reserve();

        self::assertNotNull($lease);
        self::assertSame($id, $lease->id);
        self::assertSame(1, $lease->attempt);
        self::assertSame('leased', DB::table('flowguard_outbox')->value('status'));

        $store->acknowledge($lease);

        self::assertSame('delivered', DB::table('flowguard_outbox')->value('status'));
        self::assertNotNull(DB::table('flowguard_outbox')->value('delivered_at'));
        self::assertNull($store->reserve());
    }

    public function test_unknown_token_cannot_acknowledge_the_delivery(): void
    {
        $this->record();
        $store = $this->store();
        $lease = $store->reserve();
        self::assertNotNull($lease);

        $fake = new OutboxLease($lease->id, $lease->scope, $lease->topic, $lease->subjectReference, 1, 'wrong-token');

        try {
            $store->acknowledge($fake);
            self::fail('Unknown token must not acknowledge delivery.');
        } catch (LeaseLost) {
            // The real lease is still active.
        }

        self::assertSame('leased', DB::table('flowguard_outbox')->value('status'));
        $store->acknowledge($lease);
    }

    public function test_expired_lease_can_be_reclaimed_but_old_owner_is_fenced_out(): void
    {
        $this->record();
        $store = $this->store();
        $original = $store->reserve(5);
        self::assertNotNull($original);

        DB::table('flowguard_outbox')->update(['lease_expires_at' => '2020-01-01 00:00:00.000000']);

        $replacement = $store->reserve(60);
        self::assertNotNull($replacement);
        self::assertSame($original->id, $replacement->id);
        self::assertSame(2, $replacement->attempt);
        self::assertNotSame($original->tokenHash(), $replacement->tokenHash());

        $this->expectException(LeaseLost::class);
        try {
            $store->acknowledge($original);
        } finally {
            self::assertSame('leased', DB::table('flowguard_outbox')->value('status'));
            $store->acknowledge($replacement);
        }
    }

    public function test_failed_delivery_is_delayed_and_then_available_for_another_attempt(): void
    {
        $this->record();
        $store = $this->store();
        $lease = $store->reserve();
        self::assertNotNull($lease);

        $store->release($lease, 'network_timeout', 3600);

        self::assertSame('pending', DB::table('flowguard_outbox')->value('status'));
        self::assertSame('network_timeout', DB::table('flowguard_outbox')->value('last_failure_code'));
        self::assertNull($store->reserve());

        DB::table('flowguard_outbox')->update(['available_at' => '2020-01-01 00:00:00.000000']);

        $next = $store->reserve();
        self::assertNotNull($next);
        self::assertSame(2, $next->attempt);
        $store->acknowledge($next);
    }

    public function test_attempt_limit_leaves_the_message_for_manual_inspection(): void
    {
        $this->record();
        $store = $this->store(maxAttempts: 1);
        $lease = $store->reserve();
        self::assertNotNull($lease);

        DB::table('flowguard_outbox')->update(['lease_expires_at' => '2020-01-01 00:00:00.000000']);

        self::assertNull($store->reserve());
        self::assertSame('leased', DB::table('flowguard_outbox')->value('status'));
        self::assertSame(1, (int) DB::table('flowguard_outbox')->value('delivery_attempts'));
    }
}
