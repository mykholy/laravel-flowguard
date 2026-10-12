<?php

declare(strict_types=1);

namespace Mykholy\Flowguard\Tests\Feature;

use Illuminate\Support\Facades\DB;
use LogicException;
use Mykholy\Flowguard\Contracts\OutboxPublisher;
use Mykholy\Flowguard\Outbox\DatabaseOutboxLeaseStore;
use Mykholy\Flowguard\Outbox\OutboxDispatcher;
use Psr\Log\LoggerInterface;

final class OutboxDispatchTransactionTest extends TestCase
{
    public function test_no_external_publish_is_possible_during_an_outer_transaction(): void
    {
        $publisher = $this->createMock(OutboxPublisher::class);
        $publisher->expects(self::never())->method('publish');

        $dispatcher = new OutboxDispatcher(
            new DatabaseOutboxLeaseStore(DB::connection()),
            $publisher,
            $this->createMock(LoggerInterface::class),
        );

        $this->expectException(LogicException::class);

        DB::transaction(function () use ($dispatcher): void {
            $dispatcher->dispatchOne();
        });
    }
}
