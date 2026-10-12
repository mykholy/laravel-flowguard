<?php

declare(strict_types=1);

namespace Mykholy\Flowguard\Tests\Unit;

use Mykholy\Flowguard\Contracts\OutboxLeaseRepository;
use Mykholy\Flowguard\Contracts\OutboxPublisher;
use Mykholy\Flowguard\Outbox\LeaseLost;
use Mykholy\Flowguard\Outbox\OutboxDispatcher;
use Mykholy\Flowguard\Outbox\OutboxLease;
use Mykholy\Flowguard\Outbox\OutboxMessage;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class OutboxDispatcherTest extends TestCase
{
    private function lease(): OutboxLease
    {
        return new OutboxLease('e-1', 'tenant.42:orders', 'order.paid', 'order-42', 1, 'opaque-secret');
    }

    public function test_empty_queue_does_not_publish(): void
    {
        $leases = $this->createMock(OutboxLeaseRepository::class);
        $publisher = $this->createMock(OutboxPublisher::class);

        $leases->expects(self::once())->method('reserve')->willReturn(null);
        $publisher->expects(self::never())->method('publish');

        self::assertFalse((new OutboxDispatcher($leases, $publisher))->dispatchOne());
    }

    public function test_it_acknowledges_only_after_successful_publishing(): void
    {
        $lease = $this->lease();
        $leases = $this->createMock(OutboxLeaseRepository::class);
        $publisher = $this->createMock(OutboxPublisher::class);
        $sent = false;

        $leases->method('reserve')->willReturn($lease);
        $publisher->expects(self::once())->method('publish')
            ->willReturnCallback(static function (OutboxMessage $message) use (&$sent): void {
                self::assertSame('e-1', $message->id);
                self::assertSame('order-42', $message->subjectReference);
                $sent = true;
            });
        $leases->expects(self::once())->method('acknowledge')
            ->willReturnCallback(static function (OutboxLease $actual) use ($lease, &$sent): void {
                self::assertTrue($sent);
                self::assertSame($lease, $actual);
            });
        $leases->expects(self::never())->method('release');

        self::assertTrue((new OutboxDispatcher($leases, $publisher))->dispatchOne());
    }

    public function test_publishing_failure_releases_the_lease(): void
    {
        $lease = $this->lease();
        $leases = $this->createMock(OutboxLeaseRepository::class);
        $publisher = $this->createMock(OutboxPublisher::class);

        $leases->method('reserve')->willReturn($lease);
        $publisher->method('publish')->willThrowException(new RuntimeException('delivery failed'));
        $leases->expects(self::once())->method('release')->with($lease, 'publish_failed', 60);
        $leases->expects(self::never())->method('acknowledge');

        $this->expectException(RuntimeException::class);
        (new OutboxDispatcher($leases, $publisher))->dispatchOne();
    }

    public function test_acknowledgement_failure_does_not_trigger_a_second_publish(): void
    {
        $leases = $this->createMock(OutboxLeaseRepository::class);
        $publisher = $this->createMock(OutboxPublisher::class);

        $leases->method('reserve')->willReturn($this->lease());
        $publisher->expects(self::once())->method('publish');
        $leases->expects(self::once())->method('acknowledge')->willThrowException(new LeaseLost);
        $leases->expects(self::never())->method('release');

        $this->expectException(LeaseLost::class);
        (new OutboxDispatcher($leases, $publisher))->dispatchOne();
    }
}
