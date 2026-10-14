# RFC-002, increment C: authorized manual outcome classification

**Supported v1 bookkeeping primitive. NOT a payment recovery, replay, refund, charge or
business-record repair API.**

This feature closes the *Flowguard operation bookkeeping status* after an
application independently verifies a provider outcome and independently records
approval from two authenticated human principals.

## State machine

    processing -> completed                         (original owner)
              -> requires_reconciliation            (original owner)
                    -> reconciled_success           (approved decision)
                    -> reconciled_failure           (approved decision)

The reconciled states are TERMINAL and not synonymous with successful
application-side persistence. They only assert that a human-approved external
observation was recorded. Neither state allows Flowguard to reissue the claim.

## Usage (opt-in, never auto-registered)

    $resolver = new \Mykholy\Flowguard\Reconciliation\DatabaseManualResolver(
        DB::connection(config('flowguard.connection')),
        app(App\Security\FlowguardResolutionAuthorization::class),
        (string) config('flowguard.table', 'flowguard_operations'),
    );

    $decision = $resolver->resolve(
        $operationId, $evidenceId, 'reviewer.one', 'reviewer.two',
        'approval.2026-42',
    );

The host app MUST implement ResolutionAuthorization, validate both independently
authenticated principals from trusted session/authorization state, and verify a
durable approval bound to the exact tenant, operation ID, evidence ID, outcome and
requested status. The actor and approval references alone are NOT credentials.
This library DOES NOT implement login, multi-tenant identity or two-person
approval collection. Never supply a permissive approval policy in production.

The approval policy executes INSIDE the database transaction while the operation
row is locked. It must use local, short, deterministic queries and NEVER call a
payment provider or another network service. Its approval record should share
the same database transaction boundaries or use immutable signed provenance.

## Fail-closed rules

- Row must still be requires_reconciliation; operation row is locked before
  checks, and the final update compares the expected status.
- Selected evidence must be the latest journal observation by timestamp; ties are rejected.
- Evidence must say confirmed_success or confirmed_failure, never inconclusive.
- Any opposing confirmed evidence, even older, blocks resolution entirely.
- Observation must be no older than 900 seconds (configurable constructor
  argument 1..86400) and at most 60 seconds ahead of DB transaction time.
- Initiator and approver references must be distinct and bounded; application
  independently verifies real identities and existing, bound approval.
- Decision record and final status write commit in the SAME transaction.
  The decision table enforces uniqueness per operation; a second decision fails.
- Results remain empty; flowguard cannot change the application's orders,
  ledger, captures, refunds or balances.
- A consumer MUST NOT treat reconciled_failure as approval to retry an external
  side effect. Do new business operations with provider-specific safeguards.

## Remaining release blockers

Expanded and repeated concurrency stress beyond the initial independent-process
MySQL/PostgreSQL test, database crash injection before/after decision audit write, provider
authenticity, production authorization/tenant isolation, lifecycle and retention
policies, and independent security review. The latest-evidence tie-breaker is
stable in a single transaction but same-microsecond ordering may reject valid
evidence, which is an intentionally fail-closed behavior. No production claim.
