# Retention, archival and replay safety

Status: **v1 policy draft**. Flowguard intentionally has no automatic
pruning command. Retention periods must be agreed by the application owner,
security reviewer and applicable financial/privacy requirements before a
stable release.

## Record classes and default handling

| Table | Meaning | Safe default |
| --- | --- | --- |
| `flowguard_operations` | Durable idempotency identity and outcome | Preserve all rows; never expire claims automatically |
| `flowguard_reconciliation_evidence` | Provider observations linked to an operation | Preserve with the parent operation, particularly while unresolved |
| `flowguard_resolution_decisions` | Operator-authorized audit history | Preserve with parent operation and its selected evidence |
| `flowguard_inbox` | Tenant-scoped inbound deduplication receipt | Preserve through the upstream's maximum replay and retry horizon |
| `flowguard_outbox` | Published/deliverable event identity, retries and leases | Preserve pending, leased and exhausted records; retain delivered identities long enough for downstream deduplication |

Treat SHA-256 hashes of event IDs, idempotency keys and payloads as
**pseudonymous identifiers**, not anonymous data. A predictable input may be
guessable from its hash. Never assume it is permissible to store hashes
indefinitely without a privacy review.

## Records that must never be automatically deleted

- `processing` or `requires_reconciliation` operations, even if old.
- Operations in any terminal state whose idempotency identity is still within
  the caller's or provider's potential retry/replay period.
- Any related evidence or manual resolution while the parent operation is
  retained or subject to investigation/legal hold.
- `pending` and `leased` outbox messages, including expired leases or records
  that reached their maximum attempts.
- Inbound inbox receipts whose upstream event ID could still reappear.

**Deleting an idempotency row removes its uniqueness guarantee.** A retried
payment request with the same key can then be treated as a new request.
**Deleting an inbox receipt removes inbound deduplication.** Likewise,
deleting a delivered outbox event can make it harder to investigate a
provider-side duplicate or acknowledgement ambiguity.

## Versioned archival procedure (application-owned)

1. Write down the actual provider retry/replay windows, privacy obligations,
   litigation holds and business retention requirements for each integration.
   Unknown or unbounded replay windows mean **no automatic deletion**.
2. Quiesce writers or take an application-defined consistent snapshot.
   Record a backup restore point, schema version and archive batch ID.
3. Reconcile external outcomes independently; do not infer delivery or
   provider success from age, row status alone or a timeout.
4. Export a consistent set of operations, evidence, decisions and inbox/outbox
   references into a restricted, integrity-checked archive. Verify counts,
   foreign key closure, restore access and audit trail before considering
   source removal.
5. Retain durable deduplication tombstones for any identity that must remain
   replay-protected. Flowguard does **not** currently provide these tombstones,
   therefore source deletion is **not** a supported automated workflow.
6. Require recorded operator approval for any exceptional erasure or
   irreversible destructive change. Follow a tested, staged application
   migration; never use `migrate:rollback` as cleanup.

## Foreign key and restore considerations

- The inbox references the outbox with `RESTRICT ON DELETE`.
- Evidence references operations; resolution decisions reference both
  operations and evidence, also with restrictive foreign keys.
- Deleting an outbox row before its inbox receipt fails at the database level;
  removing the receipt first would compromise event deduplication.
- Restoring a database snapshot can restore old pending/leased states after a
  provider already accepted deliveries. Require downstream idempotency and
  reconciliation before resuming delivery.
- No retention schedule, GDPR erasure workflow, warehouse export or archive
  CLI is enabled by this package. Implementations need their own reviewed
  privacy and incident response process.

See [operations runbook](OPERATIONS.md) and
[stable release gates](RELEASE_CRITERIA.md).
