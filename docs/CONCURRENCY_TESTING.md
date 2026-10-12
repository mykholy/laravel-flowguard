# Concurrency and fault-injection checks

The regular PHPUnit tests run on in-memory SQLite. **SQLite tests alone cannot prove
MySQL/PostgreSQL database arbitration under concurrent workers.** The separate
`composer test:concurrency` suite launches 12–16 independent PHP processes, gates
all at the same unique-key insertion, and verifies:

1. Exactly one durable row and exactly one owner across 16 contending processes.
2. All callers observe the same immutable operation identifier.
3. Losing an owner's capability on process exit **never** makes the operation
   available for an automatic claim/replay.
4. A missing database table raises an exception instead of returning ownership.

The suite **only** runs when `FLOWGUARD_CONCURRENCY_TEST=1` and
`FLOWGUARD_TEST_DATABASE=flowguard_ci`. It creates and drops a randomly named
`fg_race_*` table and must never be pointed at a real application's database.

CI uses disposable MySQL 8 and PostgreSQL 16 service containers, with dedicated
`flowguard_ci` databases. Results must be checked on GitHub Actions; the source
checkout alone does not prove those integration tests have passed.

```bash
FLOWGUARD_CONCURRENCY_TEST=1 \
FLOWGUARD_TEST_DATABASE=flowguard_ci \
FLOWGUARD_TEST_DRIVER=mysql \
FLOWGUARD_TEST_HOST=127.0.0.1 \
FLOWGUARD_TEST_PORT=3306 \
FLOWGUARD_TEST_USERNAME=flowguard \
FLOWGUARD_TEST_PASSWORD=flowguard \
composer test:concurrency
```

## Still not proven

- Exactly-once *external* side effects (impossible without provider guarantees).
- Provider-specific timeout and unknown-outcome reconciliation.
- Network partitions, multi-region replication or 1000+ simultaneous workers.
- Recovery after loss of both claim token and persisted provider state.

Any failed CI test is a **release blocker**, not something to ignore.

## Fault injection: worker termination

The OutboxDatabaseTest suite starts a separate PHP process, waits until it
commits a lease, and terminates that process before acknowledgement. The
parent verifies that the lease remains durable, advances the expiry timestamp
in the disposable test database, and confirms a new lease can finish the
message. This covers the **reserved-but-not-acknowledged** crash point.

It does not simulate a remote provider accepting a publish immediately before
the process dies. That remains an end-to-end test requirement, since a retry
in that window can deliver a duplicate.

## Ambiguous external acknowledgement

The AtLeastOnceHandoffTest simulates a receiver accepting a published message
and the publisher then throwing due to a lost acknowledgement. The dispatcher
releases the source lease. A second attempt delivers the identical message ID
again, and the downstream inbox accepts it as a duplicate without creating
another processing signal.

This is a deterministic integration fixture, **not** proof about any payment
gateway or other third-party service. Production consumers must enforce the same
stable deduplication key, and providers that lack such a contract remain an
integration-specific risk.
