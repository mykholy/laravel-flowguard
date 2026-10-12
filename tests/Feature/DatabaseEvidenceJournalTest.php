<?php

declare(strict_types=1);

namespace Mykholy\Flowguard\Tests\Feature;

use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Mykholy\Flowguard\Contracts\EvidenceAuthorization;
use Mykholy\Flowguard\Domain\OperationSnapshot;
use Mykholy\Flowguard\Domain\OperationStatus;
use Mykholy\Flowguard\Exceptions\InvalidTransition;
use Mykholy\Flowguard\Flowguard;
use Mykholy\Flowguard\Infrastructure\DatabaseEvidenceJournal;
use Mykholy\Flowguard\Reconciliation\ReconciliationEvidence;
use Mykholy\Flowguard\Reconciliation\ReconciliationOutcome;
use RuntimeException;

final class DatabaseEvidenceJournalTest extends TestCase
{
    private function journal(bool $allow = true): DatabaseEvidenceJournal
    {
        $policy = new class($allow) implements EvidenceAuthorization
        {
            public function __construct(private bool $allow) {}

            public function assertCanRecord(string $actorReference, OperationSnapshot $operation): void
            {
                if (! $this->allow || $actorReference !== 'reviewer.42' || $operation->scope !== 'payments.capture') {
                    throw new RuntimeException('Access denied.');
                }
            }
        };

        return new DatabaseEvidenceJournal(DB::connection(), $policy);
    }

    private function evidence(): ReconciliationEvidence
    {
        return new ReconciliationEvidence(
            ReconciliationOutcome::ConfirmedSuccess,
            'gateway.status',
            'capture_found',
            new DateTimeImmutable('2026-10-12T05:00:00+02:00'),
            'secret-customer-provider-reference',
        );
    }

    private function unknownOperation(): string
    {
        $guard = $this->app->make(Flowguard::class);
        $claim = $guard->begin('payments.capture', 'evidence-test-'.bin2hex(random_bytes(8)), ['amount' => 100]);
        $guard->requireReconciliation($claim->claim(), 'provider_timeout');

        return $claim->operation->id;
    }

    public function test_an_authorized_observation_is_inserted_without_resolving_the_operation(): void
    {
        $operationId = $this->unknownOperation();
        $entry = $this->journal()->append($operationId, $this->evidence(), 'reviewer.42');

        self::assertSame($operationId, $entry->operationId);
        self::assertSame(ReconciliationOutcome::ConfirmedSuccess, $entry->outcome);
        self::assertSame('2026-10-12 03:00:00', $entry->observedAt->format('Y-m-d H:i:s'));

        $row = DB::table('flowguard_reconciliation_evidence')->sole();
        self::assertSame($entry->id, $row->id);
        self::assertSame('capture_found', $row->evidence_code);
        self::assertSame(1, (int) $row->provider_reference_present);
        self::assertSame('requires_reconciliation', DB::table('flowguard_operations')->where('id', $operationId)->value('status'));
        self::assertStringNotContainsString('secret-customer-provider-reference', json_encode((array) $row, JSON_THROW_ON_ERROR));
    }

    public function test_policy_denial_never_persists_an_observation(): void
    {
        $operationId = $this->unknownOperation();

        try {
            $this->journal(false)->append($operationId, $this->evidence(), 'reviewer.42');
            self::fail('Expected policy to reject the write.');
        } catch (RuntimeException $exception) {
            self::assertSame('Access denied.', $exception->getMessage());
        }

        self::assertSame(0, DB::table('flowguard_reconciliation_evidence')->count());
    }

    public function test_processing_and_completed_operations_cannot_receive_evidence(): void
    {
        $guard = $this->app->make(Flowguard::class);
        $processing = $guard->begin('payments.capture', 'not-unknown', []);
        $completed = $guard->begin('payments.capture', 'already-complete', []);
        $guard->complete($completed->claim());

        foreach ([$processing->operation->id, $completed->operation->id, '00000000-0000-0000-0000-000000000000'] as $id) {
            try {
                $this->journal()->append($id, $this->evidence(), 'reviewer.42');
                self::fail('Expected InvalidTransition.');
            } catch (InvalidTransition) {
                // All three branches must fail closed.
            }
        }

        self::assertSame(0, DB::table('flowguard_reconciliation_evidence')->count());
    }

    public function test_actor_reference_is_bounded_before_database_write(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->journal()->append($this->unknownOperation(), $this->evidence(), "admin\nfake");
    }

    public function test_multiple_observations_append_separate_entries(): void
    {
        $operationId = $this->unknownOperation();
        $journal = $this->journal();
        $first = $journal->append($operationId, $this->evidence(), 'reviewer.42');
        $second = $journal->append($operationId, $this->evidence(), 'reviewer.42');

        self::assertNotSame($first->id, $second->id);
        self::assertSame(2, DB::table('flowguard_reconciliation_evidence')->where('operation_id', $operationId)->count());
        self::assertSame(OperationStatus::RequiresReconciliation->value, DB::table('flowguard_operations')->where('id', $operationId)->value('status'));
    }

    public function test_policy_receives_stored_scope_and_reason(): void
    {
        $operationId = $this->unknownOperation();
        $policy = new class implements EvidenceAuthorization
        {
            public function assertCanRecord(string $actorReference, OperationSnapshot $operation): void
            {
                if ($operation->id === '' || $operation->scope !== 'payments.capture' || $operation->reconciliationReason !== 'provider_timeout') {
                    throw new RuntimeException('Untrusted operation state.');
                }
            }
        };

        $journal = new DatabaseEvidenceJournal(DB::connection(), $policy);
        self::assertSame($operationId, $journal->append($operationId, $this->evidence(), 'reviewer.42')->operationId);
    }
}
