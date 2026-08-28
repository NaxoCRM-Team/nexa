# Phase 3 Sales Exit Gate

## Scope

This evidence covers the M06 opportunity and commercial-planning slice of Phase 3:

- native Opportunity records with tenant-owned pipeline and forecast fields;
- multiple tenant pipelines with create, edit, default, ordering and guarded archive lifecycles;
- configurable stage creation, editing, ordering, archive, probability, forecast category, closed/won semantics and required fields;
- permission-aware pipeline and forecast aggregation;
- exchange-rate conversion of mixed-currency forecast amounts into the configured base currency;
- tenant-admin ownership of enabled currencies, default transaction currency, base reporting currency and exchange rates;
- native EspoCRM currency configuration applied separately to web requests and background jobs;
- tenant product catalogues with pricing, units, taxes, editing and non-destructive archive;
- opportunity line-item creation, editing and removal with native Opportunity total persistence;
- ORM-calculated opportunity totals and append-only revenue history;
- quote creation with immutable price, discount and tax snapshots plus validated status transitions and append-only commercial history;
- forecast filtering by period, pipeline, native Opportunity owner and team;
- responsive Opportunity list, create, edit, detail, pipeline, forecast and product workspaces;
- tenant, service and Opportunity ACL enforcement on every read and write.

This does not close all of M06 or Phase 3. Activities, calendars, projects, documents, collaboration, service workflows and the complete Phase 3 acceptance suite remain attached to their own backlog issues.

## Database Evidence

Migrations `0035` through `0040` add the sales configuration, commercial lifecycle, tenant currency ownership and auditable reference-rate sourcing without replacing native Opportunity or currency behavior. Every added table is classified as service-owned and requires both `tenant_id` and `service_id`.

The verified clean-install baseline is 197 tables, 184 tenant columns, 166 service columns and 39 applied migrations.

## Runtime Evidence

`TenantSalesPipelineTest.php` creates opportunities and products for two synthetic tenants, then proves that:

- each workspace sees only its own opportunities, products and pipeline stages;
- a tenant-scoped line item recalculates the native Opportunity through the ORM;
- creation and amount changes append revenue-history entries;
- a quote snapshots only the current tenant's line items;
- quote status transitions append immutable tenant-scoped history;
- product, line-item and stage edits cannot cross tenant boundaries;
- pipeline, owner and team filters operate on native Opportunity ownership;
- no line item or quote is visible from the other tenant.

`TenantCurrencyIsolationTest.php` additionally gives two synthetic tenants different enabled currencies, defaults and rates, then proves that native configuration, conversion and audit history remain isolated.

## Verification

Run:

```powershell
powershell -ExecutionPolicy Bypass -File scripts/dev/verify.ps1 -Ci
npx playwright test tests/browser/sales-workspace.spec.js
npx playwright test tests/browser/live-sales-workspace.spec.js
```

The sub-gate passes when repository verification, migration replay, the two-tenant sales runtime suite, and all responsive accessibility tests pass.
