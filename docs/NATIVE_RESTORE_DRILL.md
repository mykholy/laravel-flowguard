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

This test is stronger than simply checking migration scripts: it proves
database-native data export, import and post-restore lease semantics for a
representative outbox table on both supported SQL engines.

## Not covered

- The entire package schema and all foreign-key relationships.
- A live application's consistent backup across order tables and outbox
  records taken in the same transaction snapshot.
- PITR / binlog / WAL recovery, credentials/secrets, or encryption at rest.
- Restore elapsed-time targets, multi-gigabyte datasets or active writers.
- Restoring into a running production cluster without stopping publishers.

The first stable release still requires a rehearsed full database restore
of a nonempty representative staging application, verification of data
integrity across all package tables, an audited encryption/retention policy
and independent sign-off. Never use the demo credentials outside disposable
GitHub Actions services.
