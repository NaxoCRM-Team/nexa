# Phase 3 Exit Gate

Phase 3 completes the tenant-safe CRM, sales, activity, service and governed data foundation. Nexa retains native EspoCRM records and backend behaviour where they are suitable, then applies the Nexa experience and mandatory tenant/service ownership.

## Covered Workflows

- Contacts and Accounts, including guided import/export, duplicate merge and recoverable deletion.
- Leads and native conversion into Contact, Account and Opportunity records.
- Opportunities, tenant pipelines, forecasting, products, line items, quotes and tenant currency ownership.
- Native Tasks, Calls, Meetings, Calendar, Projects and Documents exposed through one activity experience.
- Native Cases and Portal, including tenant SLA policies, deadlines, escalation and customer/commercial relationships.
- Tenant-aware custom properties, layouts, custom objects and approved relationships.
- Global search, dashboards, permissions and field-level controls across the CRM surface.

## Automated Evidence

Run the complete local gate from the repository root:

```powershell
powershell -ExecutionPolicy Bypass -File scripts/dev/verify-phase-3.ps1 `
  -PhpPath C:\wamp64\bin\php\php8.2.29\php.exe
```

The migration replay uses the local MariaDB `root` account by default because the application database user intentionally cannot create test databases. Supply `-MigrationUser` and `-MigrationPassword` where the local administrative credentials differ.

The gate performs:

1. Repository, architecture, CRM, search, dashboard, sales, activity, Case and Portal contract checks.
2. A clean database installation from the pinned EspoCRM schema through every shared migration and seed.
3. An incremental database replay through migration `0042`, preserving an existing native Case and reconciling its native owner and SLA data.
4. Two-tenant runtime tests for ORM persistence, CRM records, merge, Lead conversion, sales, currency, Cases, SLA APIs and the SLA monitor job.
5. Two-tenant representative Case and Portal fixture checks using native relationships.
6. Responsive browser accessibility and visual-regression fixtures on desktop, tablet and mobile viewports.

Temporary migration-test databases are restricted to `nexa_phase3_clean_test` and `nexa_phase3_upgrade_test` and are removed when the test completes.

## Deferred Work

Knowledge Base, shared team inboxes and production Google OAuth verification are tracked separately. They do not replace or duplicate the native Case and Portal delivery and do not block the Phase 3 CRM-to-service lifecycle gate.

## Recorded Result

The gate passed locally on 1 September 2026 against WAMP, PHP 8.2.29 and MariaDB 10.11. The clean and incremental migration replay passed, all combined backend contract/runtime suites passed, and the Playwright matrix completed with 245 passing checks and 85 intentional live-environment skips across desktop, tablet and mobile profiles. The authenticated Case workflow additionally passed against the live local application with the Service > Cases navigation assertion enabled.
