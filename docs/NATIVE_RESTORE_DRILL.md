# Native backup / restore rehearsal

The native-restore GitHub Actions workflow performs an actual MySQL 8
mysqldump/mysql restore or PostgreSQL 16 pg_dump/psql restore, not a JSON
copy. It uses two disposable databases: flowguard_ci and flowguard_restore_ci.

The prepared outbox fixture contains three different delivery states:
pending (not yet attempted), leased (two attempts, unexpired owner token
hash) and delivered (acknowledged). The workflow restores its schema, unique
index and records into the second database, checks all states and then
reserves and acknowledges only the pending message. The leased and delivered
rows must remain unchanged. Any missing record or incorrect transition
fails CI.

Run only in disposable CI services. Both FLOWGUARD_CONCURRENCY_TEST=1 and
FLOWGUARD_RESTORE_CONFIRM=disposable-only are required, with the source
database exactly flowguard_ci and restore target exactly flowguard_restore_ci.
The verifier refuses any other database names. No application or payment
provider is contacted.

The workflow also provisions a **clean Laravel 13 host application**, installs
Flowguard from the current checkout, applies the actual package migrations,
and populates every Flowguard table using the public operation, evidence,
resolution, inbox and outbox APIs. It exports and restores the **entire**
disposable database. On the restored copy it verifies real package foreign
keys, audited resolution and inbound event linkage, and correct recovery of
pending/leased/delivered message states.

## Not covered

- A live application's consistent backup across order tables and outbox
  records taken in the same transaction snapshot.
- PITR / binlog / WAL recovery, credentials/secrets, or encryption at rest.
- Restore elapsed-time targets, multi-gigabyte datasets or active writers.
- Restoring into a running production cluster without stopping publishers.

The first stable release still requires backup/restore on a **representative
staging application**, including application-owned tenant data, gateway
configuration and independently reviewed privacy/retention requirements.
A disposable single-process test does not prove production recovery-point or
recovery-time objectives. Never use these fixture credentials outside CI.

## Linked audit-record restoration

The drill also exports and restores a small operation/evidence/resolution graph
using real foreign-key relationships and a unique resolution-per-operation
constraint. The post-restore check verifies all three identities and that
attempting to delete the referenced operation is rejected by the restored SQL
foreign key. This catches native dump/import problems that a single-table
snapshot would miss.

The original relational fixture remains a focused foreign-key regression.
The additional full-schema test uses all current Flowguard migrations and
tables, but still only test-sized data and a disposable Laravel application.
It is not a production host-application restore or a security assessment.
