<?php

declare(strict_types=1);

namespace Mykholy\Flowguard\Tests\Unit;

use InvalidArgumentException;
use Mykholy\Flowguard\Domain\OperationIdentity;
use PHPUnit\Framework\TestCase;

final class OperationIdentityTest extends TestCase
{
    public function test_canonical_payload_ignores_associative_key_order_but_not_list_order(): void
    {
        $first = OperationIdentity::from('payments.capture', 'req-1', ['amount' => 100, 'meta' => ['b' => 2, 'a' => 1], 'list' => [1, 2]]);
        $same = OperationIdentity::from('payments.capture', 'req-1', ['list' => [1, 2], 'meta' => ['a' => 1, 'b' => 2], 'amount' => 100]);
        $different = OperationIdentity::from('payments.capture', 'req-1', ['list' => [2, 1], 'amount' => 100, 'meta' => ['a' => 1, 'b' => 2]]);

        self::assertSame($first->payloadHash, $same->payloadHash);
        self::assertNotSame($first->payloadHash, $different->payloadHash);
        self::assertSame(64, strlen($first->keyHash));
    }

    public function test_invalid_scope_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        OperationIdentity::from('Pay ments', 'req-1', []);
    }

    public function test_empty_key_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        OperationIdentity::from('payments', '', []);
    }

    public function test_objects_are_rejected_to_keep_fingerprints_unambiguous(): void
    {
        $this->expectException(InvalidArgumentException::class);
        OperationIdentity::from('payments', 'key', ['object' => new \stdClass]);
    }

    public function test_overlarge_payload_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        OperationIdentity::from('payments', 'key', ['too_long' => str_repeat('x', 70000)]);
    }

    public function test_json_unsupported_values_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        OperationIdentity::from('payments', 'key', ['value' => NAN]);
    }
}
