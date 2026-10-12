# Contributing

Flowguard aims for correctness and safety, not feature count. Before a PR, document
which failure scenario or concurrency invariant the change improves. Include tests
for the happy path, duplicate requests, crash/timeout, and unauthorized transition
where relevant. Make no breaking changes without an RFC. Follow `composer test`,
`composer analyse` and `composer format-check`; if tools were not run, state so.
Avoid logging real customer data, tokens, idempotency keys, or claim capabilities.
