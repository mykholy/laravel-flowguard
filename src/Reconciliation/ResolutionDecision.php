<?php

declare(strict_types=1);

namespace Mykholy\Flowguard\Reconciliation;

use Mykholy\Flowguard\Domain\OperationStatus;

/** Immutable receipt for a committed manual classification decision. */
final readonly class ResolutionDecision
{
    public function __construct(
        public string $id,
        public string $operationId,
        public string $evidenceId,
        public OperationStatus $status,
        public string $initiatorReference,
        public string $approverReference,
        public string $approvalReference,
    ) {}
}
