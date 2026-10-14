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

    public function test_tenant_scope_supports_colons_like_inbox_and_outbox(): void
    {
        $identity = OperationIdentity::from('tenant.42:payments', 'capture-42', ['amount_minor' => 100]);
        self::assertSame('tenant.42:payments', $identity->scope);
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

    public function test_cyclic_payload_fails_without_unbounded_recursion(): void
    {
        $payload = ['operation' => 'capture'];
        $payload['cycle'] = &$payload;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('nesting');
        OperationIdentity::from('payments.capture', 'cyclic-key', $payload);
    }

    public function test_deeply_nested_payload_is_rejected_before_fingerprinting(): void
    {
        $payload = ['amount_minor' => 100];

        for ($level = 0; $level < 33; $level++) {
            $payload = ['nested' => $payload];
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('nesting');
        OperationIdentity::from('payments.capture', 'deep-key', $payload);
    }

    public function test_json_unsupported_values_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        OperationIdentity::from('payments', 'key', ['value' => NAN]);
    }
}
