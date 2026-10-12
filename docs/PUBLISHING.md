# Publishing an outbox message

The host application implements \`OutboxPublisher\`. Its \`publish(OutboxMessage $message)\`
method must only return after the destination durably acknowledges the event.

\`\`\`php
$dispatcher = new OutboxDispatcher(
    new DatabaseOutboxLeaseStore(DB::connection()),
    app(App\Messaging\OrderPublisher::class),
    app(\Psr\Log\LoggerInterface::class),
);

$dispatcher->dispatchOne();
\`\`\`

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
This is a contract and manual dispatch primitive; there is no scheduled
worker, transport implementation or ready-to-run integration yet.
