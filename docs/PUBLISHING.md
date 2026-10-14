# Publishing an outbox message

## Opt-in Artisan delivery

The package provides a **single-attempt** command; it is never scheduled
automatically. In the host application's service provider, bind an audited
publisher to the public contract:

```php
$this->app->bind(
    \Mykholy\Flowguard\Contracts\OutboxPublisher::class,
    \App\Messaging\VerifiedOutboxPublisher::class,
);
```

The application-defined publisher must validate the tenant scope, load an
immutable or correctly versioned event snapshot, and use the message ID as a
destination idempotency key. Its HTTP timeout must be shorter than the lease.

```bash
php artisan flowguard:dispatch-once --lease=60 --retry=60 --max-attempts=10
```

The command delivers **at most one** eligible message and exits. It rejects a
missing publisher binding or invalid limits and exposes only the exception
class (not the provider error text) on failure. Run it from a supervised worker
or an explicitly configured Laravel scheduler. Its failure exit status must be
monitored; a failed remote call can already have succeeded upstream.

A queue adapter, high-throughput dispatcher and automatic schedule are **not**
bundled. Do not run it inside a business transaction, do not log raw
exceptions, and do not treat a command retry as exactly-once delivery.

The host application implements `OutboxPublisher`. Its `publish(OutboxMessage $message)`
method must only return after the destination durably acknowledges the event.

```php
$dispatcher = new OutboxDispatcher(
    new DatabaseOutboxLeaseStore(DB::connection()),
    app(App\Messaging\OrderPublisher::class),
    app(\Psr\Log\LoggerInterface::class),
);

$dispatcher->dispatchOne();
```

Invoke the dispatcher from a supervised worker with explicit timeouts. The
publisher receives the message ID, tenant-aware scope, topic and subject
reference. It never receives the lease token.

- Publish with a stable destination idempotency key based on message ID.
- Ensure your remote HTTP timeout is shorter than the lease duration.
- Avoid publishing mutable business state without a snapshot reference.
- A timeout can happen after the destination accepted the message.
- A database-backed delivery cannot begin inside an outer transaction. Hosts
  implementing custom lease repositories must enforce this precondition.
- If publishing throws, the lease is released for a bounded retry and the
  exception propagates. If release fails, the original error is rethrown
  and a sanitized error is reported through the logger. Do not persist raw exception text as a failure code.
- If acknowledgement fails, the dispatcher does not release or resend.
- A crash after publishing and before acknowledgement causes redelivery.

**Delivery is at-least-once. Exactly-once side effects are not promised.**
The package ships a bounded CLI orchestration primitive, not an automatic
scheduler, provider transport or guarantee of end-to-end delivery.

## Tenant-scoped delivery workers

If your application uses different provider credentials per tenant, create a
dedicated lease store for that tenant's **server-derived** scope:

```php
$leases = new DatabaseOutboxLeaseStore(
    DB::connection(),
    scope: 'tenant.42:orders',
);
$dispatcher = new OutboxDispatcher($leases, $publisher, $logger);
```

A scoped store reserves only its own rows and refuses to acknowledge or
release a lease belonging to a different scope, even when passed a valid
lease object from another worker. The scope must come from authenticated
application configuration, never an untrusted message or request header.

An unscoped store retains the original all-scopes behavior for shared
publishers. Flowguard **does not authenticate the tenant** and a
scope-qualified worker does not replace the host's credential authorization.

The opt-in Artisan command supports the same restriction:

```bash
php artisan flowguard:dispatch-once --scope=tenant.42:orders
```

An application-wide publisher binding is still required. The CLI flag is an
**operator-supplied scope**, so production job scheduling must supply it from
trusted configuration and bind credentials to the same tenant. Never forward
the scope directly from a webhook or client request.
