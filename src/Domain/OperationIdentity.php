<?php

declare(strict_types=1);

namespace Mykholy\Flowguard\Domain;

use InvalidArgumentException;
use JsonException;

/** Immutable, content-addressed identity. Payloads themselves are never stored. */
final readonly class OperationIdentity
{
    private function __construct(
        public string $scope,
        public string $keyHash,
        public string $payloadHash,
    ) {}

    /** @param array<string|int, mixed> $payload */
    public static function from(string $scope, string $key, array $payload): self
    {
        if (preg_match('/^[a-z][a-z0-9._-]{0,119}$/D', $scope) !== 1) {
            throw new InvalidArgumentException('Scope must be 1-120 lowercase ASCII characters (a-z, 0-9, dot, underscore, hyphen), starting with a letter.');
        }

        if ($key === '' || strlen($key) > 512) {
            throw new InvalidArgumentException('Idempotency key must contain 1-512 bytes.');
        }

        try {
            $encoded = json_encode(self::canonicalize($payload), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Payload cannot be fingerprinted as JSON.', 0, $exception);
        }

        if (strlen($encoded) > 65536) {
            throw new InvalidArgumentException('Canonical payload exceeds 64 KiB; use a minimal operation identity.');
        }

        return new self($scope, hash('sha256', $key), hash('sha256', $encoded));
    }

    /**
     * @param  array<string|int, mixed>  $items
     * @return array<string|int, mixed>
     */
    private static function canonicalize(array $items): array
    {
        if (! array_is_list($items)) {
            ksort($items, SORT_STRING);
        }

        foreach ($items as $key => $value) {
            if (is_array($value)) {
                $items[$key] = self::canonicalize($value);
            } elseif (! is_null($value) && ! is_scalar($value)) {
                throw new InvalidArgumentException('Payload must contain JSON-native scalars and arrays only.');
            }
        }

        return $items;
    }
}
