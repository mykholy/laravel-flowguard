<?php

declare(strict_types=1);

namespace Mykholy\Flowguard\Outbox;

use Mykholy\Flowguard\Contracts\OutboxLeaseRepository;
use Mykholy\Flowguard\Contracts\OutboxPublisher;
use Throwable;

/** Delivers one message outside the reservation transaction. */
final readonly class OutboxDispatcher
{
    public function __construct(
        private OutboxLeaseRepository $leases,
        private OutboxPublisher $publisher,
        private int $leaseSeconds = 60,
        private int $retryAfterSeconds = 60,
    ) {}

    public function dispatchOne(): bool
    {
        $lease = $this->leases->reserve($this->leaseSeconds);

        if ($lease === null) {
            return false;
        }

        try {
            $this->publisher->publish(new OutboxMessage(
                $lease->id,
                $lease->scope,
                $lease->topic,
                $lease->subjectReference,
            ));
        } catch (Throwable $exception) {
            $this->leases->release($lease, 'publish_failed', $this->retryAfterSeconds);

            throw $exception;
        }

        $this->leases->acknowledge($lease);

        return true;
    }
}
