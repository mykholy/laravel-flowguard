# RFC-001: Durable idempotent claims

**Status:** Design implemented; supported v1 contracts are defined in
[PUBLIC_API.md](PUBLIC_API.md). No independent production security audit is claimed.

## Problem

A network timeout from a payment API does not tell a Laravel worker if money changed hands.
Retrying a Queue job may repeat the transaction. Telescope/Horizon can display job and
exception data but they do not arbitrate business-level idempotency or prove outcome.

## First vertical slice

- `begin(scope, key, payload)` persists a unique operation, scoped by business action.
- Database uniqueness `(scope, SHA256(key))` chooses exactly one initial caller.
- A random 256-bit ownership secret is issued only to the inserting caller, stored as SHA-256.
- Existing callers get a **read-only** snapshot, including previously completed result metadata.
- Reusing the same scope/key with a different canonical payload returns an explicit conflict.
- Only the owner can move a processing operation to `completed` or `requires_reconciliation`.
- Transitions are database compare-and-swap updates; no blind restart or automatic claiming.
- Fingerprint inputs must be JSON-native scalars/arrays and are capped at 64 KiB (canonical JSON).
- Results are limited to 16 KiB. No raw request body, payment tokens or secrets are stored by default.

## State machine

```text
              begin (unique insert)
                      |
                      v
                 processing
                 /        \
          complete        ambiguous / failed external outcome
            /                         \
      completed                  requires_reconciliation
         [terminal]                   [terminal]
```

`processing` is *not* evidence of payment success or failure. If the process dies,
`processing` remains durable and later callers are denied ownership. The operator
must check the external provider before any controlled remediation.

## Non-goals and explicit limitations

- Does **not** make an outbound network request atomic with a local DB transaction.
- Does **not** guarantee exactly-once effects at third-party providers.
- Does **not** automatically retry/reclaim stuck operations or reconcile them.
- Does **not** currently implement inbox/outbox, Saga execution, dashboards or HTTP middleware.
- Does **not** promise linearizability across multi-primary/multi-region database replication.
- SHA-256 key hashes do NOT protect low-entropy identifiers from offline guessing.
- Redis locks are intentionally not required; the database unique constraint is the source of truth.
- Do not call `complete` until business side effects you intend to acknowledge are durably committed.
- DB outages should propagate exceptions, never fall back to unsafe execution.

## Safety invariants to prove in integration tests

1. One `(scope, key)` row across multiple contenders.
2. Only the winner possesses the claim token.
3. Mismatched payloads are rejected before external effects.
4. A claim can finalize once; competing finalizations cannot both succeed.
5. A crashed worker leaves no auto-reclaim route.
6. A DB write failure must never grant an operation claim.

## Next milestones

- MySQL/PostgreSQL concurrent-process test suite and fault injection.
- Expand read-only stale claim CLI with pagination and add immutable audit trail.
- Provider-specific reconciliation contract and manual recovery approvals.
- Outbox/inbox and integration with popular webhook receiver packages.
- Public API stabilization before first tagged release.
