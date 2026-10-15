# Stable release verification

## Published v1.0.1 — verified 2026-10-15

**v1.0.1 is published as a stable library release.** Its supported API, persisted
schema and behavior follow the [1.x release contract](RELEASE_CRITERIA.md).
The beta-era application acceptance checklist below is historical evidence;
it does not describe a currently blocked package publication.

Released source: `fe85777f5a7656b61731274d0a6d5fa004ff060d`.

| Exact-source workflow | Result | Run |
| --- | --- | --- |
| Compatibility, static analysis and SQL worker tests | Success | [CI](https://github.com/mykholy/laravel-flowguard/actions/runs/38607750018) |
| Archive-based Laravel installations | Success | [Clean installation](https://github.com/mykholy/laravel-flowguard/actions/runs/38607750028) |
| Checkout host verification | Success | [Demo host](https://github.com/mykholy/laravel-flowguard/actions/runs/38607750013) |
| Native database and host restoration | Success | [Restore](https://github.com/mykholy/laravel-flowguard/actions/runs/38607750102) |
| Immutable release, archive/checksum, Packagist and fresh public installation | Success | [Publication](https://github.com/mykholy/laravel-flowguard/actions/runs/38607750225) |

Publication job `116003430898` completed the exact Packagist indexing and fresh
public Laravel installation steps. The public Composer metadata independently
resolves v1.0.1 to the released source above. Existing tags and artifacts remain
immutable; later documentation changes on main do not change that source.

The release has no independent human security audit or adopter approval.
Provider-specific production acceptance, real host authorization, retention and
representative staging remain the deployment owner's responsibilities.

## Historical load evidence — 2026-10-14

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

At the time of this benchmark, host/provider acceptance and independent review
remained open. Subsequent sandbox and full-demo restore evidence is linked from
[the release criteria](RELEASE_CRITERIA.md). Independent human review and
representative customer staging remain open; the synthetic benchmark itself
does not complete those tasks.
