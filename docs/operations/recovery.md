# Backup And Recovery

## Scope

The Phase 8 local drill checks database backup/restore mechanics, restored
migration checksums, per-table row counts and two tenant-scoped Contact sentinels.
It does not establish production disaster recovery, object-store restoration,
per-tenant restoration or recovery-time/recovery-point guarantees.

```powershell
Set-Location C:\wamp64\www\nexa
powershell -ExecutionPolicy Bypass -File scripts/dev/verify-recovery.ps1
```

The drill creates two uniquely named scratch databases, initializes the current
schema and synthetic seeds, dumps one and restores into the other. It checks
the restored snapshot and replays the migration runner to verify no changes.
Both scratch databases are dropped in `finally`; it never accepts `espocrm` as
a source or destination. Synthetic dump and JSON evidence remain under ignored
`test-results/recovery-*`. Non-default installations can supply matching client,
dump, port and user parameters. Do not put real passwords in shell history.

## Production Backup Set

Back up the following as one versioned recovery set, outside Git:

- A consistent full MariaDB backup, including schema, records, migration ledger,
  triggers, routines and events. A tenant export is not a database backup.
- Private attachment bytes: local uploads and all configured Cloudflare object
  stores. Preserve the object keys, native Attachment IDs and storage mapping.
- Exact application release/commit and dependency versions.
- Deployment configuration and the complete historical encryption key ring.
  Store keys separately from database ciphertext with restricted access.
- Infrastructure configuration, scheduler definitions and provider callback
  configuration, without leaking secrets into the incident record.

Encrypt backups before off-host transfer; restrict access, record checksums,
retain immutable copies and monitor failed/missed backups. Operators must define
the approved retention, maximum data-loss window and recovery-time target before
production. None of these policies is automatically provisioned by the local drill.

## Restore Procedure

1. Record the incident, chosen restore point and release. Stop application writes,
   cron and queue workers. Preserve a forensic copy before changing anything.
2. Restore into a new isolated database and private storage namespace, never over
   the only surviving copy. Keep provider dispatch, callbacks and scheduled jobs
   disabled; restored outbox rows must not send real messages during inspection.
3. Restore the matching files, configuration and historical key ring. Validate
   backup checksums and confirm secrets decrypt without printing plaintext.
4. Compare table/record counts, tenant/service ownership, foreign keys, migration
   checksums and Attachment metadata against object existence. Sample authorized
   downloads and cross-tenant denials. Test missing-file behavior as well.
5. Start the matching application release. Apply only required migrations through
   the checksum-ledger runner, rebuild metadata and refresh frontend assets.
6. Verify tenant login, CRM records, conversation history, file previews/downloads,
   consent/DNC and roles. Inspect pending/uncertain provider submissions against
   the provider before enabling retries: a database rollback can replay sends.
7. Approve cutover, switch application configuration atomically and enable workers
   gradually. Monitor delivery, webhooks, queues and file access; preserve a
   documented rollback target until acceptance is signed off.
8. Record actual data loss and elapsed recovery time against the approved targets.

Deleting live data does not automatically erase backup copies. Retention, legal
holds and eventual backup expiry must be documented separately. Do not restore
one tenant by importing a whole shared database over other tenants; selective
tenant recovery needs its own dependency-aware tooling and acceptance.

## External Acceptance Still Required

- Encrypted off-host backup and restoration using actual deployment infrastructure.
- Cloudflare file/object restore and private authorized downloads after recovery.
- Dispatch deduplication/reconciliation against real providers after rollback.
- Measured recovery point/time and infrastructure failure/cutover rehearsal.

Until these are demonstrated, production disaster recovery remains unverified.

## Local Evidence: 21 October 2026

The disposable drill passed after applying migrations 0001-0109. Restored schema
table counts, per-table row counts, both tenant Contact sentinels and migration
IDs/checksums/timestamps matched the source snapshot. A second migration run
skipped all 109 files without changing the snapshot; both scratch databases were
removed. JSON evidence and the synthetic dump are under ignored
`test-results/recovery-327b093d2bf2409d955d9cfd8f9dcf0d/`.

An earlier run detected an unexpected ledger replay. The runner now requires one
valid table-count response and one explicit checksum/missing marker before it
decides whether to apply a file. Empty or malformed output fails closed. Three
fake-client regression checks passed without connecting to a database. This
guards against ambiguous client output; it does not claim the earlier transient
response was reproduced or that production recovery has been established.
