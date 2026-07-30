# Unified Customer Data Contract Blueprint

## Status

**D0 audited; D1 foundation implemented.** The [existing schema audit and requirement map](existing-schema-audit.md) records the retain, extend and new decisions. Forward migration `0009_unified_customer_foundation.sql` implements customer identity links, typed relationships, lifecycle state/history and the chronological timeline projection. Later migration slices remain proposed until their data contracts are approved.

## Objective

Provide one tenant-owned customer identity, relationship graph, lifecycle and chronological history that CRM, sales, service, marketing, automation, analytics and integrations can share without copying customer masters.

Related requirements are `CRM-*`, `DATA-041..090`, `EVENT-*`, `MKT-013..060`, `EXT-*`, `PLAT-036` and the associated advanced `F-*`/`NFR-*` rows in the traceability matrix.

## Existing Canonical Records

| Record | Existing source | Contract |
|---|---|---|
| Known person | `contact` | Canonical person/customer profile inside a tenant |
| Company | `account` | Canonical organization/company profile inside a tenant |
| Lead | `lead` | Pre-conversion qualification record linked to preserved history |
| Opportunity | `opportunity` | Sales lifecycle record linked to Contact and Account |
| Case | `case` | Service record linked to the same Contact/Account identity |
| Activities | task/call/meeting/email/note/document records | Source records referenced by the customer timeline |

No new generic `customer` table should duplicate Contact or Account fields merely to support marketing or support modules.

## Proposed Shared Primitives

Physical names remain subject to migration review, but each responsibility requires one authoritative owner.

| Candidate structure | Owner | Minimum responsibility |
|---|---|---|
| `nexa_identity_link` | M05/M11 contract | Tenant-scoped link from Contact to verified email/phone, anonymous, login or external-provider identity; normalized values are protected and unsafe automatic linking is prohibited |
| `nexa_relationship_type` | M05 | Tenant-configurable relationship label, endpoint types, cardinality, inverse label and permission policy |
| `nexa_relationship_edge` | M05 | Audited typed relationship between two tenant-owned records with validity dates and no cross-tenant endpoints |
| `nexa_lifecycle_definition` | M05 | Tenant-configurable lifecycle model and applicable record type |
| `nexa_lifecycle_stage` | M05 | Ordered stages, category, active state and transition metadata |
| `nexa_lifecycle_transition` | M05/M12 contract | Append-only stage transition reason, actor, source, timestamp and automation correlation |
| `nexa_timeline_event` | M05/M11 contract | Permission-aware customer-history projection referencing an immutable source record/event |
| `nexa_behavior_event` | M11 | Versioned behavioral/custom event with subject, consent, source, idempotency and correlation data |
| `nexa_consent_record` | M08 | Purpose/channel evidence, policy version, source, grant/withdrawal and audit history |
| `nexa_outbox_event` extension | M01 | Reliable post-commit publication to automation, providers, search and analytics |

Later segment, campaign, score, content, send, automation, report and integration tables reference these primitives; they are not part of the first Customer 360 migration unless their own phase contract requires them.

## Required Keys and Constraints

Every proposed tenant-owned structure includes:

- immutable primary ID;
- `tenant_id NOT NULL` and nullable/required `service_id` according to ownership classification;
- tenant-leading lookup and uniqueness indexes;
- created/modified timestamps and actor where applicable;
- soft-delete or immutable-history behavior chosen explicitly;
- source/correlation/idempotency identity for events and external operations;
- foreign-key or application-enforced endpoint validation that prevents cross-tenant relationships;
- documented retention, export, erasure and legal-hold behavior.

Raw personally identifiable values must not be duplicated into event metadata when an internal reference or protected normalized hash is sufficient.

## Lead Conversion Contract

Lead conversion is one transaction or an idempotent recoverable workflow that:

1. resolves or creates the Contact and Account inside the same tenant;
2. optionally creates the Opportunity;
3. relinks activities, forms, behavioral identities, email/campaign history, scores, source and segment membership;
4. records old-to-new identity mappings and a conversion timeline event;
5. publishes an outbox event after commit;
6. safely returns the previous result when the same idempotency key is retried.

No history may remain visible only through an unscoped or deleted Lead after conversion.

## Migration Slices

| Slice | Phase | Schema outcome |
|---|---|---|
| D0 Contract inventory | Alignment gate | Data dictionary, ownership manifest, existing association audit and query/index baseline |
| D1 Customer graph | Phase 3 | Identity links, relationship types/edges, lifecycle definitions/stages/transitions and backfill |
| D2 Timeline foundation | Phase 3 | Timeline projection, source-reference contract and outbox integration for CRM/sales/service events |
| D3 Consent and audience | Phase 4 | Consent evidence, preferences, segment definitions/membership and campaign enrollment foundations |
| D4 Behavioral events | Phase 5 | Anonymous identity, behavioral-event ingestion, deduplication, retention and timeline projection |
| D5 Communication and automation | Phase 6 | Marketing sends, delivery events, workflow definitions, enrollment, execution and retry history |
| D6 Scoring and personalization | Phase 7 | Score models/events, expiration and deterministic decision history |
| D7 Governed analytics | Phase 9 | Reporting semantic models, metric definitions and attribution versions; high-volume storage may be external |

Each slice is a separate pull request or reviewable migration series. It must support both a clean database and forward upgrade of an existing Nexa installation.

## Required Verification

A schema slice cannot merge until it proves:

- clean installation and idempotent migration recording;
- forward upgrade from the current baseline with documented backfill;
- two-tenant CRUD, relationship, event, export and job isolation;
- tenant-qualified uniqueness and query-plan checks;
- conversion/retry idempotency where applicable;
- deletion, retention and audit behavior;
- synthetic fixtures for both demo tenants;
- rollback or compensating recovery instructions;
- synchronized data dictionary, ownership manifest, API/event schema and module documentation.

## Decision Needed Before SQL

Before D1 is implemented, the team must approve:

1. whether Contact alone represents all known people or a thin subject registry is required for non-contact actors;
2. supported relationship endpoint types and polymorphic-reference enforcement;
3. lifecycle models that apply to Contact, Lead, Account and Opportunity;
4. timeline source-reference and visibility rules;
5. behavioral-event payload storage, partitioning and retention targets;
6. privacy treatment for normalized identity values and anonymous identifiers.

These decisions are architecture work, not browser-installer or local-environment configuration.