# Host regression and security acceptance

This checklist supplements the package's existing tests and does not assert that any external host has passed acceptance.

## Host acceptance and independent review

Keep #41 and #42 open until an independent human security reviewer and an external staging adopter provide evidence. Verify tenant isolation, manual reconciliation authorization, signed webhooks, provider idempotency or read-only reconciliation, fenced outbox leases, kill/restart recovery and restored database state. A library release is not a production payment certification.

## Verification record

Record the exact package tag and source SHA, PHP and Laravel versions, database driver/version, test command, sanitized pass/fail output, and relevant host commit. Do not publish secrets or real customer data.

## Laravel framework security baseline (October 2026)

For any host using Laravel's built-in token-based API guard, review [GHSA-f9x3-7vq9-cph6](https://github.com/laravel/framework/security/advisories/GHSA-f9x3-7vq9-cph6). The advisory identifies patched framework versions **12.68.0+** and **13.29.0+**. Check the host's resolved Composer lockfile and guard configuration; this is not evidence that this package itself contains the vulnerability.

## Completion policy

- Add executable regression tests and link passing CI evidence before claiming code remediation.
- Distinguish package-level behavior from host-owned authentication, authorization, external provider and deployment responsibilities.
- Do not claim independent security approval or production readiness without independently supplied evidence.
