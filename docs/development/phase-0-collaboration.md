# Phase 0 Collaboration and Database Workflow

## Objective

Both developers must be able to clone one repository, create equivalent environments, apply the same database structure, load safe development data and verify the same behavior. Docker, XAMPP and WampServer are launch methods, not different architectures.

The approved technical direction should follow the [EspoCRM SaaS Architecture Recommendation](../architecture/espocrm-saas-architecture-recommendation.md).

## Version Baseline

| Component | Baseline | Notes |
|---|---|---|
| EspoCRM | 9.1.9 | Pinned; never use `latest` |
| PHP | 8.2.x initially | Match required extensions |
| MariaDB | 10.11 baseline; 10.11 or 11.x native | Docker and CI declare 10.11; native setup validates supported client and server versions |
| Composer | 2.x | Commit lock files for Nexa-owned services |

XAMPP's bundled MariaDB 10.4 is below the supported minimum. An XAMPP developer should run MariaDB 10.11 or 11.x separately, or connect Apache/PHP to the project database container. XAMPP does not require using its bundled database.

## Repository Workflow

1. Create the public GitHub repository and protect `main` with a ruleset.
2. Each developer clones it; never exchange edited folders or database files.
3. Use one short-lived branch per requirement, such as `feature/M05-contact-associations`.
4. Apply migrations and checks before opening a pull request.
5. The other developer reviews every pull request.
6. Merge only when code, migration, tests and documentation agree.

Never commit `.env`, credentials, cache, attachments, logs, runtime configuration or customer data. Application source, bundled client assets and pinned runtime dependencies are shared through Git.

## Local Database Layout

| Database | Purpose |
|---|---|
| `espocrm` | One local shared schema containing Espo core, Nexa SaaS tables and at least two synthetic tenants |
| `espocrm_test` | Disposable shared-schema database created by automated tests |

Developers do not share a development database server or volume. Each developer owns an independent local database with the same migration sequence and synthetic tenant fixtures. Git synchronizes definitions; migrations synchronize installations; seeders synchronize safe examples. Shared staging is used only after local review.

## Schema Change Rules

### Espo-Owned Entities

- Define fields, entities, relationships, indexes, layouts and scopes in Nexa custom metadata.
- Run Espo rebuild after metadata changes.
- Commit custom metadata and tests, not generated database/cache files.
- Use explicit shared-schema migrations for tenant columns, service columns, backfills, composite indexes and cross-table integrity.

### Nexa-Owned Data

- Put coordinated schema migrations in `database/shared/migrations/`.
- Classify every affected table before adding tenant or service scope.
- Use immutable sortable names such as `0002_add_subscription_period.sql`.
- Never edit a migration after merge; add another migration.
- Prefer expand/migrate/contract: add, backfill, switch readers, then remove later.

### Seed Data

- Commit only synthetic, non-personal fixtures under `database/*/seeds/` or the idempotent demo tenant provisioner.
- Never commit development or production dumps.
- Keep encrypted backups outside Git.

## Database Pull Request Checklist

- Requirement and owning module are identified.
- Migration succeeds on clean and previous schemas.
- Re-running the migration tooling is safe.
- Existing records receive valid defaults or a backfill.
- Index and query effects are reviewed.
- Tenant isolation, permissions and audit behavior are tested.
- Fixtures contain no real people, customers or credentials.
- Forward-fix and operational recovery are documented.

## Docker Developer

```powershell
Copy-Item .env.example .env
docker compose up -d
powershell -ExecutionPolicy Bypass -File scripts/dev/apply-shared-schema.ps1 -Mode Docker -IncludeDevelopmentSeeds
powershell -ExecutionPolicy Bypass -File scripts/dev/provision-demo-tenants.ps1 -Mode Docker
docker compose exec espocrm php rebuild.php
docker compose exec espocrm php clear_cache.php
powershell -ExecutionPolicy Bypass -File scripts/dev/check-environment.ps1
```

## XAMPP Developer

1. Clone the same repository with XAMPP PHP 8.2 and MariaDB 10.11 or 11.x available.
2. Configure the `nexa.local` Apache virtual host and hosts entry.
3. Run `scripts/dev/setup-native-windows.ps1` with the PHP and MariaDB client paths.
4. The command creates `.env`, database, application configuration, administrators, demo data and installed marker.
5. Confirm the shared login loads and `/install` redirects away.
6. Follow [XAMPP Development Setup](xampp-setup.md) for the complete procedure and acceptance checks.

Do not copy Docker volumes into XAMPP or exchange phpMyAdmin exports for daily synchronization.

## WampServer Developer

1. Clone the same organization repository under `C:\wamp64\www\nexa` and select PHP 8.2.x with MariaDB 10.11 or 11.x.
2. Enable the required extensions and configure the `/nexa` Apache alias without a virtual host.
3. Run `scripts/dev/setup-native-windows.ps1` with the PHP and MariaDB client paths.
4. The command creates `.env`, database, application configuration, administrators, demo data and installed marker.
5. Confirm the shared login loads and `/install` redirects away.
6. Follow [WampServer Development Setup](wampserver-setup.md) for the complete procedure and acceptance checks.

Do not copy WampServer database files, another developer's database, or Docker volumes into the local installation.

## Phase 0 Exit Checklist

- [ ] Public Git remote exists and both developers can clone it.
- [ ] `main` is protected and review is required.
- [ ] PHP and MariaDB baselines match.
- [ ] Both developers start EspoCRM 9.1.9 from a clean checkout.
- [ ] Both developers apply an identical migration set.
- [ ] Both developers load identical synthetic fixtures.
- [ ] CI checks PHP, JSON, metadata and migration filenames.
- [ ] Clean and upgrade smoke tests cover core CRM records.
- [ ] Both developers review ADR-0002 and the shared-schema tenant isolation contract.
- [ ] Two synthetic tenants prove authentication, CRUD, relationship, report and job isolation.

Phase 1 starts only after this checklist is green.
