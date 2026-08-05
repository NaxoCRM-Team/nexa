# EspoCRM to Nexa CRM SaaS: Shared-Schema Architecture Recommendation

## 1. Executive Recommendation

Nexa will use EspoCRM 9.1.9 as the starting application codebase and convert it into a deeply customized, shared-schema SaaS product. All Espo core records, Nexa modules and SaaS administration records will use one coordinated MariaDB schema.

Customer-owned data is isolated with mandatory `tenant_id` scope. Service-specific records and entitlements additionally use `service_id`. The application will not prioritize compatibility with future upstream EspoCRM upgrades; the team accepts responsibility for its modified ORM, schema and security model.

The customer-facing application remains a modular PHP monolith. Redis, object storage, search, analytics and dedicated workers are supporting infrastructure, not independent product microservices.

## 2. Why Existing EspoCRM Is Not SaaS-Ready

EspoCRM expects one organization in one database. Its existing users, accounts, contacts, opportunities, activities, reports and jobs do not carry or automatically enforce a SaaS tenant condition.

A SaaS conversion must cover more than registration and login:

- Repository reads, updates and deletes.
- Entity relationships and junction tables.
- REST APIs, imports, exports and duplicate checks.
- Reports, dashboards, totals and search.
- Scheduled jobs, email processing and automation.
- Cache, sessions, files, attachments and queues.
- Audit, support access and platform administration.

Nexa is intentionally modifying the complete codebase, so a permanent tenant-aware fork is an accepted product decision rather than an accidental side effect.

## 3. Chosen Data Model

One shared database contains four categories of data:

| Category | Identity | Examples |
|---|---|---|
| Platform-global | Privileged, no tenant owner | plans, service definitions and stable reference data |
| Tenant-owned | `tenant_id` required | users, accounts, contacts, deals, activities, cases and campaigns |
| Service-owned | `tenant_id` and `service_id` required | email sends, automation runs and service usage events |
| Tenant events | `tenant_id` required, `service_id` optional | audit and transactional outbox events |

The security rule is simple: `tenant_id` owns data; `service_id` controls or identifies a product service. A service ID never replaces tenant ID.

General CRM records should not carry a service ID merely because several services use them. A Contact remains owned by its tenant whether CRM, marketing or customer service is currently enabled. Service availability and limits are represented by `nexa_tenant_service`.

## 4. Target Architecture

![Nexa shared-schema modular monolith architecture showing tenant routing, automatic ORM scope, product modules, one shared database, workers and supporting infrastructure](../assets/nexa-system-architecture.svg)

### 4.1 Modular Monolith

Nexa is one versioned PHP product with modules for platform core, CRM, sales, marketing, automation, service, conversations, analytics, identity and SaaS administration. Modules own their services, metadata, views, jobs and tests while using shared tenant and entitlement contracts.

This avoids the operational overhead of prematurely splitting the product into many independently deployed services. High-volume or specialist workloads may be extracted later when measurement demonstrates a clear scaling, storage, isolation or provider-integration need.

### 4.2 Shared MariaDB Schema

The same logical database contains:

- Existing EspoCRM tables converted to tenant ownership where appropriate.
- Nexa CRM, marketing, automation, service and reporting tables.
- Tenant, domain, plan, service, subscription and entitlement tables.
- Usage, provisioning, audit and transactional outbox tables.
- A schema migration history.

A production cell may later host its own copy of this shared schema for capacity or regional reasons, but each cell still contains multiple tenants. The initial architecture does not provision a database per customer.

### 4.3 Supporting Infrastructure

- Redis stores tenant-prefixed cache entries, queues, locks and rate limits.
- Object storage uses immutable tenant prefixes for attachments and media.
- OpenSearch stores tenant-filtered search documents.
- An analytics database and Metabase or equivalent provide governed reporting at scale.
- Dedicated workers process marketing email, automation, imports, webhooks and schedules with immutable tenant context.
- Central logs and traces include tenant, service, request and correlation identity.

### 4.4 Unified Customer Domain

The modular monolith shares one canonical customer identity and history across CRM, sales, service and marketing. Contacts and Accounts remain the known person and company masters; anonymous visitor identities, leads, opportunities, cases, campaigns, segments, scores and external identities link to those records through tenant-scoped contracts.

The first cross-module schema package after the SaaS control plane defines Customer 360 identity links, typed relationships, lifecycle state/history, timeline events, behavioral events, consent evidence and reliable outbox publication. Module-specific schemas then build on those primitives instead of creating independent customer stores.

The [Unified Product Specification](../product/unified-product-specification.md) defines required behavior, the [Requirements Traceability Matrix](../product/requirements-traceability.md) assigns ownership and phases, and the [SaaS Data Architecture](saas-data-architecture.md) controls physical schema and isolation rules.
## 5. Request and Login Flow

1. A user opens the common Nexa login and submits a globally unambiguous username.
2. `TenantResolver` maps that server-owned identity to exactly one active tenant; verified tenant domains remain optional.
3. The application creates an immutable `TenantContext`.
4. Login queries the shared Espo user table using tenant ID and username/email.
5. The authenticated session retains trusted tenant and user identity.
6. The ORM automatically adds tenant scope to every tenant-owned query.
7. Service commands check `nexa_tenant_service` before execution.
8. Audit, cache, files, jobs and events receive the same tenant context.

The browser never chooses an arbitrary tenant ID through a hidden form field or API property.

A login query is logically equivalent to:

```sql
SELECT *
FROM user
WHERE tenant_id = :trusted_tenant_id
  AND user_name = :user_name
  AND deleted = 0;
```

## 6. Automatic Query Enforcement

Developers must not manually remember `WHERE tenant_id = ...` on every screen. Espo's central ORM and repository framework will apply it based on an `EntityOwnershipRegistry`.

The framework must scope:

- Select, count, update and delete queries.
- Joined aliases and relationship tables.
- Record services and API collections.
- Report and dashboard query builders.
- Scheduled jobs and queue workers.
- Imports, exports, duplicate checks and global search.

Raw SQL is prohibited for tenant-owned data unless it uses an approved scoped gateway. Cross-tenant platform operations require explicit authorization, purpose and audit records.

Nexa does not use MariaDB triggers to populate ownership. Triggers cannot secure reads or reliably carry authenticated request identity. The central ORM injects trusted ownership for reads and writes, while `NOT NULL` constraints reject incomplete records.

## 7. Database Integrity Rules

- Every tenant-owned row has `tenant_id NOT NULL` after migration.
- Every service-owned row has `tenant_id NOT NULL` and `service_id NOT NULL`.
- Tenant-local uniqueness includes tenant ID.
- Relationship tables carry tenant identity and cannot connect records from different tenants.
- Frequent access paths use tenant-leading composite indexes.
- Update and delete predicates include tenant scope.
- Audit and outbox events always contain tenant identity.
- Platform-global tables are accessible only through privileged gateways.

## 8. Initial Nexa Tables

The versioned shared-schema migration creates:

- `nexa_tenant`.
- `nexa_tenant_domain`.
- `nexa_plan_definition`.
- `nexa_service_definition`.
- `nexa_plan_service`.
- `nexa_tenant_subscription`.
- `nexa_tenant_service`.
- `nexa_usage_counter`.
- `nexa_provisioning_operation`.
- `nexa_audit_event`.
- `nexa_outbox_event`.

The `nexa_` prefix prevents collisions with Espo table names and makes ownership clear.

These are the existing SaaS control-plane foundations, not the complete future product schema. Customer identity, relationships, lifecycle, timeline, behavior, consent and later module tables require separate reviewed migrations after their requirement and data contracts are approved.

## 9. EspoCRM Conversion Plan

### Stage 1: Inventory

Classify every existing table as platform-global, tenant-owned, service-owned or derived. Record relationships, unique indexes, raw query callers and expected service ownership.

### Stage 2: Expand

Add nullable tenant columns and indexes to approved tenant tables. Add service columns only to service-owned tables. Keep existing development data operational.

### Stage 3: Backfill

Create one baseline tenant for current records and backfill parent tables, relationship tables, histories and derived records in dependency order.

### Stage 4: Framework Enforcement

Implement tenant resolution, immutable context, ORM scope, write guards, entitlement checks and audited platform gateways. Convert authentication, reports, dashboards and jobs.

### Stage 5: Database Enforcement

Make tenant columns non-null, replace global uniqueness with tenant-qualified indexes and add relationship integrity checks.

### Stage 6: Isolation Proof

Use at least two synthetic tenants with deliberately overlapping usernames and record names. Attempt cross-tenant access through every supported interface and execution path.

## 10. Reporting and Dashboards

Tenant reports operate in the same shared database, which simplifies joins across CRM, marketing, automation and service modules. The reporting query builder must apply tenant scope to every participating tenant-owned table.

Service-specific dashboards additionally check the tenant's service entitlement. Platform-wide commercial or operational reporting uses a separate audited gateway and does not reuse a normal tenant session.

High-volume behavioral and delivery analytics may later move to an analytics store, but every event still carries tenant and optional service identity.

## 11. Registration and Services

Customer registration creates:

1. A pending `nexa_tenant`.
2. A verified domain or workspace slug.
3. A subscription and enabled `nexa_tenant_service` rows.
4. The first Espo user with the new tenant ID.
5. Default tenant roles, teams and configuration.
6. An audit trail and provisioning result.

Services are checked independently of record ownership. Disabling marketing email blocks sends and schedules while preserving the tenant's Contacts and historical data according to retention policy.

## 12. Security and Operational Risks

The main risk of shared-schema tenancy is cross-customer disclosure from a missing or incorrect tenant condition. Nexa reduces this risk through centralized ORM scope, table ownership metadata, database constraints, code review, static checks and attack-oriented two-tenant tests.

Other required controls include:

- Tenant-prefixed cache, file and search keys.
- Immutable tenant context in jobs and webhooks.
- Audited operator impersonation.
- Row-level export, deletion and legal-hold workflows.
- Database-wide backup plus tested tenant-level logical recovery.
- Query-plan and index monitoring as tenant volume grows.

## 13. Team and Migration Workflow

Docker, XAMPP and WampServer developers use one local `espocrm` database and apply the same migrations from `database/shared/migrations/`. Synthetic seeds provide the same service catalog and test tenants. Live dumps and database volumes are never shared through Git.

Every schema pull request includes migration, backfill behavior, index review, isolation tests, documentation and a recovery plan. Migrations are immutable after merge.

## 14. Decision Summary

1. Nexa remains a modular PHP monolith with supporting infrastructure.
2. The application uses one shared MariaDB schema rather than a database per tenant.
3. Every customer-owned row is secured by mandatory `tenant_id` scope.
4. Service-owned rows and entitlements use `service_id` in addition to tenant ID.
5. Espo's ORM, authentication, relationships, reports and jobs will be deeply modified.
6. Compatibility with future upstream EspoCRM releases is not a priority.
7. Automatic framework enforcement and two-tenant isolation tests are launch requirements.
8. [ADR-0002](ADR-0002-shared-schema-multitenancy.md) is the governing decision record.
