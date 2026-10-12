<?php

declare(strict_types=1);

namespace Mykholy\Flowguard\Contracts;

/** A lease store can block external side effects until local writes commit. */
interface OutboxDispatchPrecondition
{
    public function assertDispatchAllowed(): void;
}
