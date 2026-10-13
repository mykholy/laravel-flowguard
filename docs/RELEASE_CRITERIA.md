# Release criteria

The first stable tag is **not authorized** by passing unit tests alone.
Flowguard coordinates operations that may have already changed remote state.
Exactly-once external side effects are outside the library's guarantees.

## Completed evidence

- [x] Scoped database-backed idempotency and immutable fingerprints.
- [x] Duplicate requests cannot obtain an owner's claim.
- [x] Unknown external outcomes remain fail-closed until reviewed.
- [x] Authorization-gated reconciliation evidence and manual classification.
- [x] Transactional inbox receipts and outbox recording.
- [x] Fenced, expiring outbox reservation and host-supplied publisher contract.
- [x] Test coverage for the reserved-worker crash point.
- [x] Integration fixture for accepted-but-unacknowledged delivery.
- [x] Laravel 12/13, PHP 8.3/8.4, MySQL 8 and PostgreSQL 16 CI matrix.
- [x] Clean Laravel 12/13 install, operator diagnostics and database benchmarks.
- [ ] Production host-auth policy has not been reviewed independently.

## Required before v1.0.0

- [ ] Independent security review, including real tenant-policy implementations,
      provider verification, credentials handling, and a full threat-model audit.
- [ ] At least one real staging integration with a representative provider:
      inject duplicate webhooks, provider timeouts, and process crashes.
- [ ] Fault-injection repeat runs at realistic concurrency; define acceptable
      retry, contention and deadlock characteristics on MySQL and PostgreSQL.
- [ ] Reproducible performance benchmarks and a documented capacity envelope.
- [ ] Clear archival and retention policy for operations, evidence, inbox and
      outbox messages. Never prune unresolved states without explicit review.
- [ ] Backward/forward migration and rollback validation on existing data.
- [ ] An operational runbook (alerts, exhausted messages, manual resolutions,
      permissions, backup/restore, and failure escalation).
- [ ] A reviewed and versioned public API contract with changelog.
- [ ] Composer package publication, signed/verified release provenance where
      available, and a GitHub v1.0.0 release by the repository maintainer.

## Versioning

No stable tag should be pushed while these gates remain open. Development may
publish pre-release tags, but they must state experimental status unambiguously.
Once v1.0.0 is published, follow SemVer and treat database migrations and
security-relevant behavior as compatibility contracts. Do not auto-enable
reconciliation, replay or provider side effects in minor releases.

## Out-of-scope promises

Flowguard does not provide exactly-once delivery across independently failing
systems. It cannot verify a provider signature or two-person approval without
application-specific credentials and identity systems. It does not replace
Telescope, Horizon, Pulse or general tracing services.
