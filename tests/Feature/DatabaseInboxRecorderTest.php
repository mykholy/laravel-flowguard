<?php

declare(strict_types=1);

namespace Mykholy\Flowguard\Tests\Feature;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Mykholy\Flowguard\Exceptions\IdempotencyConflict;
use Mykholy\Flowguard\Inbox\DatabaseInboxRecorder;
use Mykholy\Flowguard\Inbox\InboxReceipt;
use RuntimeException;

final class DatabaseInboxRecorderTest extends TestCase
{
    private function receive(array $payload = ['event' => 'paid'], string $scope = 'tenant.42:webhooks'): InboxReceipt
    {
        return (new DatabaseInboxRecorder(DB::connection()))
            ->receive($scope, 'gateway-event-1', $payload, 'payment.received', 'payment-1');
    }

    public function test_new_event_commits_a_receipt_and_one_outbox_signal(): void
    {
        $receipt = $this->receive();

        self::assertFalse($receipt->duplicate);
        self::assertSame(1, DB::table('flowguard_inbox')->count());
        self::assertSame(1, DB::table('flowguard_outbox')->count());
        self::assertSame($receipt->id, DB::table('flowguard_inbox')->value('id'));
        self::assertSame('pending', DB::table('flowguard_outbox')->value('status'));
        self::assertFalse(property_exists(DB::table('flowguard_inbox')->sole(), 'payload'));
    }

    public function test_duplicate_event_does_not_enqueue_another_message(): void
    {
        $first = $this->receive(['value' => 5, 'currency' => 'EGP']);
        $again = $this->receive(['currency' => 'EGP', 'value' => 5]);

        self::assertTrue($again->duplicate);
        self::assertSame($first->id, $again->id);
        self::assertSame(1, DB::table('flowguard_inbox')->count());
        self::assertSame(1, DB::table('flowguard_outbox')->count());
    }

    public function test_same_event_id_with_different_payload_is_rejected(): void
    {
        $this->receive(['amount_minor' => 100]);

        $this->expectException(IdempotencyConflict::class);
        $this->receive(['amount_minor' => 200]);
    }

    public function test_same_event_id_cannot_be_rerouted_to_another_topic(): void
    {
        $this->receive(['paid' => true]);

        $this->expectException(IdempotencyConflict::class);
        (new DatabaseInboxRecorder(DB::connection()))->receive(
            'tenant.42:webhooks', 'gateway-event-1', ['paid' => true],
            'payment.refunded', 'payment-1',
        );
    }

    public function test_different_tenants_can_receive_same_provider_event_id(): void
    {
        $first = $this->receive(['paid' => true], 'tenant.1:webhooks');
        $second = $this->receive(['paid' => true], 'tenant.2:webhooks');

        self::assertNotSame($first->id, $second->id);
        self::assertSame(2, DB::table('flowguard_inbox')->count());
        self::assertSame(2, DB::table('flowguard_outbox')->count());
    }

    public function test_outer_transaction_rollback_discards_both_receipt_and_signal(): void
    {
        try {
            DB::transaction(function (): void {
                $this->receive();

                throw new RuntimeException('Application transaction rolled back.');
            });
            self::fail('Expected rollback.');
        } catch (RuntimeException $exception) {
            self::assertSame('Application transaction rolled back.', $exception->getMessage());
        }

        self::assertSame(0, DB::table('flowguard_inbox')->count());
        self::assertSame(0, DB::table('flowguard_outbox')->count());
    }

    public function test_invalid_scope_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->receive([], 'tenant 42');
    }
}
