## Reproducible outbox recording benchmark

Run only against the disposable `flowguard_ci` database. The benchmark uses the
same guarded bootstrap as the independent-worker integration suite and refuses
other database names or missing explicit opt-in.

```bash
FLOWGUARD_CONCURRENCY_TEST=1 \
FLOWGUARD_TEST_DATABASE=flowguard_ci \
FLOWGUARD_TEST_DRIVER=mysql \
FLOWGUARD_TEST_HOST=127.0.0.1 \
FLOWGUARD_TEST_PORT=3306 \
FLOWGUARD_TEST_USERNAME=flowguard \
FLOWGUARD_TEST_PASSWORD=flowguard \
FLOWGUARD_BENCH_ITERATIONS=250 \
php benchmarks/outbox-record.php
```

Outputs p50/p95/p99 for a single outbox record in a local SQL transaction.
The benchmark creates and drops an isolated table and stores no customer data.
It does not publish messages, measure queue latency or simulate an external API.
CI runner numbers are useful for regressions, **not production SLOs**. A v1
capacity statement still requires repeated runs on representative infrastructure
with real service settings and concurrent traffic.
