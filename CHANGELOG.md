# Changelog

This project follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
**No stable or prerelease GitHub tag has been published.** The entries below
describe the current development branch and do not imply release guarantees.

## Unreleased

### Added
- Tenant-scoped durable operation claims with canonical payload fingerprints
  and explicit unknown-provider-outcome handling.
- Read-only reconciliation inspection, authorized evidence journal and
  independently approved manual outcome classification.
- Atomic inbox receipts and outbox signals, bounded delivery attempts, fenced
  expiring leases and application-defined publisher contracts.
- An opt-in single-message Artisan publisher and read-only installation,
  stale-operation and exhausted-message diagnostics.
- Laravel 12/13, PHP 8.3/8.4, MySQL 8 and PostgreSQL 16 CI coverage, including
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
