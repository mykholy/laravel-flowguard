<?php

declare(strict_types=1);

namespace Mykholy\Flowguard\Tests\Unit;

use LogicException;
use Mykholy\Flowguard\Domain\Claim;
use Mykholy\Flowguard\Outbox\OutboxLease;
use PHPUnit\Framework\TestCase;

final class SensitiveCapabilitiesTest extends TestCase
{
    public function test_claim_debugging_does_not_display_its_owner_secret(): void
    {
        $claim = new Claim('operation-42', 'secret-never-log');
        $output = $claim->__debugInfo();

        self::assertSame('operation-42', $output['operationId']);
        self::assertSame('[REDACTED]', $output['secret']);
        self::assertNotContains('secret-never-log', $output);

        ob_start();
        var_dump($claim);
        $dump = (string) ob_get_clean();

        self::assertStringNotContainsString('secret-never-log', $dump);
        self::assertStringContainsString('[REDACTED]', $dump);
    }

    public function test_lease_debugging_does_not_display_its_token(): void
    {
        $lease = new OutboxLease('outbox-42', 'tenant.42:orders', 'order.paid', 'order-42', 2, 'lease-never-log');
        $output = $lease->__debugInfo();

        self::assertSame('[REDACTED]', $output['token']);
        self::assertArrayNotHasKey('subjectReference', $output);
        self::assertNotContains('lease-never-log', $output);

        ob_start();
        var_dump($lease);
        $dump = (string) ob_get_clean();

        self::assertStringNotContainsString('lease-never-log', $dump);
        self::assertStringContainsString('[REDACTED]', $dump);
    }

    public function test_claim_rejects_serialization(): void
    {
        $claim = new Claim('operation-42', 'sensitive');
        $this->expectException(LogicException::class);
        serialize($claim);
    }

    public function test_lease_rejects_serialization(): void
    {
        $lease = new OutboxLease('event-42', 'tenant.42:orders', 'order.paid', 'order-42', 1, 'sensitive');
        $this->expectException(LogicException::class);
        serialize($lease);
    }

    public function test_claim_rejects_manual_restoration(): void
    {
        $claim = new Claim('operation-42', 'sensitive');
        $this->expectException(LogicException::class);
        $claim->__unserialize([]);
    }

    public function test_lease_rejects_manual_restoration(): void
    {
        $lease = new OutboxLease('event-42', 'tenant.42:orders', 'order.paid', 'order-42', 1, 'sensitive');
        $this->expectException(LogicException::class);
        $lease->__unserialize([]);
    }
}
