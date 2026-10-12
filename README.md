# Laravel Flowguard

**Reliable business operations for Laravel — experimental prototype.**

> **Status: pre-alpha. Do not use to capture real payments.** No release has been
> published to Packagist and the integration test suite has not yet been executed
> in the authoring environment. This repository is a source starter, not a
> production guarantee.

Flowguard solves a different problem from Telescope, Horizon and Pulse:
**who is allowed to execute a business operation, whether the outcome is known,
and whether replay could be unsafe**. It does not replace tracing/monitoring.

## What works in this first vertical slice

- Durable, scoped database idempotency claims (MySQL/PostgreSQL/SQLite syntax intended).
- Canonical JSON request fingerprint; conflict on key reuse with different data.
- Atomic owner-only transitions: `processing -> completed | requires_reconciliation`.
- Duplicate callers never receive a claim; completed callers receive safe result metadata.
- After a crash, claims stay `processing` and are **never** automatically reissued.
- No external provider API calls or automatic recovery.

## Requirements and local development

PHP 8.3+, Laravel 12/13, a transactional database, Composer 2. Laravel 12 & 13
compatibility is *targeted* and is not fully tested yet.

Once published on Packagist, the planned install command is
`composer require mykholy/laravel-flowguard`. **Until then, use a local path
repository or a GitHub VCS repository**, not that Packagist command.

```bash
composer install
composer test
composer analyse
composer format-check
php tests/smoke.php
```

The service provider is registered using Laravel package discovery. Its migration is
loaded automatically and is applied by your normal `php artisan migrate` command.
To customize the connection/table, publish the config:

```bash
php artisan vendor:publish --tag=flowguard-config
php artisan migrate
php artisan flowguard:stale --minutes=15 --limit=50
```

`flowguard:stale` is read-only and shows old processing claims for investigation.
It never authorizes a replay or infers the external provider outcome.

Configure a durable transactional database with `FLOWGUARD_DB_CONNECTION`; defaults
to your Laravel application's default connection. Do not change connection/table
config after creating data without an intentional migration.

## Example: payment capture

```php
use Mykholy\Flowguard\Flowguard;

$guard = app(Flowguard::class);
$operation = $guard->begin(
    scope: 'payments.capture',
    idempotencyKey: $merchantId . ':' . $orderId,
    payload: ['order_id' => $orderId, 'amount_minor' => $amountMinor, 'currency' => 'EGP'],
);

if (! $operation->acquired()) {
    // NEVER call the gateway from this branch.
    // Inspect $operation->operation->status and return an appropriate
    // API response (completed / already processing / manual reconciliation).
    return;
}

$claim = $operation->claim();
try {
    // IMPORTANT: the provider must support its own idempotency key when available.
    $payment = $gateway->capture($orderId, $amountMinor, idempotencyKey: $merchantId . ':' . $orderId);

    // Commit your local business writes durably before acknowledging completion.
    $guard->complete($claim, ['provider_reference' => $payment->reference]);
} catch (\Throwable $e) {
    // A timeout may mean the charge was successful. Fail closed.
    // This example assumes no other operation finalization occurred yet.
    $guard->requireReconciliation($claim, 'capture_outcome_unknown');
    throw $e;
}
```

The caller must ensure errors before dispatch and known vs unknown outcomes are handled
according to provider semantics. A provider can charge successfully even when local
completion fails. Do not treat failed `complete()` as grounds for retrying the charge.
See [RFC-001](docs/RFC-001-CORE.md) and [threat model](docs/THREAT_MODEL.md).

## Experimental reconciliation inspection (in development)

A provider-specific `Reconciler` may **read** the external state of an operation
explicitly marked `requires_reconciliation`. `ReconciliationInspector` returns
bounded, typed `ReconciliationEvidence` and cannot finalize or replay anything.
The caller must authenticate and authorize the inspection and use a read-only
provider lookup. This is intentionally **not** an automated recovery system.

See [RFC-002](docs/RFC-002-READ_ONLY.md) for the API and limits.

## Opt-in durable reconciliation evidence journal (experimental)

A provider observation is not permission to retry or complete a payment. Flowguard
can now persist a **reduced, authorized audit observation** on the same database
connection, while leaving the operation in `requires_reconciliation`.

- Requires an application-supplied `EvidenceAuthorization` policy. No default
  allow-all binding exists. Your policy must authenticate the operator and enforce
  tenant ownership; an actor string alone is not authentication.
- Rechecks the database row under a transaction lock before inserting evidence.
- Stores typed observation metadata but **not** raw provider references, request
  payloads, headers or gateway responses.
- Does **not** implement replay, capture, refund, or finalization.

See [RFC-002 evidence journal](docs/RFC-002-EVIDENCE-JOURNAL.md). This is still
pre-alpha and needs fault-injection and independent security review.

## Opt-in manual outcome classification (experimental)

The optional `DatabaseManualResolver` can classify a previously unknown
external outcome as `reconciled_success` or `reconciled_failure`. These statuses
are **bookkeeping only**, and are NOT equivalent to completed application-side
writes. No payment, refund, capture, replay or business record mutation occurs.

It rejects stale, conflicting, inconclusive or superseded evidence; requires
**two independently authenticated people and a bound approval verified by an
application-supplied `ResolutionAuthorization` policy**; and atomically writes
a decision record with the status change. The package DOES NOT implement login
or collecting real two-person approvals. An actor-reference string alone is
not proof of identity or approval. Do not enable this in production without
an independently reviewed host-app policy.

See [manual resolution RFC](docs/RFC-002-MANUAL-RESOLUTION.md).

## Correctness and versioning policy

- Never repeat external effects when an existing operation is processing or unknown.
- No TTL-based automatic lock reclaim. Recovery requires reconciliation.
- Only application-verified, authorized, validated requests reach the engine.
- Do not store raw PII, gateway secrets, or complete response bodies in result metadata.
- No SLA and no semantic version stability in this pre-alpha starter.
- Initial independent-process tests run on disposable MySQL/PostgreSQL databases in CI; expanded crash/fault testing remains pending.

## Contribution

See [roadmap](docs/ROADMAP.md). Contributions should include a failing regression test,
public failure scenario and rationale for any API change. Small, reviewable PRs preferred.

MIT © 2026 Ahmed El-Kholy.
