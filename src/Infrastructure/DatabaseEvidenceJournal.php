<?php

declare(strict_types=1);

namespace Mykholy\Flowguard\Infrastructure;

use DateTimeZone;
use Illuminate\Database\Connection;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Mykholy\Flowguard\Contracts\EvidenceAuthorization;
use Mykholy\Flowguard\Contracts\EvidenceJournal;
use Mykholy\Flowguard\Domain\OperationSnapshot;
use Mykholy\Flowguard\Domain\OperationStatus;
use Mykholy\Flowguard\Exceptions\InvalidTransition;
use Mykholy\Flowguard\Reconciliation\EvidenceEntry;
use Mykholy\Flowguard\Reconciliation\ReconciliationEvidence;
use stdClass;

/**
 * Opt-in evidence log; not a payment resolution, replay or provider verification API.
 * Policy runs against an actual database-locked row, not caller-supplied state.
 */
final readonly class DatabaseEvidenceJournal implements EvidenceJournal
{
    public function __construct(
        private Connection $connection,
        private EvidenceAuthorization $authorization,
        private string $operationsTable = 'flowguard_operations',
        private string $evidenceTable = 'flowguard_reconciliation_evidence',
    ) {
        foreach ([$operationsTable, $evidenceTable] as $table) {
            if (preg_match('/^[a-zA-Z_][a-zA-Z_0-9]*$/D', $table) !== 1) {
                throw new InvalidArgumentException('Invalid SQL table identifier.');
            }
        }
    }

    public function append(string $operationId, ReconciliationEvidence $evidence, string $actorReference): EvidenceEntry
    {
        if (preg_match('/^[a-z][a-z0-9._:-]{0,119}$/D', $actorReference) !== 1) {
            throw new InvalidArgumentException('Actor reference must be a non-sensitive machine identifier (1-120 characters).');
        }

        return $this->connection->transaction(function () use ($operationId, $evidence, $actorReference): EvidenceEntry {
            /** @var stdClass|null $row */
            $row = $this->connection->table($this->operationsTable)
                ->where('id', $operationId)
                ->lockForUpdate()
                ->first();

            if ($row === null || $row->status !== OperationStatus::RequiresReconciliation->value) {
                throw new InvalidTransition;
            }

            $operation = new OperationSnapshot(
                id: (string) $row->id,
                scope: (string) $row->scope,
                status: OperationStatus::RequiresReconciliation,
                result: null,
                reconciliationReason: $row->reconciliation_reason === null ? null : (string) $row->reconciliation_reason,
            );

            // An application MUST provide a real tenant- and actor-aware policy.
            // Policy must be synchronous, with no provider API/network calls.
            $this->authorization->assertCanRecord($actorReference, $operation);

            $id = (string) Str::uuid();
            $now = now();
            $observedAt = $evidence->observedAt->setTimezone(new DateTimeZone('UTC'));

            $this->connection->table($this->evidenceTable)->insert([
                'id' => $id,
                'operation_id' => $operation->id,
                'actor_reference' => $actorReference,
                'outcome' => $evidence->outcome->value,
                'source' => $evidence->source,
                'evidence_code' => $evidence->evidenceCode,
                'observed_at' => $observedAt->format('Y-m-d H:i:s.u'),
                // Never persist provider response, request, headers or raw reference.
                'provider_reference_present' => $evidence->providerReference !== null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return new EvidenceEntry(
                id: $id,
                operationId: $operation->id,
                actorReference: $actorReference,
                outcome: $evidence->outcome,
                source: $evidence->source,
                evidenceCode: $evidence->evidenceCode,
                observedAt: $observedAt,
            );
        }, 3);
    }
}
