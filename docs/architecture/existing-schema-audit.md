# Existing Schema Audit and Requirement Map

## Purpose

This is the D0 database audit for the unified Nexa product specification. It identifies what the current shared schema already supports, what must be extended, and what needs a new owned table. It is the decision record used before schema work begins.

## Audited Baseline

The audit compared:

- the pinned EspoCRM 9.1.9 schema fixture;
- shared migrations `0001` through `0008`;
- the runtime ownership manifests;
- Espo core and CRM entity metadata;
- the local MariaDB schema; and
- all 47 sections of the unified product specification.

At the time of audit the local database contained 158 tables, 147 `tenant_id` columns, 138 `service_id` columns, and all eight existing migrations. The original fixture defines 136 Espo tables. The additional 22 tables provide tenant, plan, entitlement, provisioning, audit, outbox, signup and identity-security capabilities.

## Decisions

- **Retain** means the existing table remains the system of record.
- **Extend** means a forward migration, metadata, service or index builds on an existing record.
- **New** means no suitable owned structure exists and a new module-owned table is required.
- **Integrate** means a provider boundary is required; provider state must not become the customer master.

Contact remains the person master and Account remains the company master. Lead, Opportunity and Case retain their current responsibilities. No generic customer table will duplicate these records.

`tenant_id` is mandatory on every new tenant-owned table. `service_id` is used only when a row belongs to a particular entitled service. Shared identity, relationship, lifecycle and timeline records are tenant-owned but not service-owned.

## Core Record Audit

| Capability | Existing source | Decision | Main gap |
| --- | --- | --- | --- |
| People | `contact`, email/phone link tables | Retain + Extend | Verified identities, lifecycle and cross-module history |
| Companies | `account`, `account_contact` | Retain + Extend | Typed relationships, hierarchy semantics and lifecycle |
| Prospects | `lead` | Retain + Extend | Conversion mapping, scoring history and timeline projection |
| Sales | `opportunity`, contact/account links | Retain + Extend | Product line items, forecasting and lifecycle history |
| Service | `case`, `case_contact`, activities | Retain + Extend | SLA, routing, satisfaction and unified timeline |
| Activities | `task`, `call`, `meeting`, `email`, `note` | Retain + Extend | One chronological, permission-aware projection |
| Campaign basics | `campaign`, `mass_email`, `target_list` | Retain + Extend | Visual orchestration, multichannel execution and attribution |
| Tenant platform | `nexa_tenant`, plan/service/subscription tables | Retain + Extend | Billing lifecycle, quotas, retention and operator workflows |
| Identity/security | user/auth tables and `nexa_*identity*` tables | Retain + Extend | Customer identity links are separate from workforce login links |
| Audit/integration | `nexa_audit_event`, `nexa_outbox_event`, webhooks | Retain + Extend | Domain event contracts, replay policy and delivery observability |

## Specification-to-Schema Map

| # | Specification area | Existing tables or metadata | Decision | Target module/phase |
| ---: | --- | --- | --- | --- |
| 1 | Core system foundation | tenant, subscription, service, user, team, role, audit, outbox | Extend | M01/M03/M04/M22, Phases 2-3 |
| 2 | Unified customer record | contact, account, lead, opportunity, case, activities | Extend + New shared primitives | M05/M11, Phase 3 |
| 3 | Core CRM entities | contact, account, lead, opportunity, case, campaign, target list | Retain | M05-M07/M13, Phase 3 |
| 4 | Contacts | contact, email address, phone number, account/contact links | Extend | M05, Phase 3 |
| 5 | Accounts/companies | account, account contact, account portal user | Extend | M05, Phase 3 |
| 6 | Leads | lead and conversion target IDs | Extend | M05/M12/M13, Phase 3 |
| 7 | Opportunities | opportunity and contact/account links | Extend + New product/line-item schema | M06, Phase 3 |
| 8 | Cases/support tickets | case, case contact, activities, knowledge base | Extend + New SLA schema | M07, Phase 3 |
| 9 | Activity management | task, call, meeting, email, note | Retain + Timeline projection | M05/M07/M11, Phase 3 |
| 10 | Calendar | call, meeting, task, reminder, working-time calendar | Extend | M10, Phase 3 |
| 11 | Email system | email, account, inbound, queue, template, filter | Extend | M09/M13, Phases 3 and 6 |
| 12 | Anonymous visitor tracking | none suitable | New | M11, Phase 5 |
| 13 | Event tracking | audit/outbox are operational, not behavior stores | New | M11, Phase 5 |
| 14 | Customer behaviour timeline | note stream is not a complete immutable projection | New timeline projection | M11, Phases 3 and 5 |
| 15 | Segmentation engine | target list is static/basic | Extend + New rule/snapshot schema | M12, Phase 4 |
| 16 | Lead-scoring engine | no versioned score model/history | New | M12, Phase 7 |
| 17 | Lifecycle/customer stages | lead status and opportunity stage are entity-specific | New shared lifecycle schema | M05/M12, Phase 3 |
| 18 | Campaign builder | campaign, target list, mass email | Extend + New orchestration schema | M13, Phase 4 |
| 19 | Email marketing | mass email, queue, template, campaign log | Extend + New delivery event schema | M13, Phase 6 |
| 20 | Dynamic content | templates only | New rules/variants | M14, Phase 7 |
| 21 | Form builder | lead capture provides a narrow foundation | Extend + New form/version/submission schema | M15, Phase 4 |
| 22 | Landing page builder | public landing is product marketing, not tenant content | New | M15, Phase 4 |
| 23 | Asset management | attachment, document, document folder | Extend | M15/M19, Phase 4 |
| 24 | Website personalization | none suitable | New | M14/M15, Phase 7 |
| 25 | Automation engine | jobs/scheduled jobs are infrastructure only | New definition/execution schema | M16, Phase 6 |
| 26 | Customer support automation | case and jobs are partial | Extend automation + SLA/routing | M07/M16, Phases 3 and 6 |
| 27 | Sales automation | opportunity, tasks and jobs are partial | Extend automation | M06/M16, Phase 6 |
| 28 | Marketing analytics | campaign logs are partial | Extend + analytics projections | M17, Phase 8 |
| 29 | Sales analytics | opportunity provides source facts | Extend + analytics projections | M17, Phase 8 |
| 30 | Customer analytics | no shared customer metric model | New projections | M17, Phase 8 |
| 31 | Support analytics | case provides source facts | Extend + analytics projections | M17, Phase 8 |
| 32 | Campaign analytics | campaign log provides partial source facts | Extend + analytics projections | M17, Phase 8 |
| 33 | Attribution | campaign source links are not multi-touch attribution | New | M17, Phase 8 |
| 34 | Customization engine | metadata, entity definitions and layouts exist | Extend under module conventions | M18, Phase 9 |
| 35 | Relationship engine | fixed join tables exist | New typed graph while retaining joins | M05/M18, Phase 3 |
| 36 | Dynamic logic | formulas/workflows are incomplete | New rules contract | M18, Phase 9 |
| 37 | Formula engine | calculated behavior is incomplete | New expression/version contract | M18, Phase 9 |
| 38 | Reporting engine | export and dashboard foundations only | Extend + reporting model | M17, Phase 8 |
| 39 | Dashboards | dashboard templates/preferences exist | Extend | M02/M17, Phases 1 and 8 |
| 40 | API | Espo API and custom API metadata | Extend with tenant/service contracts | M20, Phase 10 |
| 41 | Webhooks | webhook and queue tables exist | Extend delivery/replay contracts | M20, Phase 10 |
| 42 | Integration engine | integration, OAuth and external account tables | Extend + connector state | M20, Phase 10 |
| 43 | Data management | import/export and duplicate checks are partial | Extend | M19, Phase 9 |
| 44 | Compliance and consent | suppression flags are not purpose-based evidence | New | M08, Phase 4 |
| 45 | Security | users, roles, teams, auth/audit plus identity security tables | Extend | M04/M22, Phase 2 |
| 46 | Required development principle | module conventions and ownership manifests | Retain + enforce through tests | M01/M23, all phases |
| 47 | Deliverable | migrations, metadata, APIs, UI and tests | Incremental delivery | M01-M23, all phases |

## D1 SQL Scope

Migration `0009_unified_customer_foundation.sql` creates:

1. verified customer identity links;
2. tenant-configurable relationship types and relationship edges;
3. lifecycle definitions, ordered stages, current assignments and immutable transitions; and
4. the chronological customer timeline projection.

The migration intentionally does not create behavior events, consent, product catalog, SLA, automation or analytics tables. Those require their own approved data contracts and forward migrations in later phases.

Polymorphic Contact/Account/entity references are tenant-validated in application services because the referenced Espo tables do not expose composite `(id, tenant_id)` keys. Every read and write must therefore bind the active `TenantContext`; raw unscoped access remains denied.

## Release Gate

D1 is acceptable only when clean installation, upgrade preservation, migration idempotency, ownership classification and two-tenant CRUD/relationship/lifecycle/timeline isolation all pass in CI.
