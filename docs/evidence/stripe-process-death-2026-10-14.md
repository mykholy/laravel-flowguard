# Real Stripe sandbox worker termination — 2026-10-14

**One bounded process-death recovery passed against Stripe over HTTPS. Stable acceptance remains open.**

The host used PHP 8.3.6, Laravel 13.36.0 and the existing disposable SQLite pilot database, with the beta.3 demo transport and unchanged core runtime described in [the earlier evidence](stripe-sandbox-2026-10-14.md). The fixed payment was 500 USD minor units, test mode only; no live transaction was made.

## Observed successful execution

1. A separate PHP worker created an unconfirmed intent, persisted its encrypted reference, confirmed the fixed test PaymentMethod and durably recorded an unknown outcome.
2. Middleware killed that worker with **SIGKILL (9)** immediately before the first provider observation. The parent process independently verified signal termination. This was not an exception masquerading as a killed worker.
3. The parent verified durable `requires_reconciliation` / `unknown` state, then ran the existing `--resume` command with a middleware that rejects every provider POST.
4. Recovery made **two real GET requests and zero POST requests**, ending in `reconciled_success` / `paid`.
5. A further read of Stripe's charge ledger filtered to the encrypted intent reference returned exactly one captured, paid test charge for 500 USD minor units, with no additional result page.

[Sanitized machine-readable report](stripe-process-death-2026-10-14.json). No credentials, provider identifiers or client secrets are published.

## Reproduction

Use the disposable host and private runtime environment documented in the demo, including `APP_ENV=testing`, `DEMO_STRIPE_PILOT_CONFIRM=sandbox-only`, an allowed pilot database and `STRIPE_TEST_SECRET_KEY`. On Linux with POSIX signal support:

```sh
php examples/checkout-demo/tools/stripe-process-death.php /absolute/demo-host /private/report.json
```

This command creates one new fixed test payment per execution. It is an opt-in external experiment, not an offline test and not automatically run by CI. Run it against an isolated host with no concurrent pilot commands. The report must be private and outside the repository. The harness rejects recovery writes and verifies provider mode, amount, currency, intent binding and ledger cardinality.

Harness SHA-256: `7ea3144dae8dc522ddbed28e5602cdb890ff7f8167a258aa1c8f1251c8261d30`.

## Limits and exploratory failures

The kill boundary is **after durable unknown classification, before observation**, not the earlier interval between remote confirmation and local unknown classification. Actual inbound Stripe webhook delivery, real network acknowledgement loss, representative MySQL/PostgreSQL provider staging, and independent human security/adopter/privacy approval are still unverified. Configured reviewer principals in this test are not independent people.

Earlier exploratory executions exposed harness handling problems: an incomplete run did not satisfy the signal assertion, and Symfony's expected signal exception initially escaped the parent. Those runs are not counted as passing. Their unknown records were preserved; the test did not clear claims or blindly reconfirm them. The final harness explicitly handles and checks SIGKILL, returns nonzero on uncaught errors, and completed the end-to-end assertions above.

This report adds bounded evidence to issue #5; it does not close the full provider or stable-release gates.
