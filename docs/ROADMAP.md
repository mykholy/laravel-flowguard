# Roadmap (no release promise)

## Phase A — trustworthy kernel [pre-alpha]

- [x] Explicit operation states and owner-only claim capability
- [x] Durable uniqueness and immutable request fingerprint
- [x] Conditional finalization and unknown-outcome state
- [x] Laravel discovery and migrations
- [x] PHP 8.3/8.4 and MySQL/PostgreSQL initial CI coverage
- [x] Worker-kill fault injection (reservation crash point)
- [ ] Larger stress runs and provider-accepted crash scenarios
- [x] Disposable database write-path benchmark
- [ ] Representative production capacity and regression thresholds

## Phase B — safe reconciliation [partial]

- [x] Read-only stale operation inspector (limited results)
- [x] Reconciler contract and typed, bounded evidence
- [x] Opt-in evidence journal with application authorization and transactional append
- [x] No raw provider-reference storage in journal records
- [x] Host-authorization tenant isolation regression fixture
- [ ] Independently reviewed production authorization integration
- [x] Initial conflict-aware CAS classification with mandatory host-app approval contract
- [ ] Built-in approval collection is intentionally out of scope; validate a real host implementation
- [x] Initial provider evidence freshness guards and independent-process review race tests
- [ ] Extended stress and crash injection for all supported engines
- [ ] Evidence retention, privacy, and archival policy

## Phase C — ecosystem

- [ ] Optional Laravel Queue / Spatie Webhook Client adapters
- [x] Transactional outbox recording boundary with MySQL/PostgreSQL tests
- [x] Initial reservation, fencing, acknowledgement and bounded retry primitives
- [x] Independent-process reservation contention and crash-point tests
- [x] Single-delivery publisher contract and orchestration (host-supplied publisher)
- [ ] Production publisher example, timeouts and consumer idempotency validation
- [x] Durable inbound event inbox (verified-event acceptance boundary)
- [ ] Optional UI without forced Filament or Livewire dependency
- [ ] Sandbox-only example integrations and versioned docs

## Release gate

No v1.0 before multi-database failure injection, explicit recovery design,
security review, version compatibility proof and an independent adopter.
