# Nexa

Nexa is a unified customer platform for CRM, sales, marketing automation, customer engagement and analytics.

This repository supports development by the designated Nexa project team. The team is not currently accepting unsolicited feature contributions, implementation pull requests or public support requests.

## Product Areas

- Unified customer and company records with relationship, lifecycle and chronological history
- Sales pipelines, forecasting, activities, calendars, products and team productivity
- Customer support, operational email, cases, SLA management, knowledge and portals
- Consent, forms, landing pages, assets, segments, campaigns and marketing email
- Behavioral tracking, lead scoring, personalization and visual automation
- Messaging, conversations, social, advertising and provider integrations
- Reporting, dashboards, attribution and customer journey analytics
- SaaS administration, identity, plans, entitlements, usage and tenant operations

Product scope is controlled by the detailed [Unified Product Specification](docs/product/unified-product-specification.md), the [86 advanced capability and 70 SaaS NFR inventory](docs/product/feature-inventory.md), and the [Requirements Traceability Matrix](docs/product/requirements-traceability.md). A capability is implementation-ready only when it has module ownership, phase dependencies, acceptance criteria, data contracts and test evidence.

## System Architecture

Nexa uses a **modular monolith with supporting platform services**. It is not a full microservices architecture. The customer-facing product is one versioned PHP application with clear module boundaries, one release process and one shared user experience. Background workers use the same application contracts, while high-volume event and analytics workloads may use separate supporting infrastructure.

### Core Product Modules

- Platform core: configuration, feature flags, queues, schedulers, notifications, files, audit/outbox events, API/webhook conventions and observability.
- Unified CRM data platform: Customer 360 identity, accounts, contacts, leads, opportunities, lifecycle, relationships, customization, formulas and governed data operations.
- Sales, activity and calendar workspace: pipelines, forecasting, stage rules, tasks, calls, meetings, products, projects, documents and collaboration.
- Service and operational email: cases, queues, SLAs, escalation, knowledge, portals, SMTP/IMAP email and team support workflows.
- Marketing foundation: consent, forms, landing pages, assets, segments, audiences, campaigns and marketing events.
- Customer timeline and events: anonymous/known identity resolution, behavioral tracking, canonical history and versioned event ingestion.
- Marketing email and automation: editors, templates, deliverability, visual workflows, branching, retries, scoring and personalization.
- Engagement channels: conversations, bots, shared inboxes, SMS, WhatsApp, social, advertising and provider adapters.
- Analytics: governed reports, dashboards, funnels, attribution, customer journeys and domain metrics.
- Identity and SaaS administration: users, teams, roles, permissions, MFA/SSO, tenants, plans, entitlements, usage, billing and operator controls.

### Architecture Diagram

![Nexa shared-schema modular monolith architecture showing tenant routing, automatic ORM scope, product modules, one shared database, workers and supporting infrastructure](docs/assets/nexa-system-architecture.svg)

Transactional data uses a **shared-schema multi-tenant model**. EspoCRM core tables, Nexa modules and SaaS administration tables live in one MariaDB schema. Every customer-owned row is protected by mandatory `tenant_id` scope; service-owned records and entitlements additionally use `service_id`.

## Supporting Infrastructure

Supporting infrastructure will be introduced as product scale and operational requirements justify it:

- Redis for cache, queues, distributed locks and rate limits.
- Object storage for attachments, imports, exports and media.
- OpenSearch for global search and high-volume event queries.
- An analytics database with Metabase or equivalent reporting tools.
- Dedicated workers for marketing email, automation, imports, webhooks and scheduled processing.

These components support the modular monolith and do not change its core architecture. Infrastructure adoption must include tenant isolation, monitoring, backup, security and local-development support.

## Team Documentation

Team members should read the documents relevant to their work before modifying shared contracts:

- [Unified product specification](docs/product/unified-product-specification.md): detailed CRM, sales, service, marketing automation, data, API and deliverable requirements.
- [Requirements traceability matrix](docs/product/requirements-traceability.md): requirement namespaces, module ownership, phase mapping and dependency gates.
- [Feature inventory](docs/product/feature-inventory.md): 86 advanced capabilities and 70 non-functional SaaS requirements.
- [Module and build roadmap](docs/product/module-build-roadmap.md): module ownership, dependency order and delivery phases.
- [SaaS architecture recommendation](docs/architecture/espocrm-saas-architecture-recommendation.md): recommended product and data architecture.
- [Shared-schema tenancy ADR](docs/architecture/ADR-0002-shared-schema-multitenancy.md): accepted tenant-isolation decision.
- [Superseded database-per-tenant ADR](docs/architecture/ADR-0001-tenant-database-isolation.md): retained decision history.
- [SaaS data architecture](docs/architecture/saas-data-architecture.md): tenant scope, services, migration and operational model.
- [Unified customer data contract](docs/architecture/unified-customer-data-contract.md): Customer 360 primitives, migration slices and database acceptance gates.
- [Existing schema audit and requirement map](docs/architecture/existing-schema-audit.md): Current-table inventory and retain, extend or new decisions for all 47 specification areas.
- [Development collaboration](docs/development/phase-0-collaboration.md): shared code and database workflow.
- [Environment baseline](docs/development/environment-baseline.md): required versions and extensions.
- [Git workflow](docs/development/git-workflow.md): branches, commits, pull requests and releases.
- [Delivery management](docs/development/delivery-management.md): Project fields, milestones, sprints, labels and issue workflow.
- [Release process](docs/development/release-process.md): version changes, tags and GitHub Releases.
- [XAMPP setup](docs/development/xampp-setup.md): detailed Windows/XAMPP installation.
- [WampServer setup](docs/development/wampserver-setup.md): detailed Windows/WampServer installation.

## Development Baseline

| Component | Version |
|---|---|
| Application release | 9.1.9 |
| PHP | 8.2.x |
| MariaDB | 10.11 Docker/CI baseline; 10.11 or 11.x for native development |
| Docker Compose | v2 |

Changes to the baseline require an approved pull request that updates local setup, CI and compatibility checks together.

## Docker Setup

Prerequisites: Git, PowerShell 5.1+ and Docker Desktop in Linux-container mode.

```powershell
git clone https://github.com/NaxoCRM-Team/nexa.git
cd nexa
powershell -ExecutionPolicy Bypass -File scripts/dev/setup.ps1
```

The clone already contains the complete pinned application source and dependencies. The setup command creates ignored local credentials, validates the environment, waits for healthy application services, applies all checksum-tracked shared-schema migrations and development seeds, and provisions tenant-scoped demo CRM records.

No application archive is downloaded from the official website during normal Docker setup. Docker may automatically pull the pinned PHP/application runtime image and MariaDB image when they are not already present; `./espocrm` is then bind-mounted over `/var/www/html`, so the running application code is the exact version committed to this repository.

Open <http://localhost:8080>. Local administrator credentials are stored in the ignored `.env` file.

Development setup also creates two isolated demo tenants with separate local-only credentials in the ignored `.env` file. Each tenant receives its own synthetic accounts, contacts, leads, opportunities, tasks and meetings:

Both accounts sign in through <http://localhost:8080/login>. The application resolves the correct tenant from the submitted login identity:

- Tenant A uses `DEMO_TENANT_A_ADMIN_USERNAME` and `DEMO_TENANT_A_ADMIN_PASSWORD`.
- Tenant B uses `DEMO_TENANT_B_ADMIN_USERNAME` and `DEMO_TENANT_B_ADMIN_PASSWORD`.

Refresh the demo administrators and their idempotent tenant-scoped CRM fixtures:

```powershell
powershell -ExecutionPolicy Bypass -File scripts/dev/provision-demo-tenants.ps1 -Mode Docker
```

```powershell
docker compose ps
docker compose logs -f espocrm
docker compose down
```

`docker compose down` retains local application and database data. Do not add `--volumes` unless the local environment is intentionally being discarded.

## XAMPP Setup

XAMPP uses the same tracked application, schema, migrations and demo fixtures as Docker. No separate application archive or official-site download is required.

Install Git, PowerShell 5.1 or later, XAMPP with PHP 8.2, and MariaDB 10.11 or 11.x. Enable the PHP extensions and limits listed in the [complete XAMPP guide](docs/development/xampp-setup.md). Run the supported MariaDB server separately and keep XAMPP MySQL stopped.

### 1. Clone And Prepare

```powershell
Set-Location C:\xampp\htdocs
git clone https://github.com/NaxoCRM-Team/nexa.git
Set-Location nexa

$env:Path = "C:\xampp\php;$env:Path"
```

Locate the newest supported MariaDB client:

```powershell
$mariadb = Get-ChildItem 'C:\Program Files' -Directory -Filter 'MariaDB *' |
    ForEach-Object { Join-Path $_.FullName 'bin\mariadb.exe' } |
    Where-Object { Test-Path -LiteralPath $_ } |
    Sort-Object -Descending |
    Select-Object -First 1
if (-not $mariadb) {
    throw 'MariaDB 10.11 or 11.x is not installed.'
}

& $mariadb --version
```

### 2. Configure Apache

Open `C:\xampp\apache\conf\httpd.conf` and confirm these lines are enabled:

```apache
LoadModule rewrite_module modules/mod_rewrite.so
Include conf/extra/httpd-vhosts.conf
```

Add this virtual host to `C:\xampp\apache\conf\extra\httpd-vhosts.conf`:

```apache
<VirtualHost *:80>
    ServerName nexa.local
    DocumentRoot "C:/xampp/htdocs/nexa/espocrm"

    <Directory "C:/xampp/htdocs/nexa/espocrm">
        Options FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

As Administrator, add this entry to `C:\Windows\System32\drivers\etc\hosts`:

```text
127.0.0.1 nexa.local
```

Start Apache from the XAMPP Control Panel. Keep the XAMPP MySQL service stopped when the supported MariaDB server is running on port 3306.

### 3. Run The Complete Setup

```powershell
Set-Location C:\xampp\htdocs\nexa
powershell -ExecutionPolicy Bypass -File scripts/dev/setup-native-windows.ps1 `
  -PhpPath 'C:\xampp\php\php.exe' `
  -ClientPath $mariadb
```

This command creates `.env`, the database and application user, loads the base
schema and migrations, generates application configuration, creates the
bootstrap administrator, provisions both demo tenants and their records,
rebuilds the application, blocks the browser installer and verifies the login.
If the generated MariaDB root password is not valid, the command securely
prompts for the current local root password.

Both demo accounts use <http://nexa.local/login>. Their usernames and passwords are the `DEMO_TENANT_A_ADMIN_*` and `DEMO_TENANT_B_ADMIN_*` values in `.env`; the submitted identity selects the correct tenant.

### 4. Enable Scheduled Jobs

Create a Windows Task Scheduler job that runs the following command every minute:

```text
C:\xampp\php\php.exe C:\xampp\htdocs\nexa\espocrm\cron.php
```

Use the same Windows account that owns the local project files. Scheduled jobs are required for email queues, workflows and background processing.

See [XAMPP Development Setup](docs/development/xampp-setup.md) for PHP settings, Apache validation, updates, troubleshooting and acceptance checks.

## WampServer Setup

WampServer is the standard native Windows development environment. Clone the
repository into `C:\wamp64\www\nexa` and expose the application at
<http://localhost/nexa/> through the Apache alias documented in the complete
guide. This setup does not use a Nexa virtual host or Windows hosts-file entry.

Use Apache on port `80`, PHP 8.2.x, and WampServer MariaDB 10.11 or 11.x on port
`3306`. The API alias maps only the public application and client assets; the
repository's backend source, configuration, data, and vendor directories are
not exposed directly.

After configuring `C:\wamp64\alias\nexa.conf`, run:

```powershell
Set-Location C:\wamp64\www\nexa

$php = Get-ChildItem C:\wamp64\bin\php -Filter php.exe -File -Recurse |
    Where-Object { $_.VersionInfo.ProductVersion -like '8.2.*' } |
    Sort-Object FullName -Descending |
    Select-Object -ExpandProperty FullName -First 1

$mariadb = Get-ChildItem C:\wamp64\bin\mariadb -Filter mariadb.exe -File -Recurse |
    Sort-Object FullName -Descending |
    Select-Object -ExpandProperty FullName -First 1

powershell -ExecutionPolicy Bypass -File scripts/dev/setup-native-windows.ps1 `
  -PhpPath $php `
  -ClientPath $mariadb `
  -DatabaseHost 127.0.0.1 `
  -DatabasePort 3306 `
  -SiteUrl http://localhost/nexa
```

The command creates the database, loads the base schema and every migration,
generates machine-specific configuration, provisions both demo tenants and CRM
data, blocks the browser installer, rebuilds the application, and runs the
verification suites. Both demo administrators sign in through
<http://localhost/nexa/login/> using the ignored `.env` values. After sign-in,
the browser uses `http://localhost/nexa/w/{tenant-slug}/{route}`. The slug keeps
links understandable; the authenticated `TenantContext` remains the security
boundary and always overrides untrusted URL input.

See [WampServer Development Setup](docs/development/wampserver-setup.md) for the
required alias file, PHP settings, MariaDB selection, OAuth callbacks,
scheduled jobs, update workflow, troubleshooting, and acceptance checks.
## Signup Email And SMTP

Self-service signup activates a workspace through one secure email verification
link. SMTP credentials stay in the ignored `.env`; `.env.example` documents the
required contract without containing secrets.

```dotenv
SMTP_HOST=smtp.provider.example
SMTP_PORT=587
SMTP_SECURITY=TLS
SMTP_AUTH=true
SMTP_USERNAME=provider-user
SMTP_PASSWORD=provider-password
SMTP_FROM_EMAIL=verified-sender@example.com
SMTP_FROM_NAME=Nexa CRM
```

Docker maps these values into the system mail configuration. Apply changes with
`docker compose up -d --force-recreate espocrm daemon`. XAMPP and WampServer
apply them during `complete-local-setup.ps1`, or on demand with:

```powershell
& <path-to-php.exe> scripts/dev/configure-smtp.php --env=.env
```

When `SMTP_HOST` is empty, the configurator leaves system mail unchanged. The
localhost-only verification URL fallback may be used for development, but
`NEXA_SIGNUP_EXPOSE_VERIFICATION_URL` must be `false` outside local environments.

## Repository Structure

```text
nexa/
|-- .github/                 GitHub Actions, issue forms and PR template
|-- database/                Versioned SQL migrations and synthetic seeds
|   `-- shared/              Shared Espo and SaaS schema migrations
|-- docs/
|   |-- architecture/        Architecture reports and decision records
|   |-- development/         Environment, Git and collaboration guides
|   `-- product/             Unified specification, traceability, feature inventory and roadmap
|-- downloads/               Ignored recovery release/upgrade packages
|-- espocrm/                 Complete versioned application codebase
|   |-- application/Espo/    Existing PHP backend framework and modules
|   |-- client/              Existing browser application resources
|   |   `-- custom/          Nexa frontend JavaScript, CSS, templates and assets
|   |-- custom/              Nexa backend PHP, metadata and server modules
|   |-- data/                Local runtime config, cache and logs; never commit
|   |-- public/              Public HTTP entry points and web assets
|   `-- vendor/              Pinned PHP runtime dependencies
|-- scripts/dev/             Setup, bootstrap, environment and verification tools
|-- .env.example             Shareable environment-variable template
|-- compose.yaml             Docker development services
`-- README.md                Project onboarding and structure
```

### Nexa Backend Customization

Backend changes belong under `espocrm/custom/`:

```text
espocrm/custom/Espo/Custom/
|-- Classes/                 Nexa PHP services, controllers, hooks and jobs
`-- Resources/
    |-- metadata/            Entities, fields, scopes, routes and client registration
    `-- i18n/                Backend and shared translations
```

Only directories needed by current features exist today. Create new directories as modules are implemented, following existing application conventions.

The current client asset registration is located at:

```text
espocrm/custom/Espo/Custom/Resources/metadata/app/client.json
```

### Nexa Frontend Customization

Frontend changes belong under `espocrm/client/custom/`:

```text
espocrm/client/custom/
|-- modules/                 Nexa views, controllers and client modules
|-- res/templates/           Nexa HTML templates
|-- css/                     Nexa styles and design-system overrides
|-- img/                     Nexa images and interface assets
`-- login-patch.js           Current login experience integration
```

Current login customization files include:

- `client/custom/login-patch.js`
- `client/custom/res/templates/login-modern.tpl`
- `client/custom/css/modern-login.css`
- `client/custom/img/login-workspace.png`

### Existing Application Files

The complete application is tracked so every developer receives the identical working product. These are shared product code:

- `espocrm/application/Espo/`: existing PHP backend and ORM.
- `espocrm/application/Espo/Modules/Crm/`: existing CRM backend modules.
- `espocrm/client/src/`: existing readable client source where present.
- `espocrm/client/res/`: existing templates and client resources.
- `espocrm/client/lib/`: generated/bundled client libraries.
- `espocrm/public/`: public routes, installer and entry points.

Prefer established extension points when they keep a change clear, but core files may be changed when the product redesign or behavior requires it. Every core change must be committed with focused tests and reviewed carefully because it can affect shared framework behavior. Never edit `vendor/` directly; update its pinned dependency source instead.

### Database Assets

- `database/shared/migrations/`: immutable migrations applied to the shared EspoCRM database.
- `database/shared/seeds/`: synthetic plans, services and test fixtures.

Each local environment uses one `espocrm` database containing Espo core and Nexa SaaS tables. Schema and safe fixtures move through Git; local records and database volumes do not. Every tenant-owned table is converted through reviewed expand/backfill/enforce migrations rather than shared database dumps. Cross-module Customer 360, relationship, lifecycle, timeline, event and consent primitives require approved data contracts before new migrations are added.

For a fresh or existing Nexa-managed XAMPP/WampServer environment, run the
idempotent native bootstrap:

```powershell
powershell -ExecutionPolicy Bypass -File scripts/dev/setup-native-windows.ps1 `
  -PhpPath <path-to-php.exe> `
  -ClientPath <path-to-mariadb.exe>
```

Existing installations receive forward migrations through `apply-shared-schema.ps1`; never reinitialize a non-empty database.

### Developer Scripts

- `scripts/dev/setup.ps1`: creates `.env`, validates the tracked codebase and optionally starts Docker.
- `scripts/dev/setup-native-windows.ps1`: performs the complete non-browser XAMPP/WampServer installation or update.
- `scripts/dev/install-native-application.php`: generates valid application configuration and completes framework installation from `.env`.
- `scripts/dev/bootstrap-espocrm.ps1`: verifies that the complete tracked application and pinned version are present.
- `scripts/dev/check-environment.ps1`: checks PHP, extensions, Git and version baseline.
- `scripts/dev/initialize-local-database.ps1`: creates the complete schema and applies every migration to a fresh local database.
- `scripts/dev/apply-shared-schema.ps1`: applies checksum-tracked forward migrations through Docker or a local MariaDB client.
- `scripts/dev/complete-local-setup.ps1`: installs development seeds, provisions demo tenants, rebuilds and verifies a local installation.
- `scripts/dev/configure-smtp.php`: validates `.env` SMTP settings and applies them through Espo's system configuration writer.
- `scripts/dev/install-development-seeds.php`: loads ordered development fixtures using the installed application's database connection.
- `scripts/dev/provision-demo-tenants.ps1`: creates or refreshes login-ready administrators and tenant-scoped CRM fixtures for both synthetic tenants.
- `scripts/dev/verify-local-install.php`: validates schema counts, migrations, demo administrators and tenant-scoped records.
- `scripts/dev/verify.ps1`: validates shareable files, JSON, PHP, secrets and Compose.

## Team Workflow

1. Select or create an assigned issue.
2. Create a short-lived branch from `main`.
3. Make the scoped code, metadata, migration, test and documentation changes.
4. Run the repository verification command.
5. Open a pull request for review by the other core developer.
6. Merge only after required checks and review pass.

```powershell
powershell -ExecutionPolicy Bypass -File scripts/dev/verify.ps1
```

## Security

Do not publish credentials, tokens, private keys, personal data or vulnerability details. Follow [SECURITY.md](SECURITY.md) for private vulnerability reporting.

## Licences and Notices

Applicable third-party and upstream licence notices are retained in [LICENSE.md](LICENSE.md) and component source files. Repository visibility does not grant trademark rights or replace the obligations of those licences.
