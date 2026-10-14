# v1.0.0 release criteria

**Current status: developer preview. No stable release has been authorized.**
Passing automated tests is necessary but not sufficient for a library that
coordinates business effects across independent systems. Flowguard never
guarantees exactly-once external side effects.

## Automated evidence already available

- [x] Operation identity, claim ownership, fail-closed unknown outcomes and
      explicit reconciliation classification tests.
- [x] Durable inbox and transactional outbox handoff with deduplication,
      concurrent claims and ambiguous provider acknowledgment fixtures.
- [x] Fenced, expiring outbox leases; MySQL/PostgreSQL `SKIP LOCKED` and
      tests with two independent SQL connections.
- [x] A bounded, supervised-worker-compatible publishing contract and
      read-only operational inspection commands.
- [x] Laravel 12/13, PHP 8.3/8.4/8.5, MySQL 8 and PostgreSQL 16 CI matrix,
      static analysis, formatting and production dependency audit.
- [x] Standalone Laravel application discovery/migrations on SQLite,
      MySQL and PostgreSQL.
- [x] Independent process crash tests and a ten-process outbox contention
      baseline on disposable CI databases.
- [x] Native MySQL/PostgreSQL logical backup and restore **fixtures** for
      selected outbox and reconciliation records.
- [x] Populated schema upgrade rehearsal and destructive rollback protection.
- [x] A draft public API contract, runbook, threat model and retention policy.

## Mandatory blockers before v1.0.0

- [ ] **Independent human security sign-off** (see
      [review handoff](INDEPENDENT_SECURITY_REVIEW.md)) on identity and tenant
      authorization, provider signature verification, secrets, approval policy
      and threat model in a real host application.
- [ ] **Real staging integration with at least one external provider**
      (see [pilot protocol](PROVIDER_STAGING_PILOT.md)):
      inject duplicate events, timeout ambiguity, remote success before local
      acknowledgement, process death, and reconciliation/retry.
- [ ] **Repeated, representative 100/500-worker stress tests** on MySQL and
      PostgreSQL, with measured lock waits, deadlocks, CPU/memory, error rates,
      p95/p99, database connection ceilings and declared acceptance thresholds.
      Three disposable CI runs per engine/workload now pass with explicit
      correctness and latency thresholds (see [measured results](STABLE_READINESS.md)).
      This synthetic workload does not establish representative host capacity.
- [ ] **Full application backup/restore** on a nonempty MySQL/PostgreSQL
      staging copy, with foreign-key consistency and resumed-worker safety;
      isolated table restore fixtures do not close this requirement.
- [ ] **Retention/privacy operational approval**, including replay horizons,
      legal holds and data subject requests. Automatic pruning remains disabled.
- [ ] **Independent adopter review** of the PHP contract and migration
      compatibility, then a versioned supported API and final changelog.
- [ ] **Maintainer release approval**, Packagist publication, provenance,
      GitHub release notes and a verified tag after all gates are green.

The authoritative checklist is [GitHub issue #5](https://github.com/mykholy/laravel-flowguard/issues/5).
Update the issue only with links to reproducible evidence. Do not check a
human/provider acceptance gate merely because a simulated test passes.

## Semantic versioning commitments

After a stable release, public PHP signatures, named parameters, storage
schemas, idempotency scopes and retry semantics are compatibility contracts.
Breaking changes require a major version with a migration plan. Minor releases
must never silently enable a new external effect or unsafe replay. Security
hardening requires a documented behavior and upgrade review.

## Unsupported promises

Flowguard is not an authentication provider, an approval identity system,
a gateway webhook signature verifier, a full queue engine, or a substitute for
Telescope, Horizon or observability. Consumers must deduplicate external
delivery independently and reconcile unknown outcomes with read-only provider
checks.
