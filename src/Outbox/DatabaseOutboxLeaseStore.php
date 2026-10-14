<?php

declare(strict_types=1);

namespace Mykholy\Flowguard\Outbox;

use DateInterval;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use InvalidArgumentException;
use LogicException;
use Mykholy\Flowguard\Contracts\OutboxDispatchPrecondition;
use Mykholy\Flowguard\Contracts\OutboxLeaseRepository;
use Mykholy\Flowguard\Infrastructure\UtcDatabaseClock;
use stdClass;

/**
 * Database-backed message reservation with expiring, fenced ownership.
 *
 * A publisher must execute outside the reservation transaction. Crashes may
 * cause redelivery, so consumers must be idempotent.
 */
final readonly class DatabaseOutboxLeaseStore implements OutboxDispatchPrecondition, OutboxLeaseRepository
{
    public function __construct(
        private Connection $connection,
        private string $table = 'flowguard_outbox',
        private int $maxAttempts = 10,
        private ?string $scope = null,
    ) {
        if (preg_match('/^[a-zA-Z_][a-zA-Z_0-9]*$/D', $table) !== 1) {
            throw new InvalidArgumentException('Invalid outbox table name.');
        }

        if ($maxAttempts < 1 || $maxAttempts > 1000) {
            throw new InvalidArgumentException('Maximum delivery attempts must be between 1 and 1000.');
        }

        if ($scope !== null && preg_match('/^[a-z0-9][a-z0-9:._-]{0,119}$/D', $scope) !== 1) {
            throw new InvalidArgumentException('Outbox scope must be a bounded tenant-aware identifier.');
        }
    }

    public function assertDispatchAllowed(): void
    {
        if ($this->connection->transactionLevel() !== 0) {
            throw new LogicException('Outbox delivery cannot run inside a business transaction.');
        }
    }

    public function reserve(int $leaseSeconds = 60): ?OutboxLease
    {
        $this->assertDispatchAllowed();

        if ($leaseSeconds < 5 || $leaseSeconds > 3600) {
            throw new InvalidArgumentException('Lease duration must be between 5 and 3600 seconds.');
        }

        return $this->connection->transaction(function () use ($leaseSeconds): ?OutboxLease {
            $now = UtcDatabaseClock::now($this->connection);
            $nowString = $now->format('Y-m-d H:i:s.u');

            $driver = $this->connection->getDriverName();

            /** @var Builder $query */
            $query = $this->connection->table($this->table)
                ->when($this->scope !== null, function (Builder $query): void {
                    $query->where('scope', $this->scope);
                })
                ->where('delivery_attempts', '<', $this->maxAttempts)
                ->where(function (Builder $query) use ($nowString): void {
                    $query->where(function (Builder $pending) use ($nowString): void {
                        $pending->where('status', 'pending')
                            ->where(function (Builder $available) use ($nowString): void {
                                $available->whereNull('available_at')
                                    ->orWhere('available_at', '<=', $nowString);
                            });
                    })->orWhere(function (Builder $expired) use ($nowString): void {
                        $expired->where('status', 'leased')
                            ->where('lease_expires_at', '<=', $nowString);
                    });
                })
                ->orderBy('created_at')
                ->orderBy('id');

            // Skip rows locked by other transactions on supported SQL engines.
            /** @var stdClass|null $row */
            $row = match ($driver) {
                'mysql' => $this->mysqlCandidate($query),
                'pgsql' => $query->lock('FOR UPDATE SKIP LOCKED')->first(),
                'sqlite' => $query->lockForUpdate()->first(),
                default => throw new InvalidArgumentException('Unsupported outbox database driver.'),
            };

            if ($row === null) {
                return null;
            }

            $token = bin2hex(random_bytes(32));
            $attempt = (int) $row->delivery_attempts + 1;
            $expiresAt = $now->add(new DateInterval('PT'.$leaseSeconds.'S'));

            $this->connection->table($this->table)
                ->where('id', (string) $row->id)
                ->update([
                    'status' => 'leased',
                    'lease_hash' => hash('sha256', $token),
                    'lease_expires_at' => $expiresAt->format('Y-m-d H:i:s.u'),
                    'available_at' => null,
                    'delivery_attempts' => $attempt,
                    'updated_at' => $nowString,
                ]);

            return new OutboxLease(
                id: (string) $row->id,
                scope: (string) $row->scope,
                topic: (string) $row->topic,
                subjectReference: (string) $row->subject_ref,
                attempt: $attempt,
                token: $token,
            );
        }, 3);
    }

    private function mysqlCandidate(Builder $query): ?stdClass
    {
        // InnoDB locks scanned rows before filesort/LIMIT. Read a bounded
        // candidate window without locks, then lock by unique primary key.
        // A locking read rechecks eligibility against current committed state.
        $ids = (clone $query)->limit(32)->pluck('id')->all();
        if ($ids === []) {
            return null;
        }

        // A small probe budget prevents a synchronized herd from walking
        // the same stale list with dozens of SQL round trips per transaction.
        shuffle($ids);
        foreach (array_slice($ids, 0, 2) as $id) {
            /** @var stdClass|null $row */
            $row = (clone $query)->reorder()->where('id', (string) $id)
                ->lock('FOR UPDATE SKIP LOCKED')->first();

            if ($row !== null) {
                return $row;
            }
        }

        // A collision means poll again with a fresh transaction, never that
        // the durable queue is empty.
        return null;
    }

    public function acknowledge(OutboxLease $lease): void
    {
        $now = UtcDatabaseClock::now($this->connection)->format('Y-m-d H:i:s.u');

        $changed = $this->activeLease($lease)->update([
            'status' => 'delivered',
            'lease_hash' => null,
            'lease_expires_at' => null,
            'delivered_at' => $now,
            'updated_at' => $now,
            'last_failure_code' => null,
        ]);

        if ($changed !== 1) {
            throw new LeaseLost;
        }
    }

    public function release(OutboxLease $lease, string $failureCode, int $retryAfterSeconds = 60): void
    {
        if (preg_match('/^[a-z][a-z0-9._-]{0,119}$/D', $failureCode) !== 1) {
            throw new InvalidArgumentException('Failure must be a non-sensitive machine code.');
        }

        if ($retryAfterSeconds < 0 || $retryAfterSeconds > 86400) {
            throw new InvalidArgumentException('Retry delay must be between 0 and 86400 seconds.');
        }

        $now = UtcDatabaseClock::now($this->connection);
        $availableAt = $now->add(new DateInterval('PT'.$retryAfterSeconds.'S'));

        $changed = $this->activeLease($lease)->update([
            'status' => 'pending',
            'lease_hash' => null,
            'lease_expires_at' => null,
            'available_at' => $availableAt->format('Y-m-d H:i:s.u'),
            'updated_at' => $now->format('Y-m-d H:i:s.u'),
            'last_failure_code' => $failureCode,
        ]);

        if ($changed !== 1) {
            throw new LeaseLost;
        }
    }

    private function activeLease(OutboxLease $lease): Builder
    {
        $query = $this->connection->table($this->table)
            ->where('id', $lease->id)
            ->when($this->scope !== null, function (Builder $query): void {
                $query->where('scope', $this->scope);
            })
            ->where('status', 'leased')
            ->where('lease_hash', $lease->tokenHash());

        return match ($this->connection->getDriverName()) {
            'mysql' => $query->whereRaw('lease_expires_at > UTC_TIMESTAMP(6)'),
            'pgsql' => $query->whereRaw("lease_expires_at > (clock_timestamp() AT TIME ZONE 'UTC')"),
            'sqlite' => $query->whereRaw("lease_expires_at > strftime('%Y-%m-%d %H:%M:%f', 'now')"),
            default => throw new InvalidArgumentException('Unsupported outbox database driver.'),
        };
    }
}
