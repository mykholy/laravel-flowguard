# Independent-worker outbox contention

This harness exercises **real PHP processes** against a disposable MySQL 8 or
PostgreSQL 16 database, not SQLite or an in-memory mock.

## Repeatable workloads

- Every process waits for a shared start gate before acquiring its lease.
- One distinct pending outbox event is seeded per process.
- Each process reserves one event and acknowledges it with its own fenced token.
- The parent asserts **N unique IDs, N delivered rows, N total attempts**.
- Results include p50/p95/p99 process latency, wall time and failure classes.
- Tables are isolated by random names and deleted after the run.

Automated CI runs ten workers for each Laravel 12/13 × MySQL/PostgreSQL
combination. The separate **Outbox Stress (manual)** workflow exposes
10, 100 and 500 independent-process workloads on both engines.

To run on an isolated local database:

```bash
FLOWGUARD_CONCURRENCY_TEST=1 \
FLOWGUARD_TEST_DATABASE=flowguard_ci \
FLOWGUARD_TEST_DRIVER=pgsql \
FLOWGUARD_TEST_HOST=127.0.0.1 \
FLOWGUARD_TEST_PORT=5432 \
FLOWGUARD_TEST_USERNAME=flowguard \
FLOWGUARD_TEST_PASSWORD=flowguard \
FLOWGUARD_STRESS_WORKERS=10 \
php benchmarks/lease-contention.php
```

For over 50 processes, explicitly set
`FLOWGUARD_STRESS_LARGE_RUN=1`. The script **refuses** databases other than
`flowguard_ci`; it does not direct traffic to an application server.

## Interpreting results

This is a correctness and first-order contention benchmark, **not a
production capacity estimate**. Every worker performs a local reserve and
acknowledgement, without HTTP, broker integration or mutable business writes.
Concurrent processes can exceed database max-connections or hosted-runner
process/memory limits, especially at 500; failure is recorded rather than
treated as success. CPU, RAM, DB parameters, process caps and hardware must
be recorded alongside representative staging benchmarks before an SLO is
established. Reservations use `FOR UPDATE SKIP LOCKED` on MySQL and PostgreSQL, so
workers can claim later rows instead of blocking behind the first locked
message. Contention, DB connection limits and fair distribution are still
measured, not assumed.

A clean ten-worker result alone does **not** close the v1.0.0 100/500-worker
release gate. Review every database/workload result before setting acceptance
thresholds.

## Synchronized start and retained evidence

Workers now bootstrap PHP and open their SQL connections **before** reporting
readiness. The parent opens the start gate only after every worker is ready;
a worker exit or a 180-second readiness timeout invalidates the workload.
This avoids reporting a nominal 500-worker run whose processes started their
work at different times during PHP bootstrap.

The stress workflow defaults to three repetitions. Changes to the harness run
automatically on pull requests at 100 and 500 workers on each engine; manual
dispatch selects a single workload. Disposable service connection ceilings are
explicitly set to 620 so 500 simultaneous SQL connections can actually become
ready. Production connection limits are never changed. Explicit Bash pipefail propagates benchmark failures
through the output capture pipeline. It retains JSON results,
`/usr/bin/time -v` process resource summaries, and database counter snapshots
before/after the workload. Worker output includes reserve poll counts and peak
PHP allocated memory. The reported maximum is per worker, not aggregate RAM.
The synchronized delivery wall time excludes process startup; overall wall time
includes it. Latency includes connection retrieval, reservation and acknowledgement.

Database counters are aggregate diagnostics: MySQL reports instance-level lock
wait/deadlock/connection counters; PostgreSQL reports database-level deadlocks,
conflicts and transaction counters. They may include other activity and are not
per-query lock-wait measurements. Resource summaries are process measurements,
not continuous database CPU/memory sampling. A failed connection ceiling is a
failed workload, not a successful reduced-concurrency run.

Correctness thresholds remain predefined: all N workers must succeed, with N
unique deliveries, N delivered rows and exactly N reservation attempts. Review
p95/p99 and resource use against **host-specific** budgets declared before a
representative staging run. These disposable runner artifacts alone do not close
the production stress gate.

## Large-workload acceptance for disposable CI

Before execution the CI harness declares a 10,000 ms p99 budget, zero failed
workers, N unique delivered rows and exactly N total lease attempts. It runs
three repetitions for 100 and 500 simultaneous workers on each SQL engine and
retains resource/counter artifacts for 90 days. A latency failure is reported
as failure; the budget is not increased after observing a slow run.

These thresholds validate this synthetic queue workload on its runner. They
are not application SLAs, provider acceptance, continuous lock-wait tracing or
a substitute for the representative staging workload required for stable 1.0.
