# Laravel Flowguard

**Durable business-operation primitives for Laravel 12 and 13.**

> **Release status: developer preview, no stable tag yet.** The implementation
> passes an automated Laravel 12/13, PHP 8.3/8.4/8.5, MySQL 8 and PostgreSQL 16
> compatibility matrix. Passing CI does not establish real-provider safety.
> Do not use Flowguard to capture live payments until the
> [release criteria](docs/RELEASE_CRITERIA.md) are closed.

Flowguard solves a different problem from Telescope, Horizon and Pulse:
**who is allowed to execute a business operation, whether the outcome is known,
and whether replay could be unsafe**. It does not replace tracing/monitoring.

## Included primitives

- Scoped idempotency claims with immutable payload fingerprints and owner-only
  transitions, including an explicit unknown-outcome state.
- Read-only reconciliation inspection, append-only evidence and optional
  authorization-gated manual outcome classification.
- Durable inbox receipts and atomic outbox recording within business transactions.
- Outbox leasing, bounded retry, fenced acknowledgement and a transport-neutral
  publisher contract.
- Read-only diagnostics for storage readiness and stale operations.
- MySQL/PostgreSQL independent-process contention and crash-point tests.

The host application still owns gateway signature verification, tenant security,
publisher timeouts, remote idempotency and any real two-person approval workflow.
Flowguard does not guarantee exactly-once effects across external systems.

## Requirements and local development

PHP 8.3+, Laravel 12/13, a transactional database and Composer 2.
CI tests Laravel 12/13 on PHP 8.3/8.4/8.5, plus MySQL 8 and PostgreSQL 16.

The package is not yet published to Packagist. For development environments,
use Composer's GitHub VCS repository support:

```bash
composer config repositories.flowguard vcs https://github.com/mykholy/laravel-flowguard.git
composer require mykholy/laravel-flowguard:dev-main
php artisan migrate
php artisan flowguard:doctor
```

Do not use the development branch with real payments.

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
php artisan flowguard:doctor
php artisan flowguard:outbox --max-attempts=10 --limit=50
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
    // The gateway must support its own idempotency key where available.
    $payment = $gateway->capture($orderId, $amountMinor, idempotencyKey: $merchantId . ':' . $orderId);
} catch (\Throwable $e) {
    // An exception after dispatch may mean the provider already charged.
    // Use provider-specific semantics for errors known to occur before dispatch.
    $guard->requireReconciliation($claim, 'capture_outcome_unknown');
    throw $e;
}

// Do not catch errors from complete() as if the provider capture failed.
// Persist local business writes atomically where required before this call.
// If finalization fails after capture, investigate without repeating the charge.
$guard->complete($claim, ['provider_reference' => $payment->reference]);
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

See [RFC-002 evidence journal](docs/RFC-002-EVIDENCE-JOURNAL.md). This remains experimental; multi-database fault-injection tests exist, but an
independent security review and a real-provider pilot are release prerequisites.

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

## Transactional outbox recording (experimental)

`DatabaseOutboxRecorder` records a small, durable signal in the same database
transaction as a business change. It refuses to record outside an active
transaction. The caller must use the **same database connection** for both
writes and scope event identities by tenant. Duplicates fail through a database
unique constraint.

This increment **does not dispatch messages**. Recorded rows remain pending;
leasing and publishing are separate features. See the experimental reservation
API below; automatic dispatch is still not provided.
See [outbox recording design](docs/RFC-003-OUTBOX.md).

## Outbox reservation lifecycle (experimental)

`DatabaseOutboxLeaseStore` allows a worker to reserve one pending message,
acknowledge it after successful publishing, or release it with a bounded retry
delay and machine-readable error code. Each lease carries an opaque token;
expired or replaced leases cannot acknowledge delivery. Attempts are capped
(default: 10), leaving exhausted records for operator inspection.

Consumers **must** support duplicate delivery. A publisher may succeed while
its worker crashes before acknowledging the row. This is an at-least-once
foundation, not exactly-once delivery, and there is no bundled publisher or
background worker yet. See [lease contract](docs/RFC-003-LEASING.md).

## Correctness and versioning policy

- Never repeat external effects when an existing operation is processing or unknown.
- No TTL-based automatic lock reclaim. Recovery requires reconciliation.
- Only application-verified, authorized, validated requests reach the engine.
- Do not store raw PII, gateway secrets, or complete response bodies in result metadata.
- No SLA and no semantic version stability in this pre-alpha starter.
- Initial independent-process tests run on disposable MySQL/PostgreSQL databases in CI; expanded crash/fault testing remains pending.

## Contribution

See the [roadmap](docs/ROADMAP.md), [operations runbook](docs/OPERATIONS.md)
and [release criteria](docs/RELEASE_CRITERIA.md). Contributions should include a failing regression test,
public failure scenario and rationale for any API change. Small, reviewable PRs preferred.

MIT © 2026 Ahmed El-Kholy.

## API stability and upgrades

The current [public API contract](docs/PUBLIC_API.md) is a **draft** checked by
unit tests. No stable tag has been published. Operational retention and schema
rollback policies are described in [retention](docs/RETENTION.md) and the
[operations runbook](docs/OPERATIONS.md).

