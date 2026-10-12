<?php

declare(strict_types=1);

namespace Mykholy\Flowguard\Contracts;

use Mykholy\Flowguard\Domain\Acquisition;
use Mykholy\Flowguard\Domain\Claim;
use Mykholy\Flowguard\Domain\OperationIdentity;
use Mykholy\Flowguard\Domain\OperationSnapshot;

interface OperationStore
{
    public function acquire(OperationIdentity $identity): Acquisition;

    /** @param array<string, mixed> $result */
    public function complete(Claim $claim, array $result): OperationSnapshot;

    public function requireReconciliation(Claim $claim, string $reason): OperationSnapshot;
}
