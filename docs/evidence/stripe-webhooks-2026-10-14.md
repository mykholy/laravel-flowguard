# Actual signed Stripe event delivery — 2026-10-14

**Result: one actual Stripe sandbox event, duplicate delivery and tampered-body rejection passed.**
[Sanitized machine-readable report](stripe-webhooks-2026-10-14.json) records the source hashes and versions.

## Executed path

A verified official Stripe CLI binary connected to Stripe's authenticated event
stream using a privately supplied test key and `--events-from @self`. The CLI
reported API version `2026-09-30.endive`. A separate Laravel 13.36.0 host on PHP
8.3.6 / SQLite received its signed snapshot event. The host used Stripe PHP
22.0.0 for raw-body signature verification with the listener's signing secret.
The key and signing secret were runtime-only; no raw event body was retained.

One fixed 500 USD-minor-unit test payment generated a real
`payment_intent.succeeded` event. This was not `stripe trigger`, an offline
fixture, or a manually signed substitute for the provider's initial delivery.
A bounded local proxy forwarded the actual body and signature three ways:

| Delivery | HTTP result | Persisted result |
| --- | --- | --- |
| Original Stripe CLI forward | 200, `duplicate=false` | One receipt and one outbox observation |
| Same original body and signature again | 200, `duplicate=true` | Same receipt; no new outbox signal |
| Body with an appended space, original signature | 403 | No new receipt or signal |

The scoped inbox and outbox row counts each increased by exactly one. The
pilot command also completed its read-only success observation and explicit
configured-principal bookkeeping. The webhook endpoint itself never changes
payment status, writes reconciliation evidence or grants approval.

## Reproduction and limits

Use the [demo setup and webhook instructions](../../examples/checkout-demo/README.md#actual-stripe-webhook-endpoint),
start the host, and listen for sandbox payment events with the CLI. A local
forwarding proxy can replay the exact body/header before its five-minute expiry
and append one byte to test signature rejection. Count only the relevant
`tenant.1:stripe` inbox/outbox rows before and after. Keep all credentials and
raw events out of logs and published artifacts.

The local proxy generated the duplicate; this does not establish Stripe's
automatic retry schedule, Dashboard endpoint configuration or direct public TLS
delivery. The first delivery genuinely originated on Stripe's event stream and
was authenticated with its CLI signing secret. The host has one explicitly
mapped sandbox account/tenant. Offline negative contracts separately cover
other tenants, connected accounts, live mode, changed amount/currency/reference,
expiry, future timestamps, payload mutation and same-ID payload conflicts.

An earlier exploratory execution received the real signed event with HTTP 200,
but its later read-only pilot observation failed with `stripe_transport_failed`.
It is not counted as a complete passing pilot; its unknown operation and durable
receipt were preserved. The final bounded run reported the full assertions
above. No failed claim was cleared or blindly reconfirmed.

This adds external-provider evidence; it does not establish an independent
human audit, real-person approvals, a real network acknowledgement-loss test,
representative customer staging or production gateway certification.
