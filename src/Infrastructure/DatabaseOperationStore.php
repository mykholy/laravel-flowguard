<?php

declare(strict_types=1);

namespace Mykholy\Flowguard\Infrastructure;

use Illuminate\Database\Connection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use InvalidArgumentException;
use JsonException;
use Mykholy\Flowguard\Contracts\OperationStore;
use Mykholy\Flowguard\Domain\Acquisition;
use Mykholy\Flowguard\Domain\Claim;
use Mykholy\Flowguard\Domain\OperationIdentity;
use Mykholy\Flowguard\Domain\OperationSnapshot;
use Mykholy\Flowguard\Domain\OperationStatus;
use Mykholy\Flowguard\Exceptions\IdempotencyConflict;
use Mykholy\Flowguard\Exceptions\InvalidTransition;
use RuntimeException;
use stdClass;

/**
 * Durable, fail-closed claims. No lease expiry or automatic retry: the status
 * stays processing after a crash because external effects may have occurred.
 */
final readonly class DatabaseOperationStore implements OperationStore
{
    public function __construct(private Connection $connection, private string $table = 'flowguard_operations')
    {
        if (preg_match('/^[a-zA-Z_][a-zA-Z_0-9]*$/D', $table) !== 1) {
            throw new InvalidArgumentException('Invalid SQL table identifier.');
        }
    }

    public function acquire(OperationIdentity $identity): Acquisition
    {
        try {
            return $this->connection->transaction(function () use ($identity): Acquisition {
                $id = (string) Str::uuid();
                $secret = bin2hex(random_bytes(32));
                $now = now();

                // The database unique index arbitrates competing owners.
                // Do not use INSERT IGNORE: unrelated write errors must surface.
                $this->connection->table($this->table)->insert([
                    'id' => $id,
                    'scope' => $identity->scope,
                    'key_hash' => $identity->keyHash,
                    'payload_hash' => $identity->payloadHash,
                    'claim_hash' => hash('sha256', $secret),
                    'status' => OperationStatus::Processing->value,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                /** @var stdClass|null $row */
                $row = $this->connection->table($this->table)->where('id', $id)->first();

                if ($row === null) {
                    throw new RuntimeException('Newly inserted operation could not be read.');
                }

                return new Acquisition($this->snapshot($row), new Claim($id, $secret));
            }, 3);
        } catch (UniqueConstraintViolationException $exception) {
            // The failed insert transaction has already rolled back. In
            // PostgreSQL we must not query inside the aborted transaction.
            /** @var stdClass|null $row */
            $row = $this->connection->table($this->table)
                ->where('scope', $identity->scope)
                ->where('key_hash', $identity->keyHash)
                ->first();

            if ($row === null) {
                throw $exception;
            }

            if (! hash_equals((string) $row->payload_hash, $identity->payloadHash)) {
                throw new IdempotencyConflict;
            }

            // A duplicate never receives another caller's owner capability.
            return new Acquisition($this->snapshot($row), null);
        }
    }

    /** @param array<string, mixed> $result */
    public function complete(Claim $claim, array $result): OperationSnapshot
    {
        try {
            $json = json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Result metadata must be JSON-serializable.', 0, $exception);
        }

        if (strlen($json) > 16384) {
            throw new InvalidArgumentException('Result metadata must not exceed 16 KiB. Store references, not raw responses.');
        }

        return $this->transition($claim, OperationStatus::Completed, $json, null);
    }

    public function requireReconciliation(Claim $claim, string $reason): OperationSnapshot
    {
        return $this->transition($claim, OperationStatus::RequiresReconciliation, null, $reason);
    }

    private function transition(Claim $claim, OperationStatus $to, ?string $json, ?string $reason): OperationSnapshot
    {
        $updated = $this->connection->table($this->table)
            ->where('id', $claim->operationId)
            ->where('claim_hash', $claim->secretHash())
            ->where('status', OperationStatus::Processing->value)
            ->update([
                'status' => $to->value,
                'result_json' => $json,
                'reconciliation_reason' => $reason,
                'finished_at' => now(),
                'updated_at' => now(),
            ]);

        if ($updated !== 1) {
            throw new InvalidTransition;
        }

        $row = $this->connection->table($this->table)->where('id', $claim->operationId)->first();

        if ($row === null) {
            throw new RuntimeException('Updated operation is missing.');
        }

        return $this->snapshot($row);
    }

    private function snapshot(stdClass $row): OperationSnapshot
    {
        $result = $row->result_json === null ? null : json_decode((string) $row->result_json, true, 512, JSON_THROW_ON_ERROR);

        if ($result !== null && ! is_array($result)) {
            throw new RuntimeException('Stored result metadata is malformed.');
        }

        return new OperationSnapshot(
            id: (string) $row->id,
            scope: (string) $row->scope,
            status: OperationStatus::from((string) $row->status),
            result: $result,
            reconciliationReason: $row->reconciliation_reason === null ? null : (string) $row->reconciliation_reason,
        );
    }
}
