# Laravel FlowGuard

**Laravel idempotency, durable webhooks and transactional outbox.**

[![CI](https://github.com/mykholy/laravel-flowguard/actions/workflows/ci.yml/badge.svg)](https://github.com/mykholy/laravel-flowguard/actions/workflows/ci.yml)
[![Packagist version](https://img.shields.io/packagist/v/mykholy/laravel-flowguard)](https://packagist.org/packages/mykholy/laravel-flowguard)
[![PHP](https://img.shields.io/badge/PHP-8.3%20%E2%80%93%208.5-777BB4)](composer.json)
[![Laravel](https://img.shields.io/badge/Laravel-12%20%7C%2013-FF2D20)](composer.json)
[![License](https://img.shields.io/github/license/mykholy/laravel-flowguard)](LICENSE)

FlowGuard coordinates operations that must remain safe when requests repeat,
workers crash or a provider times out after accepting a payment. It records who
owns an operation, preserves its payload identity and keeps uncertain outcomes
blocked until the application investigates them.

**Stable: [v1.0.2](https://github.com/mykholy/laravel-flowguard/releases/tag/v1.0.2).**
The supported PHP API and persisted schema follow [SemVer](docs/PUBLIC_API.md).
The [publication record](docs/STABLE_READINESS.md) links the exact-source checks,
Composer distribution and fresh public installation.

[Installation](#installation) · [Payment example](#example-payment-capture) ·
[Checkout demo](examples/checkout-demo/README.md) · [Public API](docs/PUBLIC_API.md) ·
[Operations](docs/OPERATIONS.md) · [Security](SECURITY.md)

## When to use FlowGuard

| Failure scenario | Primitive | Application responsibility |
| --- | --- | --- |
| Two requests attempt the same payment or fulfillment | Scoped operation claim and immutable fingerprint | Derive tenant identity on the server; use provider idempotency |
| A provider times out with an unknown result | Explicit reconciliation state and read-only evidence | Investigate the provider outcome before any further effect |
| A signed webhook arrives repeatedly | Durable inbox receipt | Verify the signature and tenant/event binding first |
| A business transaction commits but queue dispatch fails | Outbox recording on the same database connection | Record the business write and signal in one transaction |
| A publisher crashes after delivery | Expiring lease, fenced acknowledgement and bounded retries | Deduplicate at the destination; supervise the worker |

Telescope, Horizon and Pulse help observe applications and workers. FlowGuard
adds durable ownership and delivery primitives to your business workflow.

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

## Installation

| Dependency | Supported / verified versions |
| --- | --- |
| PHP | 8.3, 8.4, 8.5 |
| Laravel | 12, 13 |
| Database | SQLite, MySQL 8, PostgreSQL 16 |
| Package manager | Composer 2 |

Use a durable transactional database. CI validates compatibility, clean archive
installation and native SQL restore separately.

The package is published on [Packagist](https://packagist.org/packages/mykholy/laravel-flowguard).
Install the supported 1.x series:

```bash
composer require mykholy/laravel-flowguard:^1.0
php artisan migrate
php artisan flowguard:doctor
```

Review the [v1.0.2 release notes](https://github.com/mykholy/laravel-flowguard/releases/tag/v1.0.2)
and [upgrade procedure](docs/PACKAGE_RELEASE.md). Pin a released version in your
lockfile and validate your own provider adapter before production deployment.

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

// Resolve the merchant from authenticated, authorized application context.
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

## Read-only reconciliation inspection

A provider-specific `Reconciler` may **read** the external state of an operation
explicitly marked `requires_reconciliation`. `ReconciliationInspector` returns
bounded, typed `ReconciliationEvidence` and cannot finalize or replay anything.
The caller must authenticate and authorize the inspection and use a read-only
provider lookup. This is intentionally **not** an automated recovery system.

See [RFC-002](docs/RFC-002-READ_ONLY.md) for the API and limits.

## Opt-in durable reconciliation evidence journal

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

See [the evidence journal design](docs/RFC-002-EVIDENCE-JOURNAL.md). Host policies
and provider adapters need application-specific security and integration review.

## Opt-in manual outcome classification

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

## Transactional outbox recording

`DatabaseOutboxRecorder` records a small, durable signal in the same database
transaction as a business change. It refuses to record outside an active
transaction. The caller must use the **same database connection** for both
writes and scope event identities by tenant. Duplicates fail through a database
unique constraint.

Recording does **not** publish or enqueue anything. A separately configured
`OutboxDispatcher` or `flowguard:dispatch-once` command can deliver a pending
message using an application-bound `OutboxPublisher`. There is no bundled
provider transport, automatic schedule or background daemon.
See [outbox recording design](docs/RFC-003-OUTBOX.md).

## Outbox reservation lifecycle

`DatabaseOutboxLeaseStore` allows a worker to reserve one pending message,
acknowledge it after successful publishing, or release it with a bounded retry
delay and machine-readable error code. Each lease carries an opaque token;
expired or replaced leases cannot acknowledge delivery. Attempts are capped
(default: 10), leaving exhausted records for operator inspection.

Consumers **must** support duplicate delivery. A publisher may succeed while
its worker crashes before acknowledging the row. This is an at-least-once
foundation, not exactly-once delivery. The host must supply a publisher and
explicitly schedule its worker. See the [lease contract](docs/RFC-003-LEASING.md)
and [publishing guide](docs/PUBLISHING.md).

## Correctness and versioning policy

- Never repeat external effects when an existing operation is processing or unknown.
- No TTL-based automatic lock reclaim. Recovery requires reconciliation.
- Only application-verified, authorized, validated requests reach the engine.
- Do not store raw PII, gateway secrets, or complete response bodies in result metadata.
- The documented 1.x public API follows SemVer; no availability or delivery SLA is offered.
- Independent-process concurrency, crash injection and SQL restore tests run in
  CI. They do not replace a real-provider pilot or independent security review.

## Local development

```bash
composer install
composer validate --strict
composer test
composer analyse
composer format-check
php tests/smoke.php
node --test tools/check-preview-release.test.cjs
```

Independent SQL workers require the disposable database setup described in
[concurrency testing](docs/CONCURRENCY_TESTING.md). The repository-only
[checkout demo](examples/checkout-demo/README.md) covers signed webhooks,
provider timeouts, worker exits and full-host restoration.

## Contribution

See the [roadmap](docs/ROADMAP.md), [operations runbook](docs/OPERATIONS.md)
and [release criteria](docs/RELEASE_CRITERIA.md). Contributions should include a failing regression test,
public failure scenario and rationale for any API change. Small, reviewable PRs preferred.

MIT © 2026 Ahmed El-Kholy.

## API stability and upgrades

The [supported public API contract](docs/PUBLIC_API.md) is versioned from v1.0.0
and checked by signature and behavior tests. Operational retention and schema
rollback policies are described in [retention](docs/RETENTION.md) and the
[operations runbook](docs/OPERATIONS.md).


## Release artifacts

Clean-install CI installs the actual Composer distribution on every supported
Laravel/database combination. See the [distribution and publication procedure](docs/PACKAGE_RELEASE.md)
for archive verification, commit-linked artifacts and stable promotion steps.

## Runnable checkout demo

See [the Laravel checkout demo](examples/checkout-demo/README.md) for an isolated host application, signed webhooks, independent HTTP provider, crash/timeout tests, and native full-host restore drills.

## Production integration boundaries

FlowGuard's stable release commits to library compatibility. The application
owns authentication, tenant isolation, webhook verification, provider adapters,
transport timeouts, approvals and retention. Outbox delivery is at least once;
consumers must deduplicate. A crashed processing claim is never automatically
reclaimed based on age.

Independent human security and adopter reviews remain open. Review the
[release scope](docs/RELEASE_CRITERIA.md), [tenant boundaries](docs/TENANT_BOUNDARIES.md)
and [provider pilot evidence](docs/PROVIDER_STAGING_PILOT.md) when integrating
with your own production workflow.
