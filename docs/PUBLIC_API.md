# Draft public API and compatibility policy

**Status: draft for independent review. No stable release or SemVer
compatibility guarantee has been published yet.**

Flowguard coordinates business operations and transport identities. The public
PHP surface, database migrations and behavioral safety rules are a single
compatibility contract. A change that compiles successfully may still be
unsafe if it alters retry, transaction or authorization semantics.

## Consumer entry points

| Surface | Supported call | Contract |
| --- | --- | --- |
| Operation execution | `Flowguard::begin(scope, idempotencyKey, payload)` | Acquire or inspect a scoped operation; duplicates never get the owner claim |
| Operation finalization | `Flowguard::complete(claim, safeResultMetadata)` | Owner-only terminal success |
| Unknown provider outcome | `Flowguard::requireReconciliation(claim, reason)` | Fail closed; do not automatically repeat an external effect |
| Incoming event receipt | `DatabaseInboxRecorder::receive(scope, eventId, payload, topic, subjectReference)` | Requires a verified upstream event; deduplicates in the caller's tenant namespace |
| Business transaction handoff | `DatabaseOutboxRecorder::record(scope, eventId, topic, subjectReference)` | Must be called within an active transaction on the **same** SQL connection as the business write |
| Publishing | `OutboxPublisher::publish(OutboxMessage)` | Host-owned; must only return after durable provider acceptance |
| Reservation | `DatabaseOutboxLeaseStore::reserve(leaseSeconds)` | Expiring fenced lease; never publish inside an outer database transaction |
| Delivery completion | `acknowledge(lease)` / `release(lease, failureCode, retryAfterSeconds)` | Current token only, guarded by lease expiry |
| One-message orchestration | `OutboxDispatcher::dispatchOne()` and `flowguard:dispatch-once` | At-least-once delivery with explicit application publisher binding |
| Operator visibility | `flowguard:doctor`, `flowguard:stale`, `flowguard:outbox` | Read only; never authorize replay from age alone |

The PHP method names, parameter names, parameter types and return types for
core entry points, including the outbox recorder, evidence journal,
reconciliation inspector and manual resolver, are checked by
`PublicApiContractTest`. Consumer calls may
use PHP named arguments, so renaming a parameter is a compatibility change.

## Semantics that must remain stable

- Request payload fingerprints are produced from canonical JSON; map keys
  are ordered while list order is preserved.
  Fingerprinting rejects cyclic or excessively nested input before it can
  exhaust recursion: maximum 32 nested array levels, 32,768 visited values
  and 64 KiB of canonical JSON.
- Result metadata is limited to JSON-native scalars and arrays, at most
  32 levels deep and 16 KiB encoded. Objects including `JsonSerializable`
  are rejected before their serialization callbacks execute. Store bounded
  provider references, not raw API responses or credentials.
- Operation, inbox and outbox identities are tenant scoped, but Flowguard
  **does not authenticate tenants**. A host must derive and verify each scope.
- Operation claims and outbox lease capabilities must not be serialized or
  re-used by other callers or workers.
- Expired outbox leases may be re-delivered. Remote exactly-once effects are
  **not guaranteed**.
- A webhook or provider response that has an unknown result must not be
  retried as if it failed.
- Reconciliation reasons must be bounded ASCII machine codes, even for callers
  using `OperationStore` directly. Raw provider exception messages and HTTP
  response bodies are rejected at the persistence boundary.
- Manual resolution requires the host application's evidence and durable,
  independently authenticated two-actor approval policy.
- `ReconciliationEvidence` accepts `providerReference:` at construction but
  deliberately retains only `providerReferencePresent`, not the original
  identifier. Do not depend on reading the raw reference from this DTO.
- Existing database records cannot be removed by an automatic migration
  rollback. Use reviewed forward migrations and backup/restore procedures.

## Compatibility rules after a future v1.0.0 release

- MAJOR: public PHP signature or namespace changes, removal of supported
  events/options, incompatibility in persisted records, more permissive replay
  semantics, or schema changes requiring data migration.
- MINOR: backward-compatible opt-in capabilities, new commands or adapters.
  No previously disabled external side effect becomes automatic.
- PATCH: bug fixes and security hardening that preserve documented contracts.
  Security fixes with necessary behavioral changes must include a migration
  note and clear operator guidance.

Before any release: review all public signatures, update this document and
`CHANGELOG.md`, prove the Laravel/MySQL/PostgreSQL matrix, perform migration
rehearsals, and obtain explicit maintainer and independent security approval.
CI passing alone does not authorize a stable tag.

For upgrade/restore safety, see [operations](OPERATIONS.md),
[retention](RETENTION.md) and [release criteria](RELEASE_CRITERIA.md).
