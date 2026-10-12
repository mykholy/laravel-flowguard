<?php

declare(strict_types=1);

namespace Mykholy\Flowguard\Exceptions;

use RuntimeException;

final class InvalidTransition extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Operation has already transitioned or the supplied claim is not its owner.');
    }
}
