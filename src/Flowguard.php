<?php

declare(strict_types=1);

namespace Mykholy\Flowguard;

use InvalidArgumentException;
use Mykholy\Flowguard\Contracts\OperationStore;
use Mykholy\Flowguard\Domain\Acquisition;
use Mykholy\Flowguard\Domain\Claim;
use Mykholy\Flowguard\Domain\OperationIdentity;
use Mykholy\Flowguard\Domain\OperationSnapshot;

/** Application API. Does not execute external side effects for you. */
final readonly class Flowguard
{
    public function __construct(private OperationStore $store) {}

    /** @param array<string|int, mixed> $payload */
    public function begin(string $scope, string $idempotencyKey, array $payload): Acquisition
    {
        return $this->store->acquire(OperationIdentity::from($scope, $idempotencyKey, $payload));
    }

    /** @param array<string, mixed> $safeResultMetadata */
    public function complete(Claim $claim, array $safeResultMetadata = []): OperationSnapshot
    {
        return $this->store->complete($claim, $safeResultMetadata);
    }

    public function requireReconciliation(Claim $claim, string $reason): OperationSnapshot
    {
        if (preg_match('/^[a-z][a-z0-9._-]{0,119}$/D', $reason) !== 1) {
            throw new InvalidArgumentException('Reason must be a machine-readable, non-sensitive code of 1-120 ASCII characters.');
        }

        return $this->store->requireReconciliation($claim, $reason);
    }
}
