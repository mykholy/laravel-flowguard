<?php

declare(strict_types=1);

namespace Mykholy\Flowguard\Contracts;

use Mykholy\Flowguard\Outbox\OutboxLease;

interface OutboxLeaseRepository
{
    public function reserve(int $leaseSeconds = 60): ?OutboxLease;

    public function acknowledge(OutboxLease $lease): void;

    public function release(OutboxLease $lease, string $failureCode, int $retryAfterSeconds = 60): void;
}
