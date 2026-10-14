# Checkout demo host

A reproducible Laravel 12/13 application for exercising Flowguard with synthetic orders, two tenants, payments, signed webhooks, an outbox publisher, and independent reconciliation reviewers. PHP 8.3+, Composer, SQLite (including `pdo_sqlite` for the provider), and the selected SQL driver are required. Use synthetic data only; the application refuses production environments.

## Create and run

From a checkout of this repository:

```bash
bash examples/checkout-demo/setup.sh /tmp/flowguard-checkout-demo
cd /tmp/flowguard-checkout-demo
php artisan demo:provider
```

In another terminal:

```bash
cd /tmp/flowguard-checkout-demo
php artisan serve --host=127.0.0.1 --port=8000
```

The setup generates separate random credentials in the app's private `.env` (mode `0600`): `DEMO_MERCHANT_A_TOKEN`, `DEMO_MERCHANT_B_TOKEN`, `DEMO_REVIEWER_A_TOKEN`, `DEMO_REVIEWER_B_TOKEN`, `DEMO_PROVIDER_KEY`, and `DEMO_WEBHOOK_SECRET`. Never commit them. Set `MERCHANT_A_TOKEN` locally from that file to try:

```bash
curl http://127.0.0.1:8000/api/demo/orders \
  -H "Authorization: Bearer $MERCHANT_A_TOKEN" \
  -H 'Content-Type: application/json' -H 'Idempotency-Key: checkout-001' \
  -d '{"amount":12500,"currency":"USD"}'
```

Amounts are integer minor units. Repeating the same key/payload returns the same order; changed payload returns HTTP 409. `POST /api/demo/orders/{id}/charge` captures once. `GET /api/demo/orders/{id}` reads only the authenticated tenant's order. Tenant and actor identities come from the server credential map.

Dispatch committed signals separately:

```bash
php artisan flowguard:dispatch-once --scope=tenant.1:orders --retry=0
```

Run this command repeatedly until eligible messages are exhausted. The provider is a separate HTTP process with an independent SQLite ledger at `storage/app/demo-provider.sqlite`. Stable message/operation IDs deduplicate accepted effects; retries can produce multiple transport attempts. It must remain available while testing.

`POST /api/demo/webhooks/1` accepts JSON `event_id`, `order_id`, `amount`, `currency`. Sign `tenant.timestamp.raw_body` using HMAC-SHA256 with `DEMO_WEBHOOK_SECRET`; send hex `X-Demo-Signature` and Unix-seconds `X-Demo-Timestamp`. The five-minute window, tenant/order binding, amount/currency, body size, and verified-event deduplication are enforced. A receipt queues a signal; it does not settle a payment.

For an unknown payment, reviewer A calls `POST /api/demo/operations/{id}/evidence` (read-only provider observation), then `POST /api/demo/operations/{id}/approvals` with `evidence_id`. Reviewer B calls `POST /api/demo/approvals/{approval}/approve`. The durable approval binds both authenticated identities, operation, and evidence. No reconciliation path captures again.

## Repeatable verification

```bash
cd /tmp/flowguard-checkout-demo
php artisan test --compact
```

Tests use and reset only `/tmp/flowguard-demo-test.sqlite` by default, or an explicitly configured SQL database named `flowguard_demo_ci`. They never reset the interactive demo database. Each test launches a new provider on a random loopback port with a separate temporary ledger. The suite exercises:

- Atomic order/claim/outbox rollback; exact duplicates and changed-payload conflicts.
- Server-derived tenant identity and merchant/reviewer permissions.
- Durable acceptance followed by an actual HTTP timeout, read-only evidence, two-person approval, stale-evidence rejection, and no recapture.
- Signed webhook validation, tenant binding, replay window, and deduplication.
- Outbox transport timeout, independent worker exit after remote commit, lease recovery, and stale-owner fencing.
- Worker exit with a processing claim before capture, which remains fail closed.
- Provider rejection of changed payload under an already accepted identity.

The `Demo Host Verification` workflow creates six fresh applications (Laravel 12/13 × SQLite/MySQL/PostgreSQL). Two additional Laravel 13 jobs use native `mysqldump`/`pg_dump` to restore **all host tables into another database**, compare persisted host/package row fingerprints, resume pending delivery, preserve an active lease, recover an expired lease, finish a pending independent approval, check foreign keys, and prove no payment recapture against the unchanged provider ledger.

The destructive restore command additionally requires `APP_ENV=testing`, `DEMO_RESTORE_CONFIRM=disposable-only`, and exactly `flowguard_demo_ci` for prepare / `flowguard_demo_restore_ci` for verify. The workflow keeps dumps, credentials, and provider ledgers private to the runner; only test reports are uploaded.

## Evidence boundary

This is a local synthetic provider, not an actual payment provider sandbox or a production deployment. It makes timeout/crash/restore behavior reproducible, but cannot supply independent security review, real-provider acceptance, representative customer staging, or operator/API sign-off. Release gates remain in [STABLE_READINESS](../../docs/STABLE_READINESS.md). The demo follows the current source checkout; the published beta tag is immutable and may contain older code.

## Optional Stripe Sandbox pilot

The interactive checkout still uses the loopback simulator. The separate `demo:stripe-pilot` command calls only `https://api.stripe.com` using `STRIPE_TEST_SECRET_KEY` beginning with `sk_test_`, one fixed `pm_card_visa` test PaymentMethod, and a fixed USD 5.00 test amount. Live keys, live-mode responses, arbitrary payment methods, and redirects are rejected. It requires `APP_ENV=testing`, `DEMO_STRIPE_PILOT_CONFIRM=sandbox-only`, and a disposable database named `flowguard_demo_ci`, `flowguard_demo_restore_ci`, `flowguard-demo-test.sqlite`, or `flowguard-stripe-pilot.sqlite`.

Set those variables privately before running setup with a new disposable target. Then:

```bash
php artisan demo:stripe-pilot --report=/tmp/stripe-pilot-report.json
```

The command creates an **unconfirmed** intent with a stable idempotency key, persists its encrypted reference before confirmation, confirms once, deliberately discards the application acknowledgement, records an unknown local outcome, then performs GET-only observation and two-principal bookkeeping resolution. This controlled application fault is not a claim of an actual Stripe network timeout or process kill. Duplicate local requests do not confirm again. The sanitized report checks that the observed charge identity remains unchanged; it is not an independent audit of every Stripe account effect.

If a transport fault occurs after the reference was persisted, the report includes a non-sensitive local operation ID. Recover with `php artisan demo:stripe-pilot --resume=<operation-id> --report=/tmp/stripe-resume.json`; this path issues GET requests only. If creation fails before the reference can be persisted, the claim stays unknown and the pilot refuses to guess or automatically create/confirm again. Preserve the private database and `APP_KEY` for investigation; never upload them.

The `Stripe Sandbox Pilot` workflow runs this command when its repository test secret is available. Without that secret it records **blocked**, never a successful provider result. Offline `StripePilotContractTest` tests verify transport contracts and rejection paths; they do not contact Stripe and do not close the real-provider acceptance gate. The workflow's green infrastructure status alone is not provider acceptance: inspect `report.json` and its exact source SHA.

Protocol references: [create an intent](https://docs.stripe.com/api/payment_intents/create), [confirm](https://docs.stripe.com/api/payment_intents/confirm), [idempotency](https://docs.stripe.com/api/idempotent_requests), [Stripe testing](https://docs.stripe.com/testing). The pilot still needs actual provider webhooks, gateway failure cases, process-kill injection, representative SQL staging, and human sign-off before the full provider gate can close.
