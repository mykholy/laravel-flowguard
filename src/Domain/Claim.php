<?php

declare(strict_types=1);

namespace Mykholy\Flowguard\Domain;

/** Only the request that inserted the operation receives this capability. Never log it. */
final readonly class Claim
{
    public function __construct(
        public string $operationId,
        private string $secret,
    ) {}

    public function secretHash(): string
    {
        return hash('sha256', $this->secret);
    }
}
