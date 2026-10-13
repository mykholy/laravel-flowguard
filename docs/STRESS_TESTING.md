# Outbox contention stress harness

This suite launches real PHP worker processes against a disposable MySQL 8 or PostgreSQL 16 database. Workers reserve and acknowledge records through the public leasing API.

The test verifies that every message is acknowledged, no worker fails, and every record has exactly one local delivery attempt. It measures elapsed time and throughput. It does not publish to an external provider or guarantee exactly-once remote side effects.

## Safety and execution

Both FLOWGUARD_CONCURRENCY_TEST=1 with FLOWGUARD_TEST_DATABASE=flowguard_ci and FLOWGUARD_STRESS_CONFIRM=disposable-only are required. The runner creates a randomly named table, then drops it.

The normal Laravel 12/13 CI runs 10 worker processes per database. To repeat locally, set the existing disposable database connection variables and run:

    FLOWGUARD_CONCURRENCY_TEST=1 FLOWGUARD_TEST_DATABASE=flowguard_ci FLOWGUARD_STRESS_CONFIRM=disposable-only FLOWGUARD_STRESS_WORKERS=10 FLOWGUARD_STRESS_MESSAGES_PER_WORKER=2 php tests/Stress/run.php

The runner accepts only 10, 100 or 500 workers, with 1..10 preseeded messages each. For 100/500 process tests, use a dedicated environment with adequate PHP process, memory and SQL connection limits; do not run them on production databases. Report database lock waits, deadlocks, CPU/memory, disk pressure and thresholds separately.

Even a successful 500-process test does not prove production readiness without repetition, representative hardware, realistic transport latencies and fault injection. The JSON output is an inspectable correctness and wall-clock baseline, not a capacity guarantee.
