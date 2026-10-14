# Outbox leases

## How to drive a worker

The application constructs a DatabaseOutboxLeaseStore using the **same durable
database connection** as its outbox recorder.

    $dispatcher = new OutboxDispatcher(
        new DatabaseOutboxLeaseStore(DB::connection()),
        app(OutboxPublisher::class),
        app(LoggerInterface::class),
    );

    // Run outside a business transaction, from a supervised worker.
    $dispatcher->dispatchOne();

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
- MySQL 8 and PostgreSQL 16 use `FOR UPDATE SKIP LOCKED` so competing
  workers can reserve another eligible row instead of waiting for a locked
  message. Returning `null` does not prove no messages exist: every eligible
  row may be locked by another transaction. The next poll can retry.
  SQLite remains a development-only fallback with no row-level locks.

## Limits

**At least once, not exactly once.** If the provider succeeds and the worker
crashes before acknowledge(), the lease expires and the next worker can
publish again. Consumers need durable idempotency independent of Flowguard.

Host applications must isolate tenant-scoped publishers, protect operator
access, and avoid putting secrets in subject references or failure codes.
A host-bound single-message CLI dispatcher and read-only operational commands
are provided; scheduled workers and provider-specific transports are not.

Re-check database behavior on MySQL and PostgreSQL with independent workers
before relying on this in production. SQLite tests are development-only.

## MySQL lock footprint

MySQL first reads up to 32 oldest eligible candidate IDs without locks, then
checks candidates in shuffled order using primary-key `FOR UPDATE SKIP LOCKED`
reads. Eligibility is rechecked by the locking read inside the reservation
transaction. This prevents InnoDB's sorted range scan from locking unrelated
messages before LIMIT is applied. PostgreSQL retains its direct ordered
SKIP LOCKED query. No host session isolation setting is changed.

Selection within the MySQL window is best effort, not strict FIFO. If all
candidates are locked or become ineligible, reserve returns null and workers
must poll again; eligible work can exist beyond that bounded window.
