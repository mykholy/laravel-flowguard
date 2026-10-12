<?php

declare(strict_types=1);

namespace Mykholy\Flowguard\Inbox;

final readonly class InboxReceipt
{
    public function __construct(
        public string $id,
        public bool $duplicate,
    ) {}
}
