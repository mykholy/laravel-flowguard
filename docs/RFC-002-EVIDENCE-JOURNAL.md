# RFC-002, increment B: authorized append-only evidence journal

**Supported v1 primitive. This feature is not payment recovery or provider verification.**

A provider Reconciler (introduced in PR #4) returns a read-only observation.
The application can persist a reduced audit record after its own actor
authentication and tenant-scoped authorization. The journal never completes
an operation and cannot trigger a refund, replay or capture.

## Opt-in API

The package intentionally does not auto-bind an allow-all policy. Register
your own implementation of EvidenceAuthorization and construct the journal
on the same connection where Flowguard operations live:

    use Illuminate\Support\Facades\DB;
    use Mykholy\Flowguard\Infrastructure\DatabaseEvidenceJournal;

    $journal = new DatabaseEvidenceJournal(
        DB::connection(config('flowguard.connection')),
        app(App\Policies\FlowguardEvidenceAuthorization::class),
        (string) config('flowguard.table', 'flowguard_operations'),
    );

    // $evidence came from your authenticated, read-only Reconciler.
    // $operationId was resolved within an authorized tenant context.
    $entry = $journal->append($operationId, $evidence, 'reviewer.42');

The application's policy must implement:

    public function assertCanRecord(string $actorReference, OperationSnapshot $operation): void
    {
        // Resolve the authenticated reviewer in the current tenant, verify role,
        // and throw on denial. Do not trust actorReference by itself.
    }

## Guarantees

- Checks the actual operation state under SELECT FOR UPDATE, then calls
  application authorization and inserts the event in the same transaction.
- Refuses missing, processing and completed operations. Leaves the operation
  in requires_reconciliation after a successful append.
- Persists outcome, evidence code, source, UTC observation time, operation ID,
  and opaque actor reference. Never persists the raw provider reference.
- No public mutation API for old evidence. FK restricts ordinary operation
  deletion when journal rows exist.
- No default allow policy is provided or bound to the Laravel container.

## Limitations and security considerations

- This is application-level append-only, not a tamper-proof ledger: a DBA
  with SQL write access can alter rows. No cryptographic audit claim is made.
- The application must validate provider evidence, prevent sensitive identifiers,
  authenticate actors and enforce tenant ownership. An actorReference string
  alone is NOT proof of identity.
- The policy runs inside a database transaction. Keep it short, deterministic,
  with no external network calls; benchmark and test the locking behavior.
- Operations and evidence must remain on the SAME database connection.
- No deduplication of repeated observations. Each append records an observation;
  no additional external side effect is implied.
- No automated recovery or resolution API exists. Human approval, stale
  evidence detection, CAS resolution and conflicting-evidence tests remain
  separate release blockers.
