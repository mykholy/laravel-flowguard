# HTTP provider acknowledgement-loss fixture

HttpProviderAcknowledgementLossTest launches PHP's built-in HTTP server as
a **separate local process**. The fixture maintains a file-backed accepted-event
registry keyed by the immutable outbox message ID. The publisher POSTs to that
server and receives HTTP acceptance. On the first request, the publisher raises
a simulated acknowledgement-loss exception before it acknowledges its lease.

Flowguard releases the lease. The next attempt delivers the same message ID.
The fixture receives two HTTP requests but persists only one logical event.
The integration test checks two delivery attempts and a final delivered state.

Unlike a mocked publisher callback, this verifies real localhost HTTP requests
and a separately executing receiver. It does **not** prove correctness of a real
third-party gateway, TLS, credentials, provider signatures, reconciliation
endpoints, or provider-side timing.

Only synthetic payloads are used, the server binds to loopback, and the
application data lives in a disposable SQLite database.

## Host integration obligations

- Use the same provider-supported idempotency key on every retry.
- Treat a network timeout after dispatch as an **unknown** outcome.
- Deduplicate recipients on the immutable message ID.
- A successful publish() must mean durable remote acceptance.
- Keep transport timeouts shorter than the active lease duration.
- Verify the remote provider's status before resolving uncertain payments.

This remains a local integration fixture, not a real-provider staging pilot.
An independent security review and a representative external-provider pilot
remain mandatory release criteria for v1.0.0.
