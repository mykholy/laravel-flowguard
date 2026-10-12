<?php

declare(strict_types=1);

namespace Mykholy\Flowguard\Outbox;

use Illuminate\Database\Connection;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;

/**
 * Stores a durable signal inside the caller's existing business transaction.
 *
 * No external API calls or queue dispatch happen here. Delivery is a separate
 * concern and can only offer at-least-once semantics.
 */
final readonly class DatabaseOutboxRecorder
{
    public function __construct(
        private Connection $connection,
        private string $table = 'flowguard_outbox',
    ) {
        if (preg_match('/^[a-zA-Z_][a-zA-Z_0-9]*$/D', $table) !== 1) {
            throw new InvalidArgumentException('Invalid outbox table name.');
        }
    }

    public function record(
        string $scope,
        string $eventId,
        string $topic,
        string $subjectReference,
    ): OutboxMessage {
        if ($this->connection->transactionLevel() < 1) {
            throw new LogicException('Outbox recording requires an active transaction on the same database connection.');
        }

        if (preg_match('/^[a-z0-9][a-z0-9:._-]{0,119}$/D', $scope) !== 1) {
            throw new InvalidArgumentException('Scope must be a bounded, tenant-aware identifier.');
        }

        if ($eventId === '' || strlen($eventId) > 1024) {
            throw new InvalidArgumentException('Event identity must contain 1 to 1024 bytes.');
        }

        if (preg_match('/^[a-z][a-z0-9._-]{0,119}$/D', $topic) !== 1) {
            throw new InvalidArgumentException('Topic must be a bounded machine-readable identifier.');
        }

        if (preg_match('/^[a-zA-Z0-9][a-zA-Z0-9:._-]{0,127}$/D', $subjectReference) !== 1) {
            throw new InvalidArgumentException('Subject reference must be a bounded opaque identifier.');
        }

        $id = (string) Str::uuid();
        $now = now();

        // The database unique index, not a read-before-write check, arbitrates
        // concurrent duplicate event identities. Let insertion errors propagate.
        $this->connection->table($this->table)->insert([
            'id' => $id,
            'scope' => $scope,
            'event_key_hash' => hash('sha256', $eventId),
            'topic' => $topic,
            'subject_ref' => $subjectReference,
            'status' => 'pending',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return new OutboxMessage($id, $scope, $topic, $subjectReference);
    }
}
