<?php

declare(strict_types=1);

namespace Mykholy\Flowguard\Exceptions;

use RuntimeException;

final class IdempotencyConflict extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The idempotency key was reused with a different payload.');
    }
}
