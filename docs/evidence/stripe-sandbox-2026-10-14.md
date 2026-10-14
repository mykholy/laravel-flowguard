# Partial external Stripe sandbox evidence — 2026-10-14

**Result: the two bounded scenarios passed against Stripe over HTTPS. Full provider acceptance is still open.** No live-mode payment was made. [Machine-readable evidence](stripe-sandbox-2026-10-14.json) contains sanitized counts and source hashes; no credentials, raw provider identifiers, client secrets, card details or account identifiers are included.

## Tested environment and source

- PHP 8.3.6, Laravel 13.36.0, SQLite; a fresh isolated generated checkout host.
- Core installed from public Packagist: `v0.1.0-beta.2`, source `454adc11e0570cb0af79d01d36faca6022bfa92b`.
- Demo transport corrected as included in beta.3; its exact SHA-256 and the unchanged pilot command hash are in the JSON evidence. No core runtime file was modified.
- Stripe API pinned per request to `2026-09-30.endive`.
- Test-only secret, fixed `pm_card_visa`, and USD 500 minor units per payment. Credentials were supplied privately at runtime, never committed or installed into CI secrets.

## A real defect was exposed before the successful run

The beta.2 demo's unconfirmed intent creation received HTTP 400 because Stripe's current API removed `payment_method_types`. The host preserved `requires_reconciliation`; it issued no capture confirmation for that failed creation. The failed operation was not cleared, retried as a capture, or rewritten to success.

The corrected transport sends `allowed_payment_method_types=[card]` and pins its API version. The offline regression now checks both the allowed field, absence of the removed field, and the explicit header on every request. The corrected local suite passed **20 tests / 228 assertions**, and Pint passed.

Official protocol sources: [parameter removal](https://docs.stripe.com/changelog/endive/2026-09-30/removes-the-payment-method-types-parameter-from-payment-intents-and-setup-intents), [create intent](https://docs.stripe.com/api/payment_intents/create), [version overrides](https://docs.stripe.com/api/versioning).

## Actual scenarios and observations

| Scenario | Observed result |
| --- | --- |
| Credential preflight | Stripe balance response returned `livemode=false`; monetary balances were not retained in this report. |
| Real confirmation followed by discarded application acknowledgement | One unconfirmed intent was created, its reference encrypted in host storage, then confirmed. The local outcome became unknown, GET-only observation established success, and two configured host principals completed bookkeeping. |
| Duplicate local claim and duplicate checkout request | No new owner claim or confirmation; the observed charge identity remained unchanged. |
| Interrupt the first local read after real confirmation | A Guzzle request middleware deliberately threw before the first GET was transmitted. Real create and confirm had already returned; the local payment stayed `unknown` and operation `requires_reconciliation`. |
| Resume that unknown operation | Middleware observed **0 create POST, 0 confirm POST, 2 GET** during `--resume`; the operation became `reconciled_success` and its payment `paid`. |
| Independent read of Stripe's charge ledger | A separate Python HTTPS client queried charges filtered to each tested intent. Each response had exactly one matching charge, `has_more=false`, `paid=true`, `captured=true`, amount 500 USD minor units and `livemode=false`. Total: two test charges, one per intent. |

The interrupted phase's middleware saw one create, one confirm, and one **attempted** read. That read was rejected locally before transmission; the counter is not evidence of a third request reaching Stripe. The two resumed reads and independent ledger queries used real HTTPS, not `Http::fake`.

Persisted host totals after these scenarios: three orders/payments (including the pre-fix rejected create), two success classifications, two evidence entries, two resolution decisions and two resolved host approvals. The pre-fix claim remained unknown. Order-created outbox rows were not dispatched to Stripe.

## Reproduce the two bounded paths

Run the documented demo setup in an isolated disposable environment with the test key and `DEMO_STRIPE_PILOT_CONFIRM=sandbox-only`. Execute `demo:stripe-pilot --report=<private-report>` for the first path.

For the recovery path, bootstrap the same host, register `Http::globalRequestMiddleware`, allow the create/confirm POSTs, and throw once before the first intent GET. The command must fail with `stripe_transport_failed`, leaving the encrypted reference and unknown operation intact. Remove that local read fault, call `demo:stripe-pilot --resume=<local-operation-id> --report=<private-report>`, and reject any POST in middleware during that resume. Expect two GET attempts and successful bookkeeping. Use a separate read-only client to list charges filtered to the decrypted intent reference; keep the raw reference private.

## What this does not establish

- The discarded acknowledgement and interrupted GET are controlled application faults, **not** an actual Stripe network timeout or a killed process.
- The two principals are configured application identities invoked by the test, **not** two independent human reviewers.
- Actual provider webhooks, representative MySQL/PostgreSQL provider staging, independent security/adopter review, owner-approved retention and full release sign-off were not exercised here.
- Observing one charge per tested intent is bounded evidence for these runs, not an exactly-once guarantee across independent systems.

The initial missing-secret CI report remains a valid historical blocked result. This later local execution supplies partial external evidence; it does not retroactively turn that skipped workflow into a provider test. Keep the full release gates open in issue #5.
