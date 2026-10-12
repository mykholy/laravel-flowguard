<?php

declare(strict_types=1);

namespace Mykholy\Flowguard\Reconciliation;

use DateTimeImmutable;

/** Immutable record of an observation; does not authorize recovery. */
final readonly class EvidenceEntry
{
    public function __construct(
        public string $id,
        public string $operationId,
        public string $actorReference,
        public ReconciliationOutcome $outcome,
        public string $source,
        public string $evidenceCode,
        public DateTimeImmutable $observedAt,
    ) {}
}
