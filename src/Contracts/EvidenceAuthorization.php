<?php

declare(strict_types=1);

namespace Mykholy\Flowguard\Contracts;

use Mykholy\Flowguard\Domain\OperationSnapshot;

/** App-supplied tenant-scoped authorization; throw on denial. */
interface EvidenceAuthorization
{
    public function assertCanRecord(string $actorReference, OperationSnapshot $operation): void;
}
