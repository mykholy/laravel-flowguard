<?php

declare(strict_types=1);

namespace Mykholy\Flowguard\Tests\Unit;

use InvalidArgumentException;
use Mykholy\Flowguard\Contracts\OperationStore;
use Mykholy\Flowguard\Domain\Acquisition;
use Mykholy\Flowguard\Domain\Claim;
use Mykholy\Flowguard\Domain\OperationIdentity;
use Mykholy\Flowguard\Domain\OperationSnapshot;
use Mykholy\Flowguard\Flowguard;
use PHPUnit\Framework\TestCase;

final class FlowguardTest extends TestCase
{
    public function test_it_rejects_unstructured_reconciliation_reasons(): void
    {
        $store = $this->createMock(OperationStore::class);
        $store->expects(self::never())->method('requireReconciliation');
        $flowguard = new Flowguard($store);

        $this->expectException(InvalidArgumentException::class);
        $flowguard->requireReconciliation(new Claim('abc', 'token'), 'raw error: card number 0000');
    }
}
