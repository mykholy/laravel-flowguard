# v1.0.0 library release scope and acceptance

**Scope: a stable PHP and storage compatibility contract for Flowguard's library primitives.**
A stable version does not certify a host application's payments, authorization,
provider adapters, retention obligations or production capacity. No independent
human security audit or independent adopter approval has been completed.

## Policy revision from the beta checklist

The earlier beta policy required independent human reviews, representative
customer staging and privacy-owner approval before any stable package tag.
That combined package compatibility with deployment approval for an unspecified
host. The maintainer has requested completion and publication of the package;
this revision defines the bounded library release that can be verified here.
It does **not** claim the earlier external tasks passed. Their status remains
visible in issue #5 and the review/pilot documents. Production owners must
complete the relevant application acceptance work before deployment.

## Required package release evidence

- [x] Documented public signatures, PHP named parameters, storage schema and
      behavioral compatibility rules in [PUBLIC_API.md](PUBLIC_API.md).
- [x] Fail-closed operation ownership, immutable payload identity, tenant-scoped
      deduplication and explicit unknown-outcome handling regression tests.
- [x] Transactional inbox/outbox recording, independent worker contention,
      expiring fenced leases and duplicate-delivery/crash regression tests.
- [x] Laravel 12/13 × PHP 8.3/8.4/8.5 quality matrix, static analysis,
      formatting and production dependency audit.
- [x] Fresh Laravel applications install the actual extracted Composer archive
      on SQLite, MySQL 8 and PostgreSQL 16, including discovery/migrations/doctor.
- [x] Populated forward-migration rehearsal and rejection of destructive
      rollback; native package and full nonempty demo-host restoration on both
      MySQL and PostgreSQL with resumed workers and foreign-key checks.
- [x] Repeated synthetic 100/500-worker tests with declared correctness and
      latency thresholds. [Measured results](STABLE_READINESS.md) describe the
      workload, initial failures, correction and capacity limits.
- [x] Runnable host integration with server-derived tenants, signed-event
      contracts, host approvals, independent process faults and HTTP timeout.
- [x] Bounded actual Stripe sandbox HTTPS evidence for read-only reconciliation
      and a killed worker. [Provider evidence](PROVIDER_STAGING_PILOT.md) records
      successful and failed scenarios without claiming full provider acceptance.
- [x] Supported-version security policy, no automatic pruning, preserved
      idempotency identities, operational diagnostics and upgrade guidance.
- [x] v1.0.1 publication: all four required workflow groups must pass on the exact
      release commit; build and validate the runtime archive and checksum.
- [x] v1.0.1 after publication: immutable tag, GitHub release, exact Packagist source
      indexing and a fresh public Laravel install must be verified.

The last two checks passed for v1.0.1 in the [publication record](STABLE_READINESS.md)
and remain mandatory for each subsequent release. Source checkboxes above describe existing behavior/evidence; they do
not substitute for those fresh exact-commit checks.

## Application acceptance still required

| Requirement | Current evidence / limit | Responsible owner |
| --- | --- | --- |
| Independent security review | Not completed; [handoff](INDEPENDENT_SECURITY_REVIEW.md), issue #41 | Host owner and independent reviewer |
| Provider-specific production validation | Partial real Stripe sandbox evidence; no live payment or full gateway certification | Host owner / gateway integrator |
| Representative capacity | Synthetic measured load only; no universal throughput or latency SLA | Deployment operator |
| Representative backup/restore | Nonempty demo host tested; customer's full staging clone unverified | Application / database operator |
| Retention and privacy | Preserve-all default, pruning disabled; legal holds and replay horizons need local approval | Application / privacy owner |
| Independent adoption review | Not completed, requested in issue #42 | Independent adopter |

These are not checked off by a stable tag, automated tests, configured demo
reviewer principals or this implementation review.

## Supported promises

1.x follows SemVer for the documented public PHP signatures, named parameters,
persisted storage and retry/authorization semantics. Breaking changes require
a major version and migration guidance. No minor release may silently enable a
new external effect or unsafe replay. Existing published tags are immutable.

Flowguard provides no exactly-once external effect guarantee, authentication
system, bundled payment gateway, automatic processing-claim recovery, automatic
pruning or production certification. A process can die after a remote effect
while its local claim remains `processing`; preserve that identity, investigate
with the provider and never infer safe replay from its age. Outbox consumers
must deduplicate downstream delivery independently.
