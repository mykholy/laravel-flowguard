# Outbox leases

## How to drive a worker

The application constructs a DatabaseOutboxLeaseStore using the **same durable
database connection** as its outbox recorder.

    $store = new DatabaseOutboxLeaseStore(DB::connection());

    $lease = $store->reserve(60);

    if ($lease !== null) {
        try {
            // Publisher must be idempotent and load an authorized snapshot
            // using $lease->scope, $lease->topic, $lease->subjectReference.
            $publisher->publish($lease);
            $store->acknowledge($lease);
        } catch (\Throwable $exception) {
            // Map the exception to a safe machine code; never store raw text.
            $store->release($lease, 'publish_failed', 120);
        }
    }

This is a usage illustration, **not a built-in worker**. Do not blindly treat
LeaseLost as a publish failure: the lease may have expired and another worker
may already be delivering the same message. Do not catch acknowledgement
failure and infer that the remote system never received the message.

## Reservation contract

- reserve() locks one eligible row in a short transaction and writes a
  random token hash, expiration and incremented attempt counter.
- Pending messages can be reserved when their retry delay has elapsed.
  Previously leased messages can be claimed again after lease expiration.
- acknowledge() and release() require the **current, unexpired lease token**
  and update by a conditional statement. A stale worker cannot overwrite a
  later worker's decision.
- No third-party calls occur inside any database transaction in this layer.
- The publisher does not run under an active reservation transaction.
- Retry delay must be within 0..86400 seconds. Expired messages are
  automatically eligible for **reservation**, not automatically published.
- Messages reaching maxAttempts (default 10) stop being reserved. An operator
  must inspect or deliberately intervene; this increment offers no reset API.
- No SKIP LOCKED strategy yet: row-level locks serialize concurrent reservations
  on MySQL/PostgreSQL. This is deliberate for an initial correctness baseline.

## Limits

**At least once, not exactly once.** If the provider succeeds and the worker
crashes before acknowledge(), the lease expires and the next worker can
publish again. Consumers need durable idempotency independent of Flowguard.

Host applications must isolate tenant-scoped publishers, protect operator
access, and avoid putting secrets in subject references or failure codes.
Redis/queue integrations, dispatcher processes, read-only management commands,
retention and throughput benchmarks are future increments.

Re-check database behavior on MySQL and PostgreSQL with independent workers
before relying on this in production. SQLite tests are development-only.
