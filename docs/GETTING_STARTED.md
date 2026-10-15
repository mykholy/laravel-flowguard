# Getting started

This guide uses the documented 1.x API on Laravel 12/13 and PHP 8.3+.
Choose an operation claim for a request that must have one owner, an inbox for
an already verified webhook, or an outbox for an atomic business-event handoff.

## Install and prepare storage

```bash
composer require mykholy/laravel-flowguard:^1.0
```

If the default Laravel database is correct, continue to migration. To select
another configured connection, publish the configuration first:

```bash
php artisan vendor:publish --tag=flowguard-config
```

Set `FLOWGUARD_DB_CONNECTION` in your environment, then refresh any cached
Laravel configuration through your normal deployment process. Next run:

```bash
php artisan migrate
php artisan flowguard:doctor
```

The package discovers its service provider and migrations automatically. Use a
durable transactional database; an in-memory SQLite connection cannot preserve
identities after process exit. Doctor verifies the local schema, not provider
credentials or application authorization.

## Guard an operation

The following is an integration sketch. Resolve `$merchantId`, `$orderId` and
`$amountMinor` from authorized application data. The gateway is your existing
provider adapter, with its own stable remote idempotency identity.

```php
use Mykholy\Flowguard\Flowguard;

$guard = app(Flowguard::class);
$acquisition = $guard->begin(
    scope: 'payments.capture',
    idempotencyKey: $merchantId . ':' . $orderId,
    payload: [
        'order_id' => $orderId,
        'amount_minor' => $amountMinor,
        'currency' => 'EGP',
    ],
);

if (! $acquisition->acquired()) {
    // Return an application response based on $acquisition->operation->status.
    // This caller must not invoke the gateway.
    return;
}

$claim = $acquisition->claim();
```

Only the acquired caller owns the claim. A matching duplicate exposes the
existing status without an owner capability. Reusing the identity with changed
payload data throws `IdempotencyConflict`. Keep the same identity across retries.

After the provider returns a known success, persist any required business writes
and finalize with `$guard->complete($claim, $safeMetadata)`. Use a transaction on
the same connection when local finalization and business writes must commit
atomically. Keep the provider call outside that local transaction.

When a dispatched call throws and its outcome is unknown, use
`$guard->requireReconciliation($claim, 'capture_outcome_unknown')` and investigate
through a provider-specific read-only lookup. Never automatically retry the
external effect. A process death can leave `processing` behind; its age is not
permission to repeat the call. The [README payment example](../README.md#payment-example)
shows the error boundary; the [demo host](../examples/checkout-demo/README.md)
provides a complete application exercise.

## Record a business event

An outbox signal commits with the business write. All writes below use the same
connection. `$orderId`, `$tenantId` and `$eventId` are application-derived values;
`$eventId` is stable for this specific business transition, not newly generated
for every retry. The `orders` table and its status rules belong to your host.

```php
use Illuminate\Support\Facades\DB;
use Mykholy\Flowguard\Outbox\DatabaseOutboxRecorder;

$connection = DB::connection(config('flowguard.connection'));
$outbox = new DatabaseOutboxRecorder($connection);

$connection->transaction(function () use (
    $connection, $outbox, $orderId, $tenantId, $eventId,
): void {
    $connection->table('orders')
        ->where('id', $orderId)
        ->where('tenant_id', $tenantId)
        ->update(['status' => 'confirmed']);

    $outbox->record(
        scope: 'tenant.' . $tenantId . ':orders',
        eventId: $eventId,
        topic: 'order.confirmed',
        subjectReference: 'order:' . $orderId,
    );
});
```

Before this transaction, authorize the order and validate the business transition.
In your host, also check the affected row/state before recording its event.
An event-identity conflict propagates and rolls back this transaction; the
recorder does not silently ignore duplicate business writes.

The package stores an opaque subject reference, not a complete immutable event
body. If delivery must reproduce the original state, persist an authorized,
versioned snapshot in the same transaction and reference that snapshot.

## Connect delivery or verified webhooks

- For delivery, implement and bind `OutboxPublisher`, then follow
  [Publishing](PUBLISHING.md) to configure a supervised worker, bounded timeout,
  tenant credentials and destination deduplication.
- For inbound events, verify the provider signature, freshness and tenant
  binding first, then follow [Durable inbox](INBOX.md).
- For unknown outcomes, start with [read-only reconciliation](RFC-002-READ_ONLY.md).
  Evidence journaling and manual classification are separate opt-in features
  requiring your application's authorization policies.

## Inspect and operate

```bash
php artisan flowguard:doctor
php artisan flowguard:stale --minutes=15 --limit=50
php artisan flowguard:outbox --max-attempts=10 --limit=50
```

These are read-only diagnostics. Use the [operations runbook](OPERATIONS.md)
for incident handling and the [retention contract](RETENTION.md) before deleting
or archiving identities. Populated migration rollback is intentionally rejected.

[Documentation index](README.md) · [Public API](PUBLIC_API.md) · [Package overview](../README.md)
