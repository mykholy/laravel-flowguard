# Security and correctness notes

## Assets

Business operation identities, non-replayable claim capabilities, finalization status,
provider reference identifiers, and database availability.

## Boundaries

`Flowguard::begin` takes trusted application-level scope, idempotency key, and semantic
payload. The caller MUST authenticate the incoming webhook/request independently and
validate the request before calling it. Flowguard is not a webhook signature verifier.

## Threats and mitigations

| Threat | Mitigation / limitation |
| --- | --- |
| Duplicate worker delivery | Durable unique DB index, one claim per key |
| Same key, changed request | Deterministic payload hash and conflict |
| Spoofed finalization | 256-bit claim secret, stored only as SHA-256; compare-and-swap |
| Database outage | Exception propagates; no permissive execution fallback |
| Process dies after provider effect | Stays processing; no unsafe automatic retry |
| Leaking sensitive payload | Store only payload hash; results must be safe metadata |
| Key with low entropy | SHA-256 is not encryption; do not treat as anonymization |
| Reused raw idempotency key across providers | Distinct scopes and provider idempotency keys required |
| Claim lost after crash | Reconciliation needed; manual workflow TBD |
| Replay/recovery mistakes | Not exposed in prototype |

## Explicit gaps

No retention/purge policy, role-based operator UI, per-tenant authorization, immutable
audit history, multi-process DB race testing, provider reconciliation adapters, or
at-rest encryption for result metadata yet. These must be addressed prior to declaring
production readiness. Configuration and database credentials are trusted deployment data.

Report suspected vulnerabilities privately; do not attach production payloads or secrets to public issues.

## Owner capability handling

`Claim` and `OutboxLease` contain short-lived secret capabilities. Their
diagnostic views redact those secrets, and their serialization and restoration
hooks reject queue/session transport. Keep these objects **in memory and within
the current request or worker execution**, not in queued jobs, cache values or
session payloads. Pass only non-sensitive IDs across process boundaries and
re-acquire ownership through the documented workflow.

These guards do not protect against PHP reflection, unrestricted memory dumps,
explicit property extraction, `var_export`, or logging the raw constructor
arguments. Never pass entire capabilities to application loggers or error
reporters. An externally compromised process can still read its own memory.
