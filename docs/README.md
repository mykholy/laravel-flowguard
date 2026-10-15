# FlowGuard documentation

FlowGuard is a Laravel library for durable operation ownership, verified-event
receipts and transactional message handoff. Begin with one application workflow;
you do not need to enable reconciliation or manual resolution to use the outbox.

## First integration

1. [Getting started](GETTING_STARTED.md): install, configure and choose a primitive.
2. [Public API](PUBLIC_API.md): supported calls and compatibility promises.
3. [Checkout demo](../examples/checkout-demo/README.md): a runnable host with
   signed webhooks, provider timeouts, process exits and recovery exercises.

## Choose your task

| I need to… | Guide |
| --- | --- |
| Guard a repeated operation | [Operation claims](GETTING_STARTED.md#guard-an-operation) and [core design](RFC-001-CORE.md) |
| Receive a verified webhook safely | [Durable inbox](INBOX.md) |
| Commit a business write and event together | [Recording example](GETTING_STARTED.md#record-a-business-event) and [outbox contract](RFC-003-OUTBOX.md) |
| Deliver messages with my own transport | [Publishing](PUBLISHING.md) and [lease lifecycle](RFC-003-LEASING.md) |
| Inspect an unknown provider outcome | [Read-only reconciliation](RFC-002-READ_ONLY.md) |
| Keep reduced reconciliation observations | [Evidence journal](RFC-002-EVIDENCE-JOURNAL.md) |
| Classify an outcome after host approval | [Manual resolution](RFC-002-MANUAL-RESOLUTION.md) |

## Operate an integration

| Task | Guide |
| --- | --- |
| Investigate stalled operations and delivery failures | [Operations runbook](OPERATIONS.md) |
| Enforce server-derived tenant identity | [Tenant boundaries](TENANT_BOUNDARIES.md) |
| Review failure and trust boundaries | [Threat model](THREAT_MODEL.md) and [security policy](../SECURITY.md) |
| Preserve durable identities and plan retention | [Retention](RETENTION.md) |
| Upgrade or inspect release artifacts | [Package release procedure](PACKAGE_RELEASE.md) |
| Rehearse database and host recovery | [Native restore drills](NATIVE_RESTORE_DRILL.md) |

## Verify the behavior

- [Stable release evidence](STABLE_READINESS.md): exact-source publication and
  historical synthetic load results, with their limits.
- [Concurrency testing](CONCURRENCY_TESTING.md): independent SQL worker setup.
- [Stress testing](STRESS_TESTING.md) and [benchmarks](BENCHMARKS.md): workload
  definitions and measured results, rather than a universal capacity promise.
- [HTTP provider fixture](HTTP_PROVIDER_FIXTURE.md): local failure simulation.
- [Provider staging pilot](PROVIDER_STAGING_PILOT.md): bounded actual sandbox evidence.
- [Release criteria](RELEASE_CRITERIA.md): library release gates and host acceptance.

## Design and future work

The RFCs describe design contracts and failure semantics; use the guides above
for your first implementation. [Roadmap](ROADMAP.md) tracks optional future work.
[Independent security review](INDEPENDENT_SECURITY_REVIEW.md) describes the open
review request. Stable versioning does not certify your host's provider adapter,
authorization policies or production capacity.

[Back to package overview](../README.md)
