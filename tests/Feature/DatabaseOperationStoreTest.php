<?php

declare(strict_types=1);

namespace Mykholy\Flowguard\Tests\Feature;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;
use Mykholy\Flowguard\Domain\Claim;
use Mykholy\Flowguard\Domain\OperationStatus;
use Mykholy\Flowguard\Exceptions\IdempotencyConflict;
use Mykholy\Flowguard\Exceptions\InvalidTransition;
use Mykholy\Flowguard\Flowguard;

final class DatabaseOperationStoreTest extends TestCase
{
    public function test_claim_is_acquired_once_and_result_is_replayed_as_a_snapshot(): void
    {
        $guard = $this->app->make(Flowguard::class);
        $first = $guard->begin('payments.capture', 'merchant-order-42', ['amount' => 1200]);
        $duplicate = $guard->begin('payments.capture', 'merchant-order-42', ['amount' => 1200]);

        self::assertTrue($first->acquired());
        self::assertFalse($duplicate->acquired());
        self::assertSame($first->operation->id, $duplicate->operation->id);
        self::assertSame(OperationStatus::Processing, $duplicate->operation->status);
        self::assertSame(1, DB::table('flowguard_operations')->count());

        $finished = $guard->complete($first->claim(), ['provider_reference' => 'p-123']);
        self::assertSame(OperationStatus::Completed, $finished->status);
        self::assertSame(['provider_reference' => 'p-123'], $finished->result);

        $after = $guard->begin('payments.capture', 'merchant-order-42', ['amount' => 1200]);
        self::assertFalse($after->acquired());
        self::assertSame(OperationStatus::Completed, $after->operation->status);
        self::assertSame(['provider_reference' => 'p-123'], $after->operation->result);
    }

    public function test_duplicate_claim_inside_an_outer_transaction_does_not_poison_it(): void
    {
        $guard = $this->app->make(Flowguard::class);

        DB::transaction(function () use ($guard): void {
            $owner = $guard->begin('orders.place', 'outer-transaction-key', ['quantity' => 1]);
            $duplicate = $guard->begin('orders.place', 'outer-transaction-key', ['quantity' => 1]);

            self::assertTrue($owner->acquired());
            self::assertFalse($duplicate->acquired());
            $guard->complete($owner->claim(), ['status' => 'accepted']);
        });

        self::assertSame(1, DB::table('flowguard_operations')->count());
        self::assertSame('completed', DB::table('flowguard_operations')->value('status'));
    }

    public function test_same_key_with_different_payload_fails_closed(): void
    {
        $guard = $this->app->make(Flowguard::class);
        $guard->begin('orders.place', 'key-1', ['quantity' => 1]);

        $this->expectException(IdempotencyConflict::class);
        $guard->begin('orders.place', 'key-1', ['quantity' => 2]);
    }

    public function test_result_objects_cannot_trigger_serialization_or_be_persisted(): void
    {
        $guard = $this->app->make(Flowguard::class);
        $operation = $guard->begin('payments.capture', 'unsafe-completion', ['amount_minor' => 100]);

        $metadata = new class implements \JsonSerializable
        {
            public function jsonSerialize(): mixed
            {
                throw new \RuntimeException('Application serializer must never execute.');
            }
        };

        try {
            $guard->complete($operation->claim(), ['unsafe' => $metadata]);
            self::fail('An object must not be accepted as result metadata.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('JSON-native', $exception->getMessage());
        }

        self::assertSame('processing', DB::table('flowguard_operations')->value('status'));
        self::assertNull(DB::table('flowguard_operations')->value('result_json'));

        $completed = $guard->complete($operation->claim(), ['safe_reference' => 'capture-100']);
        self::assertSame(OperationStatus::Completed, $completed->status);
    }

    public function test_result_metadata_rejects_excessive_nesting_before_persistence(): void
    {
        $guard = $this->app->make(Flowguard::class);
        $operation = $guard->begin('orders.place', 'deep-completion', ['quantity' => 1]);

        $metadata = ['safe' => true];

        for ($level = 0; $level < 33; $level++) {
            $metadata = ['nested' => $metadata];
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('nesting');
        $guard->complete($operation->claim(), $metadata);
    }

    public function test_unowned_claim_cannot_complete(): void
    {
        $guard = $this->app->make(Flowguard::class);
        $acquisition = $guard->begin('orders.place', 'key-2', ['quantity' => 1]);
        $forgedClaim = new Claim($acquisition->operation->id, 'incorrect-secret');

        $this->expectException(InvalidTransition::class);
        $guard->complete($forgedClaim);
    }

    public function test_duplicate_callers_cannot_access_the_claim(): void
    {
        $guard = $this->app->make(Flowguard::class);
        $guard->begin('orders.place', 'key-3', ['quantity' => 1]);
        $duplicate = $guard->begin('orders.place', 'key-3', ['quantity' => 1]);

        $this->expectException(LogicException::class);
        $duplicate->claim();
    }

    public function test_crashed_work_is_not_automatically_reclaimed(): void
    {
        $guard = $this->app->make(Flowguard::class);
        $first = $guard->begin('payments.capture', 'possibly-already-charged', ['amount' => 99]);
        // Simulate a worker disappearing without calling complete/requireReconciliation.
        unset($first);

        $another = $guard->begin('payments.capture', 'possibly-already-charged', ['amount' => 99]);
        self::assertFalse($another->acquired());
        self::assertSame(OperationStatus::Processing, $another->operation->status);
    }

    public function test_unknown_outcome_is_terminal_until_explicit_reconciliation(): void
    {
        $guard = $this->app->make(Flowguard::class);
        $first = $guard->begin('payments.capture', 'key-4', ['amount' => 99]);
        $unknown = $guard->requireReconciliation($first->claim(), 'provider_timeout');

        self::assertSame(OperationStatus::RequiresReconciliation, $unknown->status);
        self::assertSame('provider_timeout', $unknown->reconciliationReason);
        self::assertFalse($guard->begin('payments.capture', 'key-4', ['amount' => 99])->acquired());

        $this->expectException(InvalidTransition::class);
        $guard->complete($first->claim());
    }

    public function test_scopes_are_isolated_even_with_same_key(): void
    {
        $guard = $this->app->make(Flowguard::class);
        $one = $guard->begin('payments.authorize', 'same-key', []);
        $two = $guard->begin('payments.capture', 'same-key', []);

        self::assertTrue($one->acquired());
        self::assertTrue($two->acquired());
        self::assertNotSame($one->operation->id, $two->operation->id);
    }

    public function test_completion_is_compare_and_swap(): void
    {
        $guard = $this->app->make(Flowguard::class);
        $first = $guard->begin('orders.place', 'key-5', []);
        $guard->complete($first->claim());

        $this->expectException(InvalidTransition::class);
        $guard->complete($first->claim());
    }
}
