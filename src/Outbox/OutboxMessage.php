<?php

declare(strict_types=1);

namespace Mykholy\Flowguard\Outbox;

/** Receipt for a message written alongside the caller's business data. */
final readonly class OutboxMessage
{
    public function __construct(
        public string $id,
        public string $scope,
        public string $topic,
        public string $subjectReference,
    ) {}
}
