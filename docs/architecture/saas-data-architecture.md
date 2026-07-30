# Nexa CRM Shared-Schema SaaS Data Architecture

## Executive Decision

Nexa uses a **shared-schema multi-tenant architecture**. EspoCRM core tables, Nexa product tables and SaaS administration tables live in one logical MariaDB database. Every customer-owned row is isolated by mandatory `tenant_id` scope enforced centrally by the application framework.

The team accepts a deep EspoCRM fork and does not prioritize future upstream upgrades. This makes a comprehensive ORM and schema conversion acceptable, but it does not reduce the security requirements: every data and execution path must fail closed when tenant context is absent.

## One Shared Database

A local environment uses one database such as `espocrm`. Production may later use several shared-schema cells for capacity or incident containment, but a cell still contains many tenants in one identical schema.

```text
                         Nexa application
         login identity/session -> TenantContext
                                |
                 ORM tenant scope + entitlements
                                |
                                v
                    Shared MariaDB database
        +----------------+----------------+----------------+
        |                |                |                |
   Espo CRM tables   Nexa modules   SaaS platform    audit/outbox
    tenant_id         tenant_id     tenant/services    tenant_id
```

There is no separate control-plane database and no database per tenant in the initial architecture.

## Data Classification

Every table must be registered in a reviewed ownership manifest.

| Classification | Required identity | Examples |
|---|---|---|
| Platform-global | No tenant column; privileged access only | plan definitions, service definitions, system reference data |
| Tenant-owned | `tenant_id NOT NULL` | users, accounts, contacts, leads, deals, activities, cases, campaigns |
| Service-owned | `tenant_id NOT NULL`, `service_id NOT NULL` | marketing sends, automation executions, service usage events |
| Tenant/service optional | `tenant_id NOT NULL`, nullable `service_id` | audit and outbox events shared across product modules |
| Derived external data | Tenant identity in partition/filter key | cache, files, search, analytics and queue payloads |

`tenant_id` is the security and ownership boundary. `service_id` is not added blindly to every record; it identifies a service-specific record or entitlement. Accounts and Contacts remain owned by a tenant even when CRM, marketing and service modules all use them.

## Unified Customer and Interaction Contracts

The detailed product specification requires one governed customer identity across CRM, marketing, sales and service. Modules must reference shared records and events; they must not create disconnected copies of a contact for campaigns, support or analytics.

### Canonical Records

- Espo Contact remains the canonical known person record inside a tenant.
- Espo Account remains the canonical company/organization record.
- Leads and Opportunities remain lifecycle and sales records linked to the same person/company identity.
- Anonymous visitors use a separate pseudonymous identity until a consented identification event links them to a Contact.
- Products, projects, purchases, cases, campaigns, segments, scores and custom entities associate through typed tenant-scoped relationships rather than copied customer fields.

### Required Shared Primitives

The physical schema for these primitives must be approved through a data-model ADR and forward migrations before Phase 3 feature implementation:

| Primitive | Responsibility |
|---|---|
| Customer identity link | Connect anonymous identifiers, login identities, email/phone identities and external IDs to one tenant-owned person without unsafe automatic merging |
| Typed relationship | Store one-to-one, one-to-many, many-to-many and parent-child associations with labels, validity and audit history |
| Lifecycle state/history | Store configurable stages, current state, transition reason, actor and timestamp |
| Timeline event | Provide an append-oriented chronological history for CRM, sales, service, marketing, score, segment and external activity |
| Behavioral event | Store versioned website, form, asset, email and custom events with identity-resolution and consent metadata |
| Consent/preference record | Preserve purpose, channel, source, policy version, grant/withdrawal time and evidence |
| Outbox event | Reliably publish domain changes to automation, integrations, analytics and notifications after database commit |

A timeline event may summarize or reference an immutable source event, but it does not replace the source record. High-volume behavioral payloads may later move to an event store or analytics database; their tenant, subject, schema version and correlation identity remain stable.

### Cross-Module Invariants

- Every relationship endpoint belongs to the same tenant unless a privileged platform workflow explicitly supports a controlled transfer.
- Lead conversion is transactional and preserves activities, forms, behavior, email/campaign history, score, source and segment membership.
- Customer identity resolution is deterministic, auditable and resistant to duplicate-email or external-provider account takeover.
- Lifecycle, score and segment changes emit timeline and outbox events with idempotency keys.
- Marketing, automation, service and analytics records reference canonical CRM identities; they do not maintain independent customer masters.
- Permission, consent, retention and erasure rules apply when events are read, exported, replayed or copied to supporting stores.

### Schema Change Gate

Do not mass-create future feature tables from module names alone. A schema package is approved only when its requirement IDs, ownership class, relationships, tenant/service scope, unique indexes, retention, migration/backfill behavior and isolation tests are defined. Cross-module primitives are delivered first; module-specific tables follow their phase.
## SaaS Platform Tables

The initial migration in `database/shared/migrations/` creates:

- `nexa_tenant` and `nexa_tenant_domain`.
- `nexa_plan_definition` and `nexa_service_definition`.
- `nexa_plan_service` and `nexa_tenant_service`.
- `nexa_tenant_subscription` and `nexa_usage_counter`.
- `nexa_provisioning_operation`.
- `nexa_audit_event` and `nexa_outbox_event`.

Nexa-owned tables use a `nexa_` prefix to avoid collisions with existing Espo table names.

## Runtime Tenant Contract

### Trusted Resolution

Tenant resolution occurs before Espo authentication:

1. Decode the submitted username from the standard authorization request without accepting a tenant ID from the browser.
2. Resolve exactly one active user and tenant from the server-owned identity records; reject unknown or ambiguous identifiers with a generic unauthorized response.
3. Create an immutable `TenantContext` containing tenant, request and correlation identity.
4. Verify the password and user status inside `TenantContext.tenantId`.
5. Attach the context to ORM, ACL, cache, file, search, queue and audit services.
6. Retain tenant identity in the signed session and clear request-scoped identity after the request or job finishes.

Tenant identity never comes from a writable form field, arbitrary HTTP header or record payload.

### Login

A shared user table is tenant-owned. The common login performs a server-side routing lookup first and accepts only an identifier that maps to exactly one active tenant. Password verification then uses the resolved tenant scope:

```sql
SELECT *
FROM user
WHERE tenant_id = :resolved_tenant_id
  AND user_name = :user_name
  AND deleted = 0
  AND is_active = 1;
```

Usernames exposed through the common login must therefore be globally unambiguous. An identity found in multiple active tenants is rejected rather than guessed. Future multi-workspace membership should use a global login identity plus explicit tenant memberships, while password-reset and invitation links retain signed identity and tenant context.

### Automatic ORM Scope

The central `TenantQueryProcessor` modifies every Espo select, insert, update and delete for registered tenant-owned entities. Developers should write normal repository code while the framework adds the mandatory predicate:

```sql
WHERE tenant_id = :trusted_tenant_id
```

The scope cannot be disabled by ordinary entity options. Platform-global access uses a separate interface with explicit permission and audit requirements.

Required framework components:

| Component | Responsibility |
|---|---|
| `TenantResolver` | Resolve and validate tenant from trusted routing data |
| `TenantContext` | Hold immutable tenant and request identity |
| `EntityOwnershipRegistry` | Classify every table/entity and its service-scope rule |
| `TenantQueryProcessor` | Add tenant predicates to ORM reads, inserts, updates, deletes, unions and joins |
| `TenantSqlExecutor` | Reject direct SQL during tenant execution so ORM scope cannot be bypassed |
| `ServiceEntitlementChecker` | Validate service availability from `nexa_tenant_service` |
| `PlatformExecutionGateway` | Permit explicit cross-tenant operations with a logged reason |
| `TenantContextStore` | Stack and clear context after each request or job iteration |

The runtime implementation is installed in `espocrm/application/Espo/Core/Tenant/`. HTTP application runners provide the public shell, and API middleware resolves a globally unambiguous login identity before password verification. Verified domains remain optional routing inputs. Espo ORM metadata exposes `tenantId` and `serviceId`, and `DefaultQueryExecutor` passes immutable queries through `TenantQueryProcessor`. Legacy UNION callers now keep query objects instead of converting them to raw SQL. Cron can enumerate through `PlatformExecutionGateway`, while every scheduled job restores the `tenant_id` stored on its record.

Migration `0003_enforce_tenant_runtime.sql` removes the compatibility default and makes `tenant_id` non-null on all 133 tenant-owned Espo tables. The repository verifier runs `tests/tenant/TenantRuntimeTest.php`, and CI loads two synthetic tenants before executing database isolation checks.

### Writes and Database Guards

Application services derive `tenant_id` from `TenantContext`; they never trust a supplied record value. Service-specific writers derive or validate `service_id` through the entity ownership registry and entitlement service.

Database triggers may reject missing or inconsistent tenant identity during the migration, but they are defense-in-depth for writes only. They do not replace ORM filtering for reads. Foreign keys, composite uniqueness and cross-tenant relationship validation provide additional protection.

### Relationships

Relationship and junction tables carry tenant scope. A relationship can be created only when both records belong to the current tenant. Repository joins constrain every tenant-owned alias, not only the first table.

### Reports and Dashboards

Report builders, dashboard widgets and aggregates run through tenant-scoped query services. Every base table and joined tenant-owned table receives the current tenant condition. Service availability is checked separately when a report belongs to a paid service.

A normal tenant report cannot request another tenant ID. Platform analytics uses a separate governed path and records the operator or system purpose.

### Scheduled and Background Jobs

Every job contains a signed or server-generated `tenant_id`, job ID and correlation ID. Service jobs also carry `service_id`. A worker creates a fresh tenant context, revalidates tenant and service status, runs scoped repositories and clears context before accepting another job.

Global schedulers may enumerate active tenants using the platform gateway, then emit one tenant-scoped job per tenant. A long-running worker must never retain an authenticated user, ORM identity map or cache namespace across tenants.

## Service Entitlements

Service access is modeled separately from data ownership:

```text
nexa_plan_definition
        |
        +--> nexa_plan_service --> nexa_service_definition
                                      |
nexa_tenant_subscription              |
        |                              |
nexa_tenant ----------------> nexa_tenant_service
```

`nexa_tenant_service` records enabled status, limits and tenant overrides. Disabling a service blocks its commands and scheduled jobs but does not remove or reassign the tenant's core CRM records.

## Audit and Events

Espo's creator, modifier, assignment, authentication log, action history, stream and audited fields remain useful. Nexa additionally records tenant-aware security and integration activity in `nexa_audit_event` and `nexa_outbox_event`.

An audit identity includes:

- `tenant_id`.
- Optional `service_id`.
- Actor type and local Espo user where applicable.
- Action, subject, timestamp, request and correlation IDs.
- Redacted metadata appropriate for retention and privacy policy.

Tenant ID is derived from trusted runtime context, not event payload. Support impersonation records both operator identity and target tenant user.

## Schema Conversion

### Stage 1: Inventory

Create a machine-readable manifest classifying every Espo and Nexa table. Identify relationship tables, raw SQL, uniqueness rules, reports and scheduled jobs. No table is assumed global merely because it lacks a visible customer field.

### Stage 2: Expand

Migration `0002_expand_espocrm_tenant_scope.sql` inventories all 136 EspoCRM 9.1.9 tables. It adds indexed `tenant_id` and nullable `service_id` columns to 133 tables, backfills current records and tenant-qualifies 56 business unique indexes. `address_country`, `extension` and `system_data` are the explicit platform-global allowlist. Nine MariaDB `AUTO_INCREMENT` sequence indexes remain globally unique because their sequence column must lead the key.

Migration `0002` used a stable legacy-local default only during expansion and backfill. Migration `0003_enforce_tenant_runtime.sql` removes that default after deploying automatic ORM scope, verifies local hostname routing and changes all 133 Espo tenant columns to `NOT NULL`.

### Stage 3: Backfill

Create a baseline tenant for existing development data. Backfill parent records first, then relationships, histories and derived records. Validate orphan counts and cross-tenant relationships after every batch.

### Stage 4: Enforce

Automatic ORM scope, direct-SQL guards and job context restoration are deployed before migration `0003` makes tenant columns non-null. Migration `0002` already tenant-qualifies the reviewed business unique indexes.

### Stage 5: Prove Isolation

Run two-tenant attack-oriented tests across authentication, CRUD, relationships, APIs, reports, dashboards, imports, exports, jobs, cache, files, search, audit and integrations. No real second customer is allowed before these tests pass.

## Shared-Schema Index Rules

- Frequent tenant queries use indexes beginning with `tenant_id`.
- Tenant-local uniqueness includes `tenant_id`.
- Service-heavy access commonly uses `(tenant_id, service_id, ...)`.
- Soft-delete and status columns follow tenant identity in selective indexes.
- Indexes are based on measured query plans; do not add both tenant and service to every index automatically.

## Cache, Files, Search and Analytics

Database filtering alone is not enough:

- Cache keys begin with tenant identity.
- Object-storage paths use immutable tenant prefixes.
- Search documents contain an enforced tenant filter field.
- Queue messages include trusted tenant context.
- Analytics events carry tenant and optional service identity.
- Rate limits and distributed locks are tenant-namespaced.

## Registration and Provisioning

1. Validate and reserve a tenant slug/domain.
2. Insert `nexa_tenant` with provisioning status.
3. Create its subscription and enabled `nexa_tenant_service` rows.
4. Create the first Espo user with the new `tenant_id`.
5. Seed default roles, teams and tenant configuration using the same tenant context.
6. Run tenant-scoped login and CRUD smoke tests.
7. Activate the tenant only after verification succeeds.

Provisioning uses an idempotency key so a retry cannot create duplicate tenants or administrators.

## Backup, Export and Deletion

Shared-schema backup is database-wide. Tenant-level recovery requires tested logical export and restore tools that preserve IDs and relationships while enforcing the target tenant. Tenant deletion is a dependency-ordered, auditable workflow across database rows, files, cache, search, analytics and provider data.

Enterprise physical isolation can later be implemented with a dedicated shared-schema cell, but it is not the initial tenancy model.

## Local Development

Each developer runs one independent `espocrm` database through Docker or a supported MariaDB 10.11/11.x server used by XAMPP or WampServer. All supported local environments apply the same `database/shared/migrations/` sequence and synthetic seeds. Git synchronizes schema definitions; developers never exchange live dumps for routine collaboration.

Local fixtures include two synthetic tenants and verified domains. Runtime and CI tests use overlapping record criteria so a missing scope is visible.

## Delivery Order

1. Maintain ADR-0002, the table-ownership rules and automated tenant-isolation gate.
2. Keep the complete Espo/Nexa ownership and index manifest synchronized with every migration.
3. Trace the unified specification to canonical entities, relationships, events, APIs and retention rules.
4. Approve the Customer 360, typed-relationship, lifecycle, timeline, behavioral-event, consent and outbox data contracts.
5. Deliver those shared primitives through forward-only expand/backfill/enforce migrations and synthetic two-tenant fixtures.
6. Build Phase 3 CRM, sales and service workflows on the shared identity and relationship contracts.
7. Add consent/content/segment schema in Phase 4 and event/timeline storage in Phase 5 before tracked email and automation in Phase 6.
8. Add module-specific schemas only with requirement IDs, rollback behavior, data dictionary updates and clean-install/upgrade/isolation tests.
9. Introduce supporting event, search and analytics stores only behind the same stable tenant and subject contracts.

## Non-Negotiable Launch Gate

No second real customer may be onboarded until automated evidence proves isolation across authentication, ORM queries, raw SQL, relationships, APIs, scheduled jobs, queues, cache, files, search, exports, analytics, audit and support impersonation.
