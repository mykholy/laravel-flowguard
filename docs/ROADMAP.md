# Roadmap (no release promise)

## Phase A — trustworthy kernel [pre-alpha]

- [x] Explicit operation states and owner-only claim capability
- [x] Durable uniqueness and immutable request fingerprint
- [x] Conditional finalization and unknown-outcome state
- [x] Laravel discovery and migrations
- [x] PHP 8.3/8.4 and MySQL/PostgreSQL initial CI coverage
- [ ] Worker-kill fault injection and larger stress runs
- [ ] Benchmark write path and validate operational cost

## Phase B — safe reconciliation [partial]

- [x] Read-only stale operation inspector (limited results)
- [x] Reconciler contract and typed, bounded evidence
- [x] Opt-in evidence journal with application authorization and transactional append
- [x] No raw provider-reference storage in journal records
- [ ] Authorization and tenant isolation end-to-end example
- [x] Initial conflict-aware CAS classification with mandatory host-app approval contract
- [ ] Built-in approval collection is intentionally out of scope; validate a real host implementation
- [x] Initial provider evidence freshness guards and independent-process review race tests
- [ ] Extended stress and crash injection for all supported engines
- [ ] Evidence retention, privacy, and archival policy

## Phase C — ecosystem

- [ ] Optional Laravel Queue / Spatie Webhook Client adapters
- [ ] Transactional outbox / inbox with documented at-least-once delivery
- [ ] Optional UI without forced Filament or Livewire dependency
- [ ] Sandbox-only example integrations and versioned docs

## Release gate

No v1.0 before multi-database failure injection, explicit recovery design,
security review, version compatibility proof and an independent adopter.
