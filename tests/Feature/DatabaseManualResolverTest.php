<?php

declare(strict_types=1);

namespace Mykholy\Flowguard\Tests\Feature;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Mykholy\Flowguard\Contracts\EvidenceAuthorization;
use Mykholy\Flowguard\Contracts\ResolutionAuthorization;
use Mykholy\Flowguard\Domain\OperationSnapshot;
use Mykholy\Flowguard\Domain\OperationStatus;
use Mykholy\Flowguard\Exceptions\InvalidTransition;
use Mykholy\Flowguard\Exceptions\ResolutionRejected;
use Mykholy\Flowguard\Flowguard;
use Mykholy\Flowguard\Infrastructure\DatabaseEvidenceJournal;
use Mykholy\Flowguard\Reconciliation\DatabaseManualResolver;
use Mykholy\Flowguard\Reconciliation\EvidenceEntry;
use Mykholy\Flowguard\Reconciliation\ReconciliationEvidence;
use Mykholy\Flowguard\Reconciliation\ReconciliationOutcome;
use RuntimeException;

final class DatabaseManualResolverTest extends TestCase
{
    private function unknownOperation(): string
    {
        $guard = $this->app->make(Flowguard::class);
        $entry = $guard->begin('payments.capture', 'manual-'.bin2hex(random_bytes(8)), ['amount_minor' => 500]);
        $guard->requireReconciliation($entry->claim(), 'provider_timeout');

        return $entry->operation->id;
    }

    private function evidence(string $operationId, ReconciliationOutcome $outcome, int $ageSeconds = 0): string
    {
        $policy = new class implements EvidenceAuthorization
        {
            public function assertCanRecord(string $actorReference, OperationSnapshot $operation): void
            {
                if ($actorReference !== 'reviewer.one' || $operation->scope !== 'payments.capture') {
                    throw new RuntimeException('Unauthorized evidence write.');
                }
            }
        };

        $observedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $observedAt = $observedAt->modify('-'.$ageSeconds.' seconds');

        return (new DatabaseEvidenceJournal(DB::connection(), $policy))
            ->append($operationId, new ReconciliationEvidence(
                $outcome, 'gateway.status', 'provider_observed', $observedAt,
            ), 'reviewer.one')->id;
    }

    private function resolver(bool $approved = true): DatabaseManualResolver
    {
        $policy = new class($approved) implements ResolutionAuthorization
        {
            public function __construct(private bool $approved) {}

            public function assertApproved(
                string $initiatorReference,
                string $approverReference,
                string $approvalReference,
                OperationSnapshot $operation,
                EvidenceEntry $evidence,
            ): void {
                if (! $this->approved
                    || $initiatorReference !== 'reviewer.one'
                    || $approverReference !== 'reviewer.two'
                    || $approvalReference !== 'approval.2026-42'
                    || $operation->scope !== 'payments.capture'
                    || $operation->id !== $evidence->operationId
                ) {
                    throw new RuntimeException('Not independently authorized.');
                }
            }
        };

        return new DatabaseManualResolver(DB::connection(), $policy);
    }

    private function resolve(string $operationId, string $evidenceId, bool $approved = true): OperationStatus
    {
        return $this->resolver($approved)->resolve(
            $operationId, $evidenceId, 'reviewer.one', 'reviewer.two', 'approval.2026-42',
        )->status;
    }

    public function test_confirmed_success_is_bookkept_without_running_provider_effects(): void
    {
        $operationId = $this->unknownOperation();
        $evidenceId = $this->evidence($operationId, ReconciliationOutcome::ConfirmedSuccess);

        self::assertSame(OperationStatus::ReconciledSuccess, $this->resolve($operationId, $evidenceId));
        self::assertSame('reconciled_success', DB::table('flowguard_operations')->where('id', $operationId)->value('status'));
        self::assertSame(1, DB::table('flowguard_resolution_decisions')->count());
        self::assertSame($evidenceId, DB::table('flowguard_resolution_decisions')->value('evidence_id'));
        self::assertSame('provider_timeout', DB::table('flowguard_operations')->value('reconciliation_reason'));
        self::assertNull(DB::table('flowguard_operations')->value('result_json'));
    }

    public function test_confirmed_failure_is_not_a_retry_authorization(): void
    {
        $operationId = $this->unknownOperation();
        $evidenceId = $this->evidence($operationId, ReconciliationOutcome::ConfirmedFailure);

        self::assertSame(OperationStatus::ReconciledFailure, $this->resolve($operationId, $evidenceId));
        self::assertSame('reconciled_failure', DB::table('flowguard_operations')->value('status'));
        self::assertSame(1, DB::table('flowguard_resolution_decisions')->count());
    }

    public function test_denied_approval_keeps_operation_unknown_and_audit_empty(): void
    {
        $operationId = $this->unknownOperation();
        $evidenceId = $this->evidence($operationId, ReconciliationOutcome::ConfirmedSuccess);

        try {
            $this->resolve($operationId, $evidenceId, false);
            self::fail('Expected application authorization to deny the resolution.');
        } catch (RuntimeException $exception) {
            self::assertSame('Not independently authorized.', $exception->getMessage());
        }

        self::assertSame('requires_reconciliation', DB::table('flowguard_operations')->value('status'));
        self::assertSame(0, DB::table('flowguard_resolution_decisions')->count());
    }

    public function test_same_actor_cannot_approve_own_decision(): void
    {
        $operationId = $this->unknownOperation();
        $evidenceId = $this->evidence($operationId, ReconciliationOutcome::ConfirmedSuccess);

        $this->expectException(ResolutionRejected::class);
        $this->resolver()->resolve($operationId, $evidenceId, 'reviewer.one', 'reviewer.one', 'approval.2026-42');
    }

    public function test_stale_evidence_is_rejected(): void
    {
        $operationId = $this->unknownOperation();
        $evidenceId = $this->evidence($operationId, ReconciliationOutcome::ConfirmedSuccess, 3600);

        $this->expectException(ResolutionRejected::class);
        $this->resolve($operationId, $evidenceId);
    }

    public function test_conflicting_confirmed_outcomes_block_finalization(): void
    {
        $operationId = $this->unknownOperation();
        $this->evidence($operationId, ReconciliationOutcome::ConfirmedSuccess);
        $latest = $this->evidence($operationId, ReconciliationOutcome::ConfirmedFailure);

        $this->expectException(ResolutionRejected::class);
        $this->resolve($operationId, $latest);
    }

    public function test_inconclusive_and_superseded_evidence_are_rejected(): void
    {
        $operationId = $this->unknownOperation();
        $earlier = $this->evidence($operationId, ReconciliationOutcome::ConfirmedSuccess);
        $latest = $this->evidence($operationId, ReconciliationOutcome::Inconclusive);

        try {
            $this->resolve($operationId, $earlier);
            self::fail('Superseded evidence must not authorize resolution.');
        } catch (ResolutionRejected) {
            // Rejected by latest-evidence check.
        }

        $this->expectException(ResolutionRejected::class);
        $this->resolve($operationId, $latest);
    }

    public function test_duplicate_finalization_cannot_create_a_second_audit_record(): void
    {
        $operationId = $this->unknownOperation();
        $evidenceId = $this->evidence($operationId, ReconciliationOutcome::ConfirmedSuccess);
        $this->resolve($operationId, $evidenceId);

        try {
            $this->resolve($operationId, $evidenceId);
            self::fail('Expected the second attempt to be rejected.');
        } catch (InvalidTransition) {
            // Compare-and-swap transition is one-time.
        }

        self::assertSame(1, DB::table('flowguard_resolution_decisions')->count());
    }

    public function test_audit_persistence_failure_rolls_back_the_state_change(): void
    {
        $operationId = $this->unknownOperation();
        $evidenceId = $this->evidence($operationId, ReconciliationOutcome::ConfirmedSuccess);
        DB::getSchemaBuilder()->drop('flowguard_resolution_decisions');

        try {
            $this->resolve($operationId, $evidenceId);
            self::fail('Expected a missing audit table to abort the transaction.');
        } catch (\Illuminate\Database\QueryException) {
            // Transaction must roll back the operation status update.
        }

        self::assertSame('requires_reconciliation', DB::table('flowguard_operations')->value('status'));
    }
}
