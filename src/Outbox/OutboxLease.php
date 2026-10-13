<?php

declare(strict_types=1);

namespace Mykholy\Flowguard\Outbox;

use LogicException;

/** A short-lived capability. Never serialize, persist or log the raw token. */
final readonly class OutboxLease
{
    public function __construct(
        public string $id,
        public string $scope,
        public string $topic,
        public string $subjectReference,
        public int $attempt,
        private string $token,
    ) {}

    public function tokenHash(): string
    {
        return hash('sha256', $this->token);
    }

    /** @return array<string, string|int> */
    public function __debugInfo(): array
    {
        return [
            'id' => $this->id,
            'topic' => $this->topic,
            'attempt' => $this->attempt,
            'token' => '[REDACTED]',
        ];
    }

    /** @return array<string, mixed> */
    public function __serialize(): array
    {
        throw new LogicException('Outbox leases cannot be serialized.');
    }

    /** @param  array<array-key, mixed>  $data */
    public function __unserialize(array $data): void
    {
        throw new LogicException('Outbox leases cannot be restored from serialization.');
    }
}
