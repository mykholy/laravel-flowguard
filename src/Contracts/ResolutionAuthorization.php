<?php

declare(strict_types=1);

namespace Mykholy\Flowguard\Contracts;

use Mykholy\Flowguard\Domain\OperationSnapshot;
use Mykholy\Flowguard\Reconciliation\EvidenceEntry;

/**
 * Application-owned, deny-by-default approval check.
 * Verify two independently authenticated principals and a durable approval,
 * bound to the exact operation, evidence ID, outcome and tenant.
 * Throw on denial. NEVER call external providers inside this method.
 */
interface ResolutionAuthorization
{
    public function assertApproved(
        string $initiatorReference,
        string $approverReference,
        string $approvalReference,
        OperationSnapshot $operation,
        EvidenceEntry $evidence,
    ): void;
}
