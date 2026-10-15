# Durable inbox

The inbox accepts **already authenticated** inbound events and atomically
records both a receipt and an outbox signal. This covers the database
acceptance boundary, not signature verification or eventual publication.

```php
use Illuminate\Support\Facades\DB;
use Mykholy\Flowguard\Inbox\DatabaseInboxRecorder;

// Verify the provider signature, timestamp and tenant first.
$receipt = (new DatabaseInboxRecorder(DB::connection()))->receive(
    scope: 'tenant.42:webhooks',
    eventId: $providerEventId,
    payload: $verifiedPayload,
    topic: 'payment.received',
    subjectReference: $safeEventReference,
);
```

- The tenant-scoped provider event ID is hashed; the canonical payload is
  fingerprinted but not stored. Only bounded, opaque metadata is persisted.
- An event with the same ID and equivalent payload returns a duplicate receipt
  and does not create another outbox entry.
- If the same ID is reused with different content, it raises
  `IdempotencyConflict`. Investigate rather than silently acknowledging.
- A single transaction commits both writes, or neither.
- Duplicate unique-index errors are caught after transaction rollback, which
  also works for PostgreSQL. Other database errors must propagate.
- The host application must verify webhook authenticity, timestamp freshness,
  permissions and tenant ownership. This package does not trust incoming HTTP
  requests or authenticate signatures.
- Do not acknowledge receipt to the upstream provider until the transaction
  commits. Delivery is independently at-least-once.

The inbox stores no provider body. If handlers need a stable snapshot of the
original event, the host application must persist a vetted immutable snapshot
inside the same transaction and pass its reference as `subjectReference`.
