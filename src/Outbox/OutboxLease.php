<?php

declare(strict_types=1);

namespace Mykholy\Flowguard\Outbox;

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
}
