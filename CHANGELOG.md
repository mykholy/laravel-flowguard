# Changelog

This project follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
## v1.0.1 — 2026-10-14

- Correct the repository-only Stripe webhook demo to exclude the mutable
  `pending_webhooks` delivery counter from its deduplication fingerprint after
  signature verification. Retries with unchanged event data remain duplicates;
  changed payment data still conflicts.
- Add a regression that reproduced HTTP 409 before the fix and requires one
  receipt/outbox signal after the counter changes. Core runtime, API, migrations
  and dependencies are unchanged.
- Existing disposable demo hosts with v1.0.0 Stripe receipts should retain their
  version for those receipts or create a fresh disposable host; the demo does not
  migrate or rewrite historical fingerprints. This does not affect core-package
  consumers or add a production provider adapter.

## v1.0.0 — 2026-10-14

First stable **library contract** release. The documented PHP signatures, named
parameters, storage schema and fail-closed semantics now follow SemVer.
Core runtime and migrations are unchanged from beta.3.

- Add a host-owned Stripe webhook demo using the official SDK, strict tenant /
  intent / amount binding, signed-event deduplication and negative contract tests.
- Add reproducible real Stripe process-death and webhook evidence; keep each
  tested boundary and unverified production condition explicit.
- Enable stable publication only after exact-commit compatibility, archive,
  demo and restore checks, then verify Packagist and a fresh public installation.
- Publish the supported API, security support policy, upgrade procedure and
  immutable artifact/checksum workflow.
- Revise release policy to distinguish library compatibility from per-host
  production certification. Independent human audit/adopter approval and
  representative production staging remain uncompleted, not passed or waived
  as evidence. See `docs/RELEASE_CRITERIA.md` for the explicit scope and limits.

## v0.1.0-beta.3 — 2026-10-14

### Fixed
- The optional Stripe demo now sends `allowed_payment_method_types` and pins
  `Stripe-Version: 2026-09-30.endive`; the removed legacy parameter caused HTTP
  400 on a real sandbox account. Core package APIs and schemas are unchanged.

### Verified
- Real Stripe HTTPS execution: two USD 5.00 test payments, one observed captured
  charge per intent, application acknowledgement discard, and GET-only recovery
  after an injected local read failure. No live-mode charges occurred.
- The failed pre-fix creation remained unknown without confirmation/replay.
  Evidence and precise boundaries are in
  `docs/evidence/stripe-sandbox-2026-10-14.md`; full external acceptance and
  independent human review remain open.

## v0.1.0-beta.2 — 2026-10-14

### Added
- Runnable isolated Laravel checkout host with tenant-bound APIs, synthetic
  payments, signed webhooks, durable approvals, independent HTTP timeouts and
  process-exit tests across Laravel 12/13 and three database engines.
- Full-host native MySQL/PostgreSQL backup/restore drills preserving domain
  rows, encrypted provider references, audit relationships and safe recovery.
- Optional test-key-only Stripe sandbox pilot with encrypted intent storage,
  one confirmation, controlled application acknowledgement loss, GET-only
  resume and sanitized evidence. Offline contracts are not external acceptance.

### Fixed
- MySQL outbox reservation now locks candidates by unique primary key instead
  of locking unrelated records during an ordered range scan. Bounded collision
  probes refresh the transaction before re-polling under heavy contention.
- MySQL candidate selection is best effort within a bounded oldest-message
  window; strict FIFO is not promised and null polls can occur during contention.

### Verified
- Three synchronized runs each at 100 and 500 workers on MySQL/PostgreSQL:
  3,600 unique deliveries, no failed workers or extra attempts, with a
  predefined 10-second p99 budget. See `docs/STABLE_READINESS.md`.

## v0.1.0-beta.1 — 2026-10-14

First versioned public beta. The features below form the beta baseline;
the independent review and real-provider gates remain open for v1.0.0.

### Added
- Tenant-scoped durable operation claims with canonical payload fingerprints
  and explicit unknown-provider-outcome handling.
- Read-only reconciliation inspection, authorized evidence journal and
  independently approved manual outcome classification.
- Atomic inbox receipts and outbox signals, bounded delivery attempts, fenced
  expiring leases and application-defined publisher contracts.
- An opt-in single-message Artisan publisher and read-only installation,
  stale-operation and exhausted-message diagnostics.
- Laravel 12/13, PHP 8.3/8.4/8.5, MySQL 8 and PostgreSQL 16 CI coverage, including
  independent-process failure injection and clean framework installation.
- Disposable SQL contention benchmarks and a separately opt-in 100/500-process
  test workflow; **the large workloads are not yet validated**.
- Native disposable MySQL/PostgreSQL backup/restore fixtures for selected
  outbox and reconciliation records; full application restoration is unproven.
- API compatibility draft, migration/retention guidance and release gates.

### Changed
- Database reservation uses `FOR UPDATE SKIP LOCKED` on MySQL and PostgreSQL
  to avoid head-of-line waits on locked rows.
- Migration rollback fails closed if any Flowguard table contains records.
- Installation diagnostics validate unique indexes required for idempotency,
  inbox deduplication, outbox identity and one-time resolution.

### Security
- Owner claims and leases redact debugging output and reject serialization.
- External dispatch is rejected inside an active business transaction.
- Duplicate database claims catch only unique-constraint violations.
- Cross-tenant authorization regression fixtures deny unauthorized evidence
  and approval operations; the host must implement real authentication.

## Release conditions

See [release criteria](docs/RELEASE_CRITERIA.md). No full provider staging
integration, independent human security sign-off or production representative
capacity proof has been completed. No exactly-once external effects are claimed.

- Evidence DTO discards raw provider identifiers after validating their shape.

### Release preparation
- Composer archives exclude development-only files and are validated before
  installation across the clean Laravel/database matrix; a ZIP and checksum
  are retained as build evidence.
- Contention workloads wait for every PHP process and SQL connection to become
  ready before simultaneous delivery, and retain repeated-run resource and
  database-counter evidence in the manual stress workflow.
