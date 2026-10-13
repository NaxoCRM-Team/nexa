# Phase 6 schema installation acceptance

Run from the repository root on supported native MariaDB:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File scripts/dev/verify-phase-6-installation.ps1
```

Optional connection parameters are `-ClientPath`, `-DatabaseHost`, `-Port`, `-User`, and `-Password`. The default root password is explicitly empty; this test does not read or modify application configuration or `.env`.

The script creates two unique `nexa_p6_acceptance_<random>_clean/upgrade` databases. It imports the checked-in native EspoCRM base schema and uses the existing shared-schema runner for the current migrations. The upgrade path starts with migration 0074, synthetic development seeds, an existing Contact, and nonzero marketing/transactional usage counters.

Acceptance checks cover current migration checksums, replay without ledger changes, rejection of a changed applied checksum, rejection of base initialization against a populated database, transactional service registration without automatic tenant grants, reservation and Postal inspection columns, composite tenant/service foreign keys, replay uniqueness, governed experiment tables and ownership, preservation of existing records and counters, and clean/incremental column-schema equivalence. Both owned databases are removed on success or failure. The live `espocrm` database is never a target.

This is database installation evidence, not proof of the installation UI, public Postal delivery, or the complete Phase 6 runtime exit gate. The final message names the last migration actually tested.

## Recorded evidence

On 12 October 2026, native WAMP MariaDB 11.4.9 passed the clean base-schema installation and the incremental 0074-to-0078 upgrade with all assertions above, including the persisted workflow A/B assignment's scoped version foreign key and replay primary key. The strengthened final runner exited successfully, and a subsequent read-only check found zero disposable acceptance databases. No application configuration, production credentials, or live records were changed.

The clean path uncovered and fixed a scalar conversion bug in `apply-shared-schema.ps1`: the empty-database table count was an array rather than its scalar result. Acceptance now exercises both successful empty initialization and rejection of initialization against an already populated database.
