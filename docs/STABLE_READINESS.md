# Stable readiness evidence — 2026-10-14

Stable 1.0 remains blocked by the host/provider and independent-review gates in
`RELEASE_CRITERIA.md`. This record closes a concrete synthetic-load gap without
claiming those reviews or staging exercises occurred.

## Large-worker verification

Runtime/test commit: `7eec811d7ec07ee8f9df1cd82a1e1ddb8d105a5e`.
Workflow: https://github.com/mykholy/laravel-flowguard/actions/runs/38573650147

Each worker opened a SQL connection before the shared start gate. Three fresh
workloads ran for each combination. Disposable connection ceilings were 620.
The predefined p99 limit was 10,000 ms, with zero failed workers, N unique
messages delivered, and exactly N total lease attempts. These limits were not
raised after observing failures.

| Engine | Workers | Successful deliveries per run | p99 ms per run |
| --- | ---: | --- | --- |
| MySQL 8 | 100 | 100 / 100 / 100 | 523.102 / 595.900 / 600.990 |
| MySQL 8 | 500 | 500 / 500 / 500 | 5626.825 / 7109.790 / 6584.383 |
| PostgreSQL 16 | 100 | 100 / 100 / 100 | 662.559 / 641.642 / 689.416 |
| PostgreSQL 16 | 500 | 500 / 500 / 500 | 3042.124 / 3227.966 / 2905.064 |

Total: 3,600 unique deliveries and exactly 3,600 lease attempts, zero failed
workers in all twelve final runs. Artifacts include JSON, process resource
summaries and before/after aggregate SQL counters, retained for 90 days.

The same runtime revision passed all 22 PR jobs, including the compatibility,
clean-install and native-restore matrices. The MySQL regression exercises a
second independent connection while reservation still holds its selected-row
lock, proving another eligible row remains independently lockable.

## Failures discovered and corrected

The original sorted MySQL locking query failed its 500-worker workload:
371 deliveries, 128 poll timeouts and one deadlock:
https://github.com/mykholy/laravel-flowguard/actions/runs/38571955124

The first correction avoided broad locks but walked too many stale candidates
inside one transaction. One repetition still timed out:
https://github.com/mykholy/laravel-flowguard/actions/runs/38573130582

The final correction reads a bounded candidate window without locks and probes
at most two shuffled primary keys using current-state locking reads. Collisions
return null for a fresh transaction and poll. See `RFC-003-LEASING.md` for the
explicit best-effort ordering and polling contract. No host session isolation
setting is changed. Existing published tags are not modified.

## Evidence boundaries

These are local queue reservation/acknowledgement workloads on disposable
GitHub runners, not provider integrations, durable downstream consumers,
continuous lock-wait tracing, or a representative application staging SLA.
The counters do not demonstrate absence of all internally retried deadlocks.
They demonstrate the reported successful final workload outcomes.

Remaining acceptance needs a real host application and external provider
sandbox, independent security/API review, consistent full-application restore,
and owner-reviewed retention/approval/tenant policies. None is marked complete
by this synthetic benchmark or by an AI-authored review.
