# Independent FlowGuard security review report — TEMPLATE (NOT APPROVED)

**Status:** `NOT REVIEWED` until a genuine independent assessor completes the report.
Never publish real provider credentials, exploit payloads, live customer IDs or
sensitive infrastructure identifiers in public Issues. Report vulnerabilities
privately under [SECURITY.md](../../SECURITY.md).

## Assessment identity

| Field | Reviewer-supplied value |
| --- | --- |
| Reviewed immutable release/tag and full commit SHA | NOT PROVIDED |
| Reviewer's independent identity / organization | NOT PROVIDED |
| Review date and evidence collection range | NOT PROVIDED |
| Laravel/PHP versions and SQL engines | NOT PROVIDED |
| Provider SDK / adapter and configured host release | NOT PROVIDED |
| Reviewer conflict-of-interest statement | NOT PROVIDED |
| Review scope / exclusions | NOT PROVIDED |

## Verification matrix

For each item record **PASS / FAIL / NOT TESTED**, sanitized evidence file
or reproducible test ID, affected host code revision, and remediation owner.

| Boundary | Result | Evidence reference | Remediation owner |
| --- | --- | --- | --- |
| Server-derived tenant scope; cross-tenant operation/inbox/evidence/outbox access | NOT TESTED | — | — |
| Signed webhook timestamps, replay windows, key rotation and origin verification | NOT TESTED | — | — |
| Claim and lease token secrecy, expiry and unauthorized reuse | NOT TESTED | — | — |
| Idempotency key collision and canonical payload identity under concurrency | NOT TESTED | — | — |
| Lost provider acknowledgement / unknown outcome; read-only recovery | NOT TESTED | — | — |
| Evidence freshness, conflicting evidence and authorization for resolution | NOT TESTED | — | — |
| Two independently authenticated approvals; denial on role overlap | NOT TESTED | — | — |
| MySQL and PostgreSQL lock contention, deadlock and migration rollback guards | NOT TESTED | — | — |
| Restoring nonempty application database incl. inbox/outbox/audit references | NOT TESTED | — | — |
| Redaction, logging, retention and sensitive reference handling | NOT TESTED | — | — |

## Findings and remediation

| Finding ID | Severity | Reproducer and sanitized evidence | Fix commit | Retest result |
| --- | --- | --- | --- | --- |
| — | — | — | — | NOT TESTED |

## Independent sign-off

- [ ] The report names every excluded/unperformed check, not merely successful tests.
- [ ] All critical/high findings have verified remediation with independent retests.
- [ ] The reviewer inspected the final **same** code and configuration accepted for deployment.
- [ ] The reviewer has recorded their independent identity and a signed date.
- [ ] Host owner separately approved provider credentials and authorization policies.

**Decision:** PENDING — do not describe this template as a security audit.

Relevant tasks: [independent review #41](https://github.com/mykholy/laravel-flowguard/issues/41),
[host acceptance #5](https://github.com/mykholy/laravel-flowguard/issues/5).
