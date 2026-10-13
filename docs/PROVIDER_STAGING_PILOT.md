# External provider staging pilot

Status: **not yet performed**. The local HTTP receiver in
HTTP_PROVIDER_FIXTURE.md is a protocol simulation, not a gateway pilot.

Use only an isolated external provider **sandbox** account and a test Laravel
application. Do not run this procedure against live merchants, payouts,
customer identities or cards. Host credentials must remain in a private
secret store and must never be committed or put in run artifacts.

## Preparation

- Pin the Flowguard commit SHA and record PHP, Laravel, MySQL/PostgreSQL,
  server timezone, queue driver, HTTP timeout and lease lifetime.
- Install all migrations. Run php artisan flowguard:doctor and verify expected
  scope and unique indexes before any provider traffic.
- Implement signature verification with the provider's actual documented
  algorithm, expiry policy, key rotation and replay detection. Verify tenant
  ownership from authenticated server-side configuration.
- Implement an OutboxPublisher that returns only after durable provider
  acceptance and uses one immutable provider-supported idempotency key.
- Implement downstream idempotency and a **read-only** provider status lookup
  for uncertain outcomes. Never infer a failed charge from timeout alone.
- Capture only synthetic event IDs, hashed request keys and sanitized status
  codes. Strip Authorization, cookies, personal data and raw payload bodies.

## Pilot scenarios

| Scenario | Expected safety invariant |
| --- | --- |
| One valid request | One business effect and one completed operation |
| Repeated same key/body | No second business effect |
| Same key, changed body | Explicit identity conflict, no dispatch |
| Forged/expired signature | Request rejected before receipt or effect |
| Same external event sent twice | One inbox receipt and processing signal |
| Provider accepts, client loses acknowledgement | Same idempotency key on retry; one provider-side effect |
| Client times out without evidence of acceptance | Unknown outcome remains fail-closed for read-only reconciliation |
| Process dies after remote acceptance | No automatic repeated business effect; recovery follows documented provider semantics |
| Two workers contend for one outbox row | Only current fenced lease can acknowledge |
| Expired lease replaced by another worker | Old worker cannot change delivery status |
| Conflicting provider observations | Resolution rejected pending investigation |
| Same actor initiates and approves | Manual resolution rejected |
| Tenant A tries to read or resolve tenant B | Denied with no state mutation |
| Database outage before commit | No falsely acknowledged receipt or completed business operation |
| Delivery retries exhausted | Operator-visible failure; no automatic unsafe replay |

Run each failure-injection case multiple times. For operations with uncertain
external effect, verify the provider ledger directly using its **read-only**
status endpoint and cross-check the local persisted state and audit evidence.

## Evidence and go/no-go decision

For each test, record the build SHA, environment identifier, provider sandbox
API version, test case, sanitized correlation key, number of observed provider
effects, number of inbox/outbox/operation rows, expected/actual outcome,
duration, and reviewer result. Include logs with secrets removed.

Hard release blockers: duplicated monetary effects, cross-tenant access,
unknown outcomes classified as failures without evidence, unapproved
reconciliation, lost committed receipts, stale lease acknowledgement, or
failure to restore durable audit records.

The host owner and an independent reviewer must sign off on the **same**
tested build. Passing a local fake HTTP fixture does not close this pilot.
Once done, link the redacted evidence report and exact SHA in issue #5.
