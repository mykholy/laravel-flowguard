<?php

declare(strict_types=1);

namespace Mykholy\Flowguard\Contracts;

use Mykholy\Flowguard\Outbox\OutboxMessage;

interface OutboxPublisher
{
    public function publish(OutboxMessage $message): void;
}
