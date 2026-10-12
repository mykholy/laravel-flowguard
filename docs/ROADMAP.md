# Roadmap (no release promise)

## Phase A — trustworthy kernel [partial]

- [x] Explicit operation states and claim capability
- [x] Durable uniqueness and immutable request fingerprint
- [x] Conditional finalization and unknown-outcome state
- [x] Laravel auto-discovery and migration
- [x] Unit and SQLite feature test definitions
- [ ] Run dependency-powered feature suite in CI
- [ ] Concurrency integration tests against MySQL and PostgreSQL
- [ ] Crash/fault injection and DB outage tests
- [ ] Benchmark write path and validate operational cost

## Phase B — manual recovery

- [x] Read-only stale-operation inspector (limited results; no pagination yet)
- [ ] Reconciler contract and provider-neutral resolution outcomes
- [ ] Immutable operation timeline
- [ ] Authorization and two-step replay approvals
- [ ] Retention, privacy, encryption guidance

## Phase C — ecosystem

- [ ] Optional Laravel Queue and Spatie Webhook Client integrations
- [ ] Outbox / inbox adapters with delivery guarantees documented
- [ ] Optional user interface (no forced Filament / Livewire dependency)
- [ ] Example integrations (sandbox only)
- [ ] Versioned documentation, migration upgrade test suite

## Release gates

No `v1.0` before multi-database concurrency verification, failure injection,
security review, version support matrix and at least one independent adopter.
