<?php

declare(strict_types=1);

namespace Mykholy\Flowguard\Reconciliation;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Connection;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Mykholy\Flowguard\Contracts\ResolutionAuthorization;
use Mykholy\Flowguard\Domain\OperationSnapshot;
use Mykholy\Flowguard\Domain\OperationStatus;
use Mykholy\Flowguard\Exceptions\InvalidTransition;
use Mykholy\Flowguard\Exceptions\ResolutionRejected;
use stdClass;

/**
 * Explicit, authorization-gated bookkeeping finalization.
 *
 * This service makes NO provider calls and NEVER executes or retries an external
 * business effect. All checks and the audit insert share one DB transaction.
 */
final readonly class DatabaseManualResolver
{
    public function __construct(
        private Connection $connection,
        private ResolutionAuthorization $authorization,
        private string $operationsTable = 'flowguard_operations',
        private string $evidenceTable = 'flowguard_reconciliation_evidence',
        private string $decisionsTable = 'flowguard_resolution_decisions',
        private int $maxEvidenceAgeSeconds = 900,
    ) {
        foreach ([$operationsTable, $evidenceTable, $decisionsTable] as $table) {
            if (preg_match('/^[a-zA-Z_][a-zA-Z_0-9]*$/D', $table) !== 1) {
                throw new InvalidArgumentException('Invalid SQL table identifier.');
            }
        }

        if ($maxEvidenceAgeSeconds < 1 || $maxEvidenceAgeSeconds > 86400) {
            throw new InvalidArgumentException('Evidence max age must be between 1 and 86400 seconds.');
        }
    }

    private function databaseTime(): DateTimeImmutable
    {
        $expression = match ($this->connection->getDriverName()) {
            'mysql' => 'UTC_TIMESTAMP(6)',
            'pgsql' => "(clock_timestamp() AT TIME ZONE 'UTC')",
            'sqlite' => "strftime('%Y-%m-%d %H:%M:%f', 'now')",
            default => throw new InvalidArgumentException('Unsupported database driver for manual resolution.'),
        };

        /** @var stdClass|null $row */
        $row = $this->connection->selectOne('SELECT '.$expression.' AS current_time');

        if ($row === null || ! is_string($row->current_time)) {
            throw new ResolutionRejected('Could not read the database clock.');
        }

        return new DateTimeImmutable($row->current_time, new DateTimeZone('UTC'));
    }

    public function resolve(
        string $operationId,
        string $evidenceId,
        string $initiatorReference,
        string $approverReference,
        string $approvalReference,
    ): ResolutionDecision {
        foreach ([$initiatorReference, $approverReference] as $actor) {
            if (preg_match('/^[a-z][a-z0-9._:-]{0,119}$/D', $actor) !== 1) {
                throw new InvalidArgumentException('Invalid non-sensitive actor reference.');
            }
        }

        if (hash_equals($initiatorReference, $approverReference)) {
            throw new ResolutionRejected('Independent initiation and approval are required.');
        }

        if (preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._:-]{5,127}$/D', $approvalReference) !== 1) {
            throw new InvalidArgumentException('Approval reference must be a bounded, non-sensitive identifier.');
        }

        return $this->connection->transaction(function () use (
            $operationId,
            $evidenceId,
            $initiatorReference,
            $approverReference,
            $approvalReference,
        ): ResolutionDecision {
            /** @var stdClass|null $row */
            $row = $this->connection->table($this->operationsTable)
                ->where('id', $operationId)->lockForUpdate()->first();

            if ($row === null || $row->status !== OperationStatus::RequiresReconciliation->value) {
                throw new InvalidTransition;
            }

            /** @var stdClass|null $latest */
            $latest = $this->connection->table($this->evidenceTable)
                ->where('operation_id', $operationId)
                ->where('id', $evidenceId)->first();

            if ($latest === null) {
                throw new ResolutionRejected('Selected evidence does not exist for this operation.');
            }

            // UUID order is NOT insert order. Equal-timestamp events are
            // ambiguous, so reject instead of guessing which observation won.
            $supersededOrAmbiguous = $this->connection->table($this->evidenceTable)
                ->where('operation_id', $operationId)
                ->where('id', '<>', $evidenceId)
                ->where('created_at', '>=', (string) $latest->created_at)
                ->exists();

            if ($supersededOrAmbiguous) {
                throw new ResolutionRejected('Newer or ambiguously ordered evidence requires further review.');
            }

            $outcome = ReconciliationOutcome::from((string) $latest->outcome);
            if ($outcome === ReconciliationOutcome::Inconclusive) {
                throw new ResolutionRejected('Inconclusive evidence cannot resolve an operation.');
            }

            // Any contradictory confirmed observation is a hard stop, even if older.
            $conflicting = $this->connection->table($this->evidenceTable)
                ->where('operation_id', $operationId)
                ->whereIn('outcome', [
                    ReconciliationOutcome::ConfirmedSuccess->value,
                    ReconciliationOutcome::ConfirmedFailure->value,
                ])
                ->where('outcome', '<>', $outcome->value)->exists();

            if ($conflicting) {
                throw new ResolutionRejected('Conflicting provider observations require separate investigation.');
            }

            $observedAt = new DateTimeImmutable((string) $latest->observed_at, new DateTimeZone('UTC'));
            $now = $this->databaseTime();
            $ageSeconds = (float) $now->format('U.u') - (float) $observedAt->format('U.u');

            if ($ageSeconds < -60 || $ageSeconds > $this->maxEvidenceAgeSeconds) {
                throw new ResolutionRejected('Provider evidence is stale or dated too far into the future.');
            }

            $snapshot = new OperationSnapshot(
                id: (string) $row->id,
                scope: (string) $row->scope,
                status: OperationStatus::RequiresReconciliation,
                result: null,
                reconciliationReason: $row->reconciliation_reason === null ? null : (string) $row->reconciliation_reason,
            );

            $evidence = new EvidenceEntry(
                id: (string) $latest->id,
                operationId: $operationId,
                actorReference: (string) $latest->actor_reference,
                outcome: $outcome,
                source: (string) $latest->source,
                evidenceCode: (string) $latest->evidence_code,
                observedAt: $observedAt,
            );

            // The host app MUST authenticate both actors and validate an
            // independently recorded, tenant-scoped approval for this exact
            // operation + evidence + outcome. A string is not proof.
            $this->authorization->assertApproved(
                $initiatorReference, $approverReference, $approvalReference, $snapshot, $evidence,
            );

            $to = $outcome === ReconciliationOutcome::ConfirmedSuccess
                ? OperationStatus::ReconciledSuccess
                : OperationStatus::ReconciledFailure;

            $nowValue = now();
            $changed = $this->connection->table($this->operationsTable)
                ->where('id', $operationId)
                ->where('status', OperationStatus::RequiresReconciliation->value)
                ->update([
                    'status' => $to->value,
                    'finished_at' => $nowValue,
                    'updated_at' => $nowValue,
                ]);

            if ($changed !== 1) {
                throw new InvalidTransition;
            }

            $id = (string) Str::uuid();
            $this->connection->table($this->decisionsTable)->insert([
                'id' => $id,
                'operation_id' => $operationId,
                'evidence_id' => $evidenceId,
                'status' => $to->value,
                'initiator_reference' => $initiatorReference,
                'approver_reference' => $approverReference,
                'approval_reference' => $approvalReference,
                'created_at' => $nowValue,
                'updated_at' => $nowValue,
            ]);

            return new ResolutionDecision(
                id: $id,
                operationId: $operationId,
                evidenceId: $evidenceId,
                status: $to,
                initiatorReference: $initiatorReference,
                approverReference: $approverReference,
                approvalReference: $approvalReference,
            );
        }, 3);
    }
}
