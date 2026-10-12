<?php

declare(strict_types=1);

namespace Mykholy\Flowguard\Inbox;

use Illuminate\Database\Connection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Mykholy\Flowguard\Domain\OperationIdentity;
use Mykholy\Flowguard\Exceptions\IdempotencyConflict;
use Mykholy\Flowguard\Outbox\DatabaseOutboxRecorder;
use stdClass;

/**
 * Accepts an already-authenticated provider event and atomically records
 * an inbox receipt with a corresponding pending outbox signal.
 */
final readonly class DatabaseInboxRecorder
{
    public function __construct(
        private Connection $connection,
        private string $inboxTable = 'flowguard_inbox',
        private string $outboxTable = 'flowguard_outbox',
    ) {
        foreach ([$inboxTable, $outboxTable] as $table) {
            if (preg_match('/^[a-zA-Z_][a-zA-Z_0-9]*$/D', $table) !== 1) {
                throw new InvalidArgumentException('Invalid inbox table name.');
            }
        }
    }

    /**
     * @param array<string|int, mixed> $payload
     */
    public function receive(
        string $scope,
        string $eventId,
        array $payload,
        string $topic,
        string $subjectReference,
    ): InboxReceipt {
        if (preg_match('/^[a-z0-9][a-z0-9:._-]{0,119}$/D', $scope) !== 1) {
            throw new InvalidArgumentException('Inbox scope must be a tenant-aware machine identifier.');
        }

        // Fingerprint the verified payload, but never persist the body.
        $identity = OperationIdentity::from('inbox', $eventId, [
            'payload' => $payload,
            'topic' => $topic,
            'subject' => $subjectReference,
        ]);

        try {
            return $this->connection->transaction(function () use (
                $scope,
                $eventId,
                $topic,
                $subjectReference,
                $identity,
            ): InboxReceipt {
                $id = (string) Str::uuid();
                $outbox = (new DatabaseOutboxRecorder($this->connection, $this->outboxTable))
                    ->record($scope, 'inbox:'.$eventId, $topic, $subjectReference);
                $now = now();

                $this->connection->table($this->inboxTable)->insert([
                    'id' => $id,
                    'scope' => $scope,
                    'event_key_hash' => $identity->keyHash,
                    'payload_hash' => $identity->payloadHash,
                    'outbox_id' => $outbox->id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                return new InboxReceipt($id, false);
            }, 3);
        } catch (UniqueConstraintViolationException $exception) {
            /** @var stdClass|null $existing */
            $existing = $this->connection->table($this->inboxTable)
                ->where('scope', $scope)
                ->where('event_key_hash', $identity->keyHash)
                ->first();

            if ($existing === null) {
                throw $exception;
            }

            if (hash_equals((string) $existing->payload_hash, $identity->payloadHash) === false) {
                throw new IdempotencyConflict;
            }

            return new InboxReceipt((string) $existing->id, true);
        }
    }
}
