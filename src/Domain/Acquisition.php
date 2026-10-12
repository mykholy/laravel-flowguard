<?php

declare(strict_types=1);

namespace Mykholy\Flowguard\Domain;

use LogicException;

/** Acquisition is a snapshot. A duplicate caller NEVER receives the owner's claim. */
final readonly class Acquisition
{
    public function __construct(
        public OperationSnapshot $operation,
        private ?Claim $claim,
    ) {}

    public function acquired(): bool
    {
        return $this->claim !== null;
    }

    public function claim(): Claim
    {
        return $this->claim ?? throw new LogicException('This caller did not acquire the operation. Do not execute side effects.');
    }
}
