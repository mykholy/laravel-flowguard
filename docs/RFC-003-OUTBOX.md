# Transactional outbox: recording boundary

The outbox begins with an intentionally small primitive: insert a durable
message in the **same transaction and database connection** as the business
change. It does not send anything yet.

## Usage

    use Illuminate\Support\Facades\DB;
    use Mykholy\Flowguard\Outbox\DatabaseOutboxRecorder;

    $db = DB::connection();
    $outbox = new DatabaseOutboxRecorder($db);

    $db->transaction(function () use ($db, $outbox, $order): void {
        $db->table('orders')->where('id', $order->id)->update(['status' => 'paid']);
        $outbox->record(
            scope: 'tenant.42:orders',
            eventId: 'order-'.$order->id.'-paid-v1',
            topic: 'order.paid',
            subjectReference: (string) $order->id,
        );
    });

The recorder refuses to run outside an active transaction. A rollback drops
both writes. A unique index on (scope, SHA-256(eventId)) prevents concurrent
double-recording; an attempted duplicate raises a database exception.
Choose event IDs that are stable across retries, and include the tenant in
the scope. The library does not infer or verify tenant ownership.

## Why references instead of payloads?

The journal stores no event body. Consumers will read an immutable, authorized
business snapshot using the subject reference and topic. This limits accidental
persistence of addresses, gateway responses, access tokens and other sensitive
data. It also means consumers must handle deleted or changed aggregates and
must not assume the referenced business record is an immutable event snapshot.

If an immutable event payload is required for correctness, the host app must
write its own versioned, immutable snapshot **inside the same transaction**
and reference that snapshot instead of a mutable business aggregate.

## Guarantees and limitations

- Local database atomicity only, on the same connection/transaction.
- The caller must not catch a duplicate constraint violation inside a
  PostgreSQL transaction and then attempt more SQL in the failed transaction.
- No delivery, claiming, leasing, retry, callback, webhook verification or
  queue dispatch in this increment. Rows remain pending.
- No claim of exactly-once side effects. A future dispatcher will be
  at-least-once; consumers must independently deduplicate.
- No automatic purging; retention is an explicit future feature.
- Support for MySQL and PostgreSQL is targeted; independent-process outbox
  race and failure-injection tests are a release prerequisite.

This is a foundational PR, not an integration-ready outbox dispatcher.
