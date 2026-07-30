# Nexa Module Conventions

This document is the implementation contract for product modules in the Nexa modular monolith. A module owns a cohesive business capability while sharing the application runtime, tenant context, database and release lifecycle.

## Module Shape

Use the stable module identifier from the product roadmap, for example `M05`, in documentation and migrations. Every change also references its advanced `F-*`, detailed domain and applicable `NFR-*` requirement IDs from the traceability matrix. PHP code uses the `Espo\Custom\Modules\<Name>` namespace and lives below:

```text
espocrm/custom/Espo/Custom/Modules/<Name>/
  Api/              authenticated HTTP actions
  Application/      commands, queries and orchestration
  Domain/           entities, value objects, policies and events
  Infrastructure/   persistence and external adapters
  Resources/        metadata, routes, translations and configuration
espocrm/client/custom/modules/<name>/
  src/              views, models and collections
  res/              templates, styles and translations
database/shared/migrations/
tests/modules/<name>/
```

Modules may call another module only through a public application contract or an event. They must not reach into another module's infrastructure classes, tables through raw SQL, or private client views.

## Ownership Rules

- M05 owns canonical customer, account, contact, lead, opportunity, lifecycle and typed-relationship data. Sales, service, marketing and analytics modules consume those identities through public contracts rather than creating independent customer masters.
- Every new table, route, event and scheduled job has one module owner.
- Schema changes are forward-only, numbered SQL migrations. A migration must be idempotently recorded in `nexa_schema_migration` and include recovery notes.
- Espo metadata is extended from `custom`; core changes require an architecture decision and a regression test proving why an extension point was inadequate.
- Shared UI primitives belong to M02. Feature modules consume semantic tokens and components rather than defining parallel button, form or dialog systems.

## Shared Customer and Requirement Contracts

- The unified specification and traceability matrix define required outcomes; module boundaries define implementation ownership, not separate products.
- Customer identity, relationship, lifecycle, timeline, consent and outbox primitives are shared contracts with one owning module and versioned consumers.
- Lead conversion and identity resolution preserve source history and emit idempotent domain events.
- Domain APIs and events ship with their owning module under M01 conventions; M20 adds adapters and synchronization rather than becoming a late API bottleneck.
- A module schema is not approved until ownership class, tenant/service scope, relationships, indexes, retention, migration/backfill, rollback and isolation tests are documented.
## Runtime Contracts

### API and errors

JSON APIs validate at the boundary and return a stable problem body containing `status`, `code`, `message` and optional field `errors`. Domain exceptions are mapped at the API boundary; stack traces and credentials never cross it.

### Tenant and permissions

Every tenant request, command, query, job and event must carry a trusted `TenantContext`. Tenant identifiers supplied by browsers are rejected. ORM queries use automatic tenant scope, and authorization is checked separately from isolation before reads or writes.

### Events, queues and audit

Domain events use past-tense names such as `ContactCreated`, include an event identifier, tenant identifier, actor, occurred-at time and schema version, and contain no secrets. Queue consumers are idempotent and restore tenant context before loading records. Material state changes write an audit entry with actor, tenant, action, target and request correlation identifier.

## Representative Vertical Slice

The self-service signup flow is the reference slice:

1. `PostSignup` is the API boundary.
2. `SignupValidator` validates input without persistence concerns.
3. `SignupService` owns the transaction that creates tenant, subscription, service entitlements and the first administrator.
4. `SignupMailer` is an infrastructure boundary configured from environment.
5. Routes and middleware live in custom metadata.
6. Validator, SMTP and tenant runtime behavior have independent tests.

New modules should preserve this separation and add one end-to-end test for their primary workflow.

## Definition of Done

A module change is complete when its requirement IDs, acceptance criteria, tenant and permission behavior, migration, API/event contract, responsive UI, audit behavior, tests, operational diagnostics and user/administrator/developer documentation are delivered together. `scripts/dev/verify.ps1 -Ci` enforces the repository-level parts of this contract.
