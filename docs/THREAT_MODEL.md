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
| Claim lost after crash | Reconciliation requires application-authorized evidence and explicit review |
| Replay/recovery mistakes | No automatic replay of unknown provider effects; outbox delivery remains at-least-once |

## Explicit gaps

There is no built-in authentication, tenant membership directory, role-based
operator UI, provider verification adapter, retention/purge policy, or at-rest
encryption for result metadata. The host must provide authenticated, tenant-scoped
`EvidenceAuthorization` and `ResolutionAuthorization` implementations. This
repository includes multi-process database race tests and a sample two-tenant
policy fixture; neither replaces testing the real host's access-control model.
Provider staging integration and an independent security review remain release
gates. Configuration and database credentials are trusted deployment data.

Report suspected vulnerabilities privately; do not attach production payloads or secrets to public issues.

## Multi-tenant host integration

The package treats scope as trusted application input, never as a substitute for
authenticated tenant membership. Tenant-qualified identifiers such as
`tenant.42:payments` are supported consistently by operations, inbox and outbox.
Hosts must derive scope from their authenticated server-side tenant context; do
not trust client-supplied tenant IDs or approval references. The example test
`TenantAuthorizationBoundaryTest` proves both cross-tenant evidence denial and
cross-tenant approval rejection using a host-owned approval table. This test
fixture is **not** an authentication provider or production policy.

The application policy must bind the independently authenticated initiator and
approver, target operation scope, selected evidence ID, confirmed outcome and
approval record to the **same tenant**, all within the resolution transaction.
Deny by default; do not call external APIs from authorization callbacks.

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

## Provider reference minimization

`ReconciliationEvidence` accepts an optional provider reference for validation,
but retains only `providerReferencePresent` (a boolean). The raw value is not
stored on the evidence object or in the evidence journal, so ordinary object
diagnostics and JSON output cannot disclose it. The caller remains responsible
for not logging constructor arguments or HTTP request bodies. Do not treat
SHA-256 hashes as anonymization of low-entropy identifiers.
