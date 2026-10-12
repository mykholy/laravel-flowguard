<?php

declare(strict_types=1);

namespace Mykholy\Flowguard\Exceptions;

use RuntimeException;

/** A rejected bookkeeping decision; never retry a payment based on this. */
final class ResolutionRejected extends RuntimeException {}
