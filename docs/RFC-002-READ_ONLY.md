# RFC-002: Read-only provider reconciliation inspection

**Status: supported v1 read-only contract; no recovery/finalization authority.**

A timeout is not proof of failure; a provider may have committed the payment while
our worker lost the response. `ReconciliationInspector` accepts a stored
`requires_reconciliation` snapshot and invokes an application-supplied `Reconciler`
that **may query status but must never create a payment, capture, refund, or replay**.

The result is immutable `ReconciliationEvidence` with:

- `outcome`: confirmed success / confirmed failure / inconclusive;
- `source`: non-sensitive machine code identifying the provider or adapter;
- `evidenceCode`: non-sensitive machine code describing what was observed;
- `observedAt`: date/time of provider observation;
- optional `providerReference`: short, non-secret reference identifier.

```php
$inspector = new ReconciliationInspector($yourReadOnlyProviderAdapter);
$evidence = $inspector->inspect($snapshot);

// Present to a vetted operator for review; do not automatically replay.
```

## Safety boundary

Evidence is **not authenticated/verified by Flowguard**. The adapter author must
validate provider signatures/authentication and avoid creating side effects.
The snapshot must be fetched under the application's existing authorization and
tenant isolation. The inspector does not load it from the database, cannot
mark it completed, and does not trigger any queued jobs. Existing `Flowguard`
methods remain unchanged. The adapter's provider calls, timeouts, and data
classification are explicitly the application's responsibility.

## Follow-up blockers before any manual-resolution API

1. Append-only evidence records with durable correlation and audit history.
2. Documented approval policy and explicit application authorization.
3. Compare-and-swap transition with stale-evidence rejection and concurrent reviewer tests.
4. No blind replay; any subsequent retry requires provider-specific idempotency guarantees.
5. MySQL/PostgreSQL integration tests for resolution, conflicting evidence, and DB failures.
