# FlowGuard: production integration acceptance checklist

FlowGuard gives Laravel durable primitives for **operation claims, reconciliation, inbox receipts and outbox delivery**. A stable library release does **not** certify your application, payment adapter or production deployment. Use this checklist before an external pilot.

## Before an integration

| Boundary | What your application must establish |
| --- | --- |
| Tenant / merchant identity | Resolve it from authenticated, server-controlled state; never trust request-body scope alone |
| Idempotency | Generate one stable key for one intended business operation; reuse that same key at the external provider if supported |
| Payload consistency | Canonicalize meaningful business inputs and reject changing payloads against an existing key |
| Unknown provider outcome | Do **not** blindly retry capture/transfer; reconcile by provider reference or a documented read-only lookup |
| Transactional outbox | Commit business state and event record on the **same** durable database connection |
| At-least-once handoff | Make destination consumers deduplicate by event identity; do not claim exactly-once effects |
| Worker lifecycle | Run your publisher under a process supervisor; choose transport timeout below lease timeout |
| Security and data minimization | Record opaque references only; never persist provider tokens, payment-card data or raw request bodies |
| Operational ownership | Assign a human for stale/unknown outcomes, alerts, retention and restore drills |

## Minimal safe acceptance sequence

1. Read [getting started](GETTING_STARTED.md) and [public API](PUBLIC_API.md) before wiring a provider.
2. In an **isolated staging environment**, run `php artisan flowguard:doctor` and ensure the DB connection is correct.
3. Run the [checkout demo](../examples/checkout-demo/README.md) using a fake provider. Verify double-click, unknown provider result, repeated webhook and outbox redelivery paths.
4. Run [concurrency tests](CONCURRENCY_TESTING.md) and the [native restore drill](NATIVE_RESTORE_DRILL.md) on throwaway data.
5. Integrate a single bounded provider-sandbox workflow. Avoid live payment captures until its callbacks, timeout semantics and reconciliation evidence have been reviewed.
6. Review [tenant boundaries](TENANT_BOUNDARIES.md), [threat model](THREAT_MODEL.md) and [operations](OPERATIONS.md) with the engineer responsible for incidents.
7. Record the exact app commit, package version, Laravel/PHP/DB versions and test evidence before signing off a pilot.

## Claims you must not make

- “FlowGuard makes payments exactly-once.” It cannot eliminate unknown provider outcomes or guarantee a downstream exactly-once effect.
- “We can retry every timed-out charge.” A timed-out call may have succeeded; reconciliation is required.
- “Stable package = PCI, security or production certification.” External adapter/host reviews remain separate.

## What makes a strong bug report

Attach sanitized reproduction steps; idempotency identity and expected status **without secrets**; the relevant DB driver, migrations and package tag; and a failing regression test. Use [the maintainer release checklist](RELEASE_CRITERIA.md) and [independent reviewer template](templates/INDEPENDENT_REVIEW_REPORT.md) when producing formal evidence.
