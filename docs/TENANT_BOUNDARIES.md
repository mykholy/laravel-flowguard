# Tenant-scoped authorization integration

Flowguard stores caller-supplied `scope` as an opaque identity partition.
A namespace such as `tenant.42:payments` is a **convention**, not an
authentication mechanism. No permission is granted merely by possessing
an operation ID, evidence ID or valid-looking scope.

## Application responsibilities

1. Authenticate the operator independently. Derive actor references and
   tenant identity from your authenticated identity service, never from
   untrusted request JSON, query strings or webhook headers.
2. Verify that the operation's stored scope belongs to the authenticated
   tenant. The contracts receive the stored `OperationSnapshot`; do not
   authorize using a caller-supplied scope alone.
3. Implement `EvidenceAuthorization` with a deny-by-default tenant and
   role check. Denial must throw, not return `false`.
4. Implement `ResolutionAuthorization` with a durable approval bound to
   the exact operation ID, evidence ID, confirmed outcome, tenant and two
   distinct authenticated actors. Validate expiry/revocation and membership
   at decision time. Approval tokens supplied by the caller are not proof.
5. Record only bounded machine-readable provider evidence. Never persist
   raw gateway credentials, request bodies, card data, or unredacted
   provider references in Flowguard.
6. Run approval checks on the same transactional database whenever their
   consistency is part of the decision; define a policy for concurrent
   approval revocation and concurrent tenant membership changes.

## Tested boundary

`TenantAuthorizationBoundaryTest` provides a deliberately small host-app
fixture with two tenants and a durable approval lookup. It demonstrates:

- Cross-tenant evidence writes fail without appending a journal row.
- An approval belonging to tenant 2 cannot finalize tenant 1's operation.
- Two authorized tenant-1 actors with an exact approval can finalize once;
  the decision row and state change commit together.

The fixture's membership map is hardcoded *test data*. It is **not** a
production authentication or RBAC implementation. Integrations must replace
it with real authenticated principals, tenancy scopes, revocation policy,
audited approvals and tests against their own data model.

The operation, inbox and outbox APIs now accept the same ASCII identifier
characters for scopes: `[a-z0-9:._-]`, 1–120 characters, starting with a
letter or digit. Reusing the same scope for different tenants is still an
application error.

See [threat model](THREAT_MODEL.md) and [release criteria](RELEASE_CRITERIA.md).

## Outbox worker isolation

Applications with per-tenant transport credentials should construct
`DatabaseOutboxLeaseStore` with the optional `scope` argument and a scope
derived from server-side tenant configuration. A scoped worker cannot reserve,
acknowledge or release rows outside that scope. The default unscoped store
is appropriate only where the publisher is authorized for every eligible
tenant. Tenant matching is an additional guard, not authentication.
