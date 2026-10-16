# Independent security review handoff

Status: **requested but not completed**. This document defines evidence an
independent reviewer should supply for a production host security assessment.
The v1.0.0 library compatibility release does not claim that assessment passed;
see [the revised release scope](RELEASE_CRITERIA.md).

## Review scope

Review the latest release-candidate commit, not an arbitrary moving branch.
Record its SHA, Laravel/PHP/database combinations, Composer audit result,
provider-adapter version and configuration assumptions.

Review these boundaries with representative **host application** implementations:

1. HTTP authentication and signed webhook timestamp/replay validation.
2. Server-derived tenant scope; cross-tenant receipt, evidence, lease,
   resolver and publisher access must be denied.
3. Owner claim and lease tokens: unpredictability, hashing, logging,
   serialization, process crashes and lease-expiration fencing.
4. Idempotency key collision behavior, mutable payload changes, body-size
   limits, SQL unique indexes and deletion/retention hazards.
5. Provider-success/local-timeout ambiguity, bounded retries, incorrect
   provider error classifications, and idempotent downstream consumers.
6. Manual reconciliation: provider evidence source, freshness, contradictory
   observations, two distinct authenticated approvers, revocation and audit
   immutability.
7. Transaction boundaries, nested transaction/savepoint behavior, query
   failures, isolation-level behavior and deadlocks on MySQL/PostgreSQL.
8. Backup/restore consistency across application tables, outbox, inbox and
   reconciliation records; retention/privacy obligations.

## Reproduction baseline

    composer validate --strict
    composer audit --no-dev
    composer test
    composer analyse
    composer format-check
    php tests/smoke.php

The repository CI additionally runs independent-process MySQL and PostgreSQL
tests, clean Laravel applications, and native restore drills. A green run
supports but does **not** substitute for the application-specific review.

## Required findings

- Document each finding with severity, likelihood, affected actor/tenant,
  precise reproduction, expected/observed behavior and a testable remediation.
- Report all scope exclusions explicitly. A README warning is not a mitigation
  if host integration contradicts it.
- Provide the reviewed commit SHA, date, reviewer identity, conflict-of-interest
  declaration and approval or rejection decision.
- Recheck every material fix against its original reproduction and regression
  tests before signing off.

Never send live payment credentials, card data or raw customer webhook bodies
in public GitHub issues or review artifacts. Use the private reporting process
described in SECURITY.md.

For submission, copy [the independent review evidence template](templates/INDEPENDENT_REVIEW_REPORT.md) into the reviewer's own report. Require a dated, identified independent reviewer and commit-bound checklist; do **not** fill a template with implied approvals. Upload findings securely using `SECURITY.md` rather than exposing exploits publicly.

This document is a reviewer brief, **not an independent security attestation**.
