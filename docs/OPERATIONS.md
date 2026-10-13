# Operations runbook

Flowguard is a library, not a hosted worker service. The application owner is
responsible for transport security, provider-specific state checks, retries,
and operational access control.

## Before enabling in an environment

1. Back up the application database and apply migrations in a staging clone.
2. Run php artisan flowguard:doctor after updating the package.
3. Verify every tenant's API/webhook credentials and receipt signature checks.
4. Confirm that the outbox, inbox and business tables use the same durable
   database connection when atomicity is required.
5. Configure dedicated, supervised publisher workers with explicit HTTP
   timeouts shorter than the lease duration.
6. Make consumers deduplicate using a stable message ID.

## Monitor

- Number and age of operations in requires_reconciliation.
- Processing operations older than their expected business deadline.
- Pending outbox messages delayed beyond expected publishing latency.
- Leased messages older than their lease expiry.
- Delivery attempts reaching the configured maximum.
- Unique-key conflicts, provider timeouts and lease-lost outcomes.

Flowguard exposes database records and a read-only diagnostic command.
Configure application monitoring without dumping raw payloads, access tokens,
claim capabilities or sensitive subject references.

## Incident response

**Unknown payment outcome:** Do not retry the charge. Query the gateway's
read-only status endpoint, preserve a bounded evidence code and require the
application's independently authenticated approval policy before classifying
the result.

**Outbox outage:** Restore workers and verify downstream deduplication before
draining queued messages. A worker crash after publishing can cause redelivery.

**Exhausted attempts:** Investigate the downstream fault and tenant scope.
There is no unconditional automatic reset/replay API. Re-enqueue only through
an explicitly reviewed application-specific remediation.

**Schema or database failure:** Stop publishers if atomicity cannot be
guaranteed. Restore consistent backups and validate foreign keys, outstanding
leases and pending receipts before resuming.

## Retention and upgrades

No automatic pruning is implemented. Do not delete unresolved operations,
resolution evidence or pending outbox messages merely because they are old.
Choose retention and deletion policies with the application's privacy, audit
and regulatory requirements in mind. Verify every migration and rollback
against a copy of existing data before deploying.

A passing installation diagnostic validates only the local schema, not remote
provider safety or multi-tenant authorization correctness.

## Lease migration rollback limits

Before downgrading the outbox lease schema, stop all dispatchers and
verify that **no active leases exist**. A schema rollback removes lease
tokens, attempt counters, retry delays and delivery timestamps; it is not
a safe replay or rollback of externally published messages. It must never
be used as a way to reset exhausted attempts.

`tests/Installation/outbox-upgrade.php` exercises the lease migration
down/up directly against an existing pending outbox record in a disposable
Laravel application. It verifies that the pending event survives. It does
**not** guarantee zero-downtime migration or a safe downgrade of a live
database with in-flight workers. Full backup/restore and engine-specific
rollout testing remain mandatory before a stable release.
