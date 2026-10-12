# Changelog

All notable released changes will be documented here, following
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
The project has **no stable release** yet.

## Unreleased

### Added
- Durable scoped operation claims and explicit unknown-outcome handling.
- Reconciliation inspection, evidence journal and opt-in manual classification.
- Transactional inbox/outbox persistence and identity conflict detection.
- Outbox reservations with fencing, expiry, retry limits and publisher contract.
- Installation diagnostics and worker-failure tests (pending merge).
- Explicit Laravel 12/13, PHP 8.3/8.4 and multi-database CI coverage.

### Security and correctness
- Require authorization policies for sensitive reconciliation operations.
- Reject ambiguous or conflicting provider observations.
- Refuse external publishing inside an open database transaction.
- Preserve publish failures when lease-release reporting also fails.
- Distinguish unique-key conflicts from unrelated database write failures.

No unreleased feature should be presented as a production guarantee.
