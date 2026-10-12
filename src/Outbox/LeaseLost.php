<?php

declare(strict_types=1);

namespace Mykholy\Flowguard\Outbox;

use RuntimeException;

final class LeaseLost extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Outbox lease is no longer active or owned by this worker.');
    }
}
