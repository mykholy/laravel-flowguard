<?php

declare(strict_types=1);

namespace Mykholy\Flowguard\Domain;

use LogicException;

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

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['operationId' => $this->operationId, 'secret' => '[REDACTED]'];
    }

    /** @return array<string, mixed> */
    public function __serialize(): array
    {
        throw new LogicException('Owner capabilities cannot be serialized.');
    }

    /** @param  array<array-key, mixed>  $data */
    public function __unserialize(array $data): void
    {
        throw new LogicException('Owner capabilities cannot be restored from serialization.');
    }
}
