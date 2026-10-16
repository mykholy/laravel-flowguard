# Independent staging pilot report — TEMPLATE (NO EXTERNAL ACCEPTANCE)

**Status:** `NOT TESTED` until an independent adopting team executes the
documented scenarios on a disposable provider sandbox and signs off. Do not
enter real card details, access tokens, secrets or production/customer data.

## Build and environment

| Field | Value |
| --- | --- |
| FlowGuard immutable tag and source commit SHA | NOT PROVIDED |
| Adopting project (privacy-preserving identifier) | NOT PROVIDED |
| Host Laravel/PHP/SQL versions and queue driver | NOT PROVIDED |
| Provider sandbox name and API version | NOT PROVIDED |
| Integration version / sanitized config digest | NOT PROVIDED |
| Publisher timeout and lease/retry budget | NOT PROVIDED |
| Independent reviewer and test operator identities | NOT PROVIDED |

## Pilot acceptance evidence

For every case enter the test ID, reproducible sanitized fixture, observation
from the real provider *read-only* API, local persisted counters, number of
remote effects, and **PASS/FAIL/NOT TESTED**.

| Case | Expected invariants | Result | Evidence ID |
| --- | --- | --- | --- |
| Same idempotency key submitted concurrently | One owner claim, one external business effect | NOT TESTED | — |
| Same key but changed semantic payload | Reject identity conflict, no second effect | NOT TESTED | — |
| Provider accepted but caller timed out | Unknown state preserved; provider effect at most once | NOT TESTED | — |
| Duplicate signed webhook | One durable inbox receipt; no duplicate effect | NOT TESTED | — |
| Forged, expired or invalid signature | Rejection before durable receipt | NOT TESTED | — |
| SIGKILL after remote publish before local ACK | Fenced lease; downstream deduplication | NOT TESTED | — |
| Expired lease stolen by second worker | First lease cannot ACK | NOT TESTED | — |
| Conflicting provider evidence | No unapproved terminal resolution | NOT TESTED | — |
| Same operator tries both approval roles | Denied without state mutation | NOT TESTED | — |
| Cross-tenant access and record reference misuse | Rejected without business mutation | NOT TESTED | — |
| Nonempty DB backup/restore with outstanding leases | No loss of committed receipts/audit; verified recovery | NOT TESTED | — |
| Provider unavailable / exhausted retries | No unknown payment replay; operator-visible state | NOT TESTED | — |

## Go / no-go

- [ ] Provider-effect count independently reconciled with sandbox ledger.
- [ ] Both authorized reviewers sign the **same pinned host and package** build.
- [ ] Reproducible failures and exact fix SHAs captured.
- [ ] Secrets and customer identifiers scrubbed from all submitted artifacts.
- [ ] Production-specific retention, incident procedure, capacity and recovery limits separately accepted by host owner.

**Decision:** PENDING. This is not an end-to-end payment certification or a
guarantee of exactly-once remote side effects.

Submit a sanitized report in [issue #42](https://github.com/mykholy/laravel-flowguard/issues/42)
and link the sign-off in [issue #5](https://github.com/mykholy/laravel-flowguard/issues/5).
