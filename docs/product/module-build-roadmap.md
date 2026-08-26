# Nexa CRM Module and Build Roadmap

## Product Direction

Nexa is one unified customer platform spanning relationship management, sales, service, behavioral data, marketing automation, analytics and integrations. The finished product uses one design system, navigation model, customer identity, permission system, event history and operating model. Capabilities are implemented through Nexa-owned modules or governed provider adapters, not disconnected applications.

"Customize everything" means that every relevant workflow and user-facing surface is deliberately reviewed, branded and tested. The complete pinned application is versioned as the shared Nexa codebase. Prefer `custom/`, `client/custom/`, metadata and clear module boundaries where they fit, while allowing reviewed changes to existing backend and frontend files when the product requires them. Core changes need focused tests and must remain distinguishable from the pinned baseline.

## Architecture Layers

| Layer | Responsibility | Deployment boundary |
|---|---|---|
| Nexa experience | Application shell, navigation, design system, responsive layouts and branded workflows | Espo client custom modules |
| Business modules | CRM, marketing, automation, service, conversations and administration | Espo custom PHP and client modules |
| SaaS administration | Tenants, plans, services, entitlements, metering, billing, provisioning and operator console | Module inside the shared-schema modular monolith |
| Data and event platform | Event collection, queues, search, reporting models and audit data | Workers and supporting data stores |
| Provider adapters | Email, SMS, WhatsApp, social, ads, identity, AI and analytics integrations | Versioned adapters behind Nexa interfaces |
| Operations | CI/CD, secrets, monitoring, backups, security and incident tooling | Shared platform infrastructure |

## Ordered Module Catalogue

Module IDs are stable architecture and ownership identifiers. Delivery order is controlled by the phase dependency gates below; a foundation from a numerically later module may be required before another module is completed.

| Module | Name | Aligned scope | Advanced requirements |
|---|---|---|---|
| M01 | Nexa Platform Core | Module conventions, configuration, feature flags, audit/events, versioned API and webhook contracts, queues, schedulers, files, notifications, shared errors, observability and extension packaging | F-003 |
| M02 | Nexa Design System and App Shell | Brand, authentication UX, global navigation, search, notifications, responsive layouts, accessibility, interface configuration, dashboard shell and reusable components | F-002, F-012, F-059 |
| M03 | SaaS Administration | Tenant lifecycle, plans, entitlements, quotas, metering, billing integration, tenant branding, domains, provisioning and sandbox accounts | F-001, F-005, F-014, F-035, F-038, F-077 |
| M04 | Identity, Teams and Access | Users, profiles, social login, SSO, MFA, teams, departments, hierarchy, roles, permission sets, record/field/content restrictions, session security, sensitive-data protection and audited impersonation | F-015, F-024, F-067, F-072, F-073, F-074, F-079, F-083 |
| M05 | Unified CRM Data Platform | Customer 360 identity, accounts, contacts, leads, conversion, opportunities, lifecycle stages, relationship graph, custom objects/fields/layouts, formulas, dynamic logic, calculated properties, currencies, import/export and deduplication | F-026, F-029, F-039, F-040, F-047, F-068 |
| M06 | Sales, Activity and Calendar Workspace | Pipelines, forecasting, stage rules, activities, tasks, calls, meetings, personal/team calendars, products, projects, documents, collaboration and account-centered workflows | F-050 |
| M07 | Service and Operational Email | Cases, queues, SLA/escalation, customer service workspace, knowledge base, portals, SMTP/IMAP operational email, threading, team email and support entitlements | F-020, F-027, F-028 |
| M08 | Consent, Forms, Landing Pages and Assets | Consent ledger, cookie tools, form and landing-page builders, conditional fields, form actions, website content surfaces, URL mappings, localization, file/media assets and download governance | F-010, F-016, F-032, F-034, F-042 |
| M09 | Marketing Contacts, Segments and Campaigns | Marketing-contact status, static/dynamic segments, audiences, target lists, campaign definitions/enrollment, marketing events, presets and asset governance | F-007, F-044, F-055 |
| M10 | Marketing Email and Deliverability | Drag-and-drop/HTML editor, templates, personalization, dynamic content, programmable/single-send APIs, approvals, scheduling, sending domains, suppression, bounce/complaint handling and health reporting | F-013, F-025, F-031, F-033, F-071, F-075, F-085 |
| M11 | Customer Timeline, Tracking and Event Platform | Canonical timeline, anonymous/known identity resolution, web tracking, page/form/asset/video events, logged-in visitors, custom events, reply tracking, ingestion APIs, retention and visual event configuration | F-021, F-022, F-053, F-066, F-070, F-082 |
| M12 | Automation and Visual Campaign Engine | Versioned trigger/action workflows, campaign canvas, enrollment, delays, branching, business-time rules, retries, cancellation, history, sales/service/marketing actions and omnichannel orchestration | F-004, F-009, F-030, F-037 |
| M13 | Scoring, Lifecycle Automation, Personalization and ABM | Explainable contact/deal/company scoring, score history/expiration, lifecycle transitions, target accounts, ABM orchestration and deterministic dynamic-content decisions | F-045, F-049, F-051, F-056, F-061, F-065 |
| M14 | Conversations and Bots | Shared inbox, live chat widget, Messenger, bots, custom inbox views and draggable widget configuration | F-008, F-011, F-018, F-036, F-063 |
| M15 | Messaging Channels | SMS and WhatsApp consent, templates, sending, replies, provider callbacks and automation actions | F-046, F-048 |
| M16 | Social Workspace | Social accounts, publishing, scheduling, inbox, approvals, listening and platform-policy enforcement | F-006 |
| M17 | Advertising | Ad account connections, audiences, campaign visibility, retargeting and conversion events | F-017, F-023, F-064 |
| M18 | SEO and Content Intelligence | SEO recommendations, page/search performance, Search Console data and YouTube analytics | F-019, F-058, F-062, F-086 |
| M19 | Analytics, Reporting, Dashboards and Attribution | Governed reporting engine, custom reports, personal/team/department dashboards, filtered views, domain analytics, campaign/asset comparison, attribution, funnels and customer journeys | F-043, F-054, F-060, F-069, F-081 |
| M20 | APIs and Enterprise Integrations | Connector framework, OAuth/credential lifecycle, domain REST APIs, signed webhooks, mapping, transformation, reconciliation, Google/Microsoft calendar and contact synchronization, payment/accounting/ecommerce adapters and Salesforce synchronization | F-057, F-078 |
| M21 | AI Services | Provider-neutral AI gateway, Anthropic adapter, social agent and social-inbox insights with safety and usage controls | F-052, F-080, F-084 |
| M22 | Administration and Support Operations | Tenant/operator settings, notifications, support console, audit access, health, usage, privacy operations and operational controls | F-076 |
| M23 | Experimentation | Audience assignment, A/B variants, statistical results and winner selection for email and supported content | F-041 |

All 86 advanced functional IDs and all 47 detailed specification sections have module ownership. The [Requirements Traceability Matrix](requirements-traceability.md) controls the detailed domain ranges and cross-module dependencies. M11 event and identity foundations must be delivered before M10 tracked marketing-email completion, and domain APIs ship with their owning modules rather than waiting for Phase 11.

The Additional features requirement remains a controlled discovery bucket in M01 and must be split into testable requirements before scheduling.
## Existing EspoCRM Coverage

Every existing area receives a product decision: retain and redesign, extend, replace behind the same user workflow, or retire. Nothing should disappear accidentally.

| Existing area | Nexa treatment | Owning module |
|---|---|---|
| Login, password recovery and authentication | Rebuild as Nexa responsive authentication experience; connect SSO and social identity later | M02, M04 |
| Home, dashboards, navbar, search and notifications | Replace the visual shell and information architecture; preserve supported extension contracts | M02 |
| Accounts, Contacts and Leads | Retain data foundations; redesign record, list, create, merge and relationship workflows | M05 |
| Opportunities | Retain core model; redesign pipeline, forecasting-ready fields and account context | M05, M06 |
| Tasks, Calls, Meetings, Calendar and reminders | Unify into the Sales Workspace and responsive activity composer | M06 |
| Emails, email accounts, templates and inbound email | Separate personal/team email from marketing email; share governed contact and consent data | M07, M10 |
| Cases, Knowledge Base and Portal | Redesign as Service workspace with tenant-aware support entitlements | M07 |
| Campaigns, Target Lists and Mass Email | Reuse suitable records and replace limited workflows with Nexa campaign and email modules | M09, M10 |
| Documents, attachments, notes and stream | Retain storage concepts; apply tenant isolation, permissions, collaboration UI and lifecycle rules | M05, M06 |
| Users, Teams, Roles and Portals | Extend for plan limits, reusable permissions, hierarchy, field security and tenant boundaries | M03, M04 |
| Imports, exports and duplicate checking | Turn into governed, asynchronous data operations with validation and auditability | M05 |
| Administration, entity manager, layouts and settings | Reorganize into tenant administration and operator-only administration | M02, M22 |
| Jobs, scheduled jobs, webhooks and integrations | Wrap with tenant context, idempotency, observability, retries and provider adapters | M01, M20, M22 |
| API | Keep compatible endpoints where useful; add tenant-aware, versioned Nexa APIs and event contracts | M01, M20 |

## Build Phases

### Phase 0 - Product and Engineering Baseline

**Goal:** Establish a reliable shared development setup before feature development.

The working agreement and exit checklist are maintained in [Phase 0 Collaboration and Database Workflow](../development/phase-0-collaboration.md). The tenant isolation decision is recorded in [ADR-0001](../architecture/ADR-0001-tenant-database-isolation.md) and detailed in the [SaaS Data Architecture](../architecture/saas-data-architecture.md).

- Agree Git workflow, protected main branch, issue template, definition of done and ownership.
- Make Docker, XAMPP and WampServer use the same PHP version, extensions, database version, configuration, migrations and seed process.
- Create sanitized fixtures; never share live database files or credentials.
- Establish architecture decisions, coding standards, automated formatting and CI checks.
- Baseline EspoCRM 9.1.9 behavior and record permitted extension points.
- Trace every advanced, detailed and non-functional requirement to acceptance criteria, module ownership and Launch/Growth/Scale entitlements.

**Exit gate:** Both developers can create the same clean environment, run the same smoke tests and load the same seed data.

### Phase 1 - Platform Core and Complete Visual Foundation

**Modules:** M01, M02

- Establish Nexa module namespaces and packaging conventions.
- Build design tokens, typography, controls, tables, forms, modals, empty/error states and responsive patterns.
- Redesign login, application shell, main navigation, global search, the dashboard/widget shell and common record/list views. Governed business metrics are delivered with their source modules and M19.
- Add feature flags, shared audit-event API, error contracts and background-job conventions.
- Inventory every Espo screen and give it an explicit retain/redesign/extend/retire status.

Sprint 04 completes the remaining shared experience through issues #43-#46. M02 owns login, signup, verification, forgotten-password, password-reset and configured social-provider UI states. M04 supplies the secure SSO, MFA, social identity and account-linking contracts in Phase 2; provider buttons must not appear until their provider is configured.

**Exit gate:** Nexa no longer feels like a renamed login page; common desktop and mobile workflows consistently use the Nexa shell.

### Phase 2 - SaaS, Identity and Security Foundation

**Modules:** M03, M04, M22 foundation

- Implement tenant identity and isolation rules before adding customer data.
- Add provisioning, plan entitlements, quotas, usage events and billing-provider boundary.
- Add tenant admin versus platform operator roles.
- Deliver permission sets, teams, hierarchy, SSO hooks, field restrictions and audited impersonation.
- Establish secrets, encryption, audit logging, retention and tenant export/deletion workflows.

Delivery is split across Sprints 05-07: tenant lifecycle, entitlements and role boundaries first; identity, permissions, metering and billing contracts second; secrets, auditing, impersonation and data-lifecycle controls third. Issue #38 then executes the automated isolation exit suite. Sprint issues remain unassigned until a developer starts them.

**Exit gate:** Automated tests prove that one tenant cannot read, change, search, export or process another tenant's data.

### Phase 3 - Unified Customer, CRM, Sales and Service Product

**Modules:** M05, M06, M07, with M01 API contracts

- Establish the canonical Customer 360 identity, relationship graph, lifecycle-stage and chronological timeline contracts.
- Redesign and harden Accounts, Contacts, Leads, lead conversion and Opportunities with the complete field and association requirements.
- Add typed relationships, association labels, custom objects/fields/layouts, formulas, conditional logic, calculated properties and duplicate management.
- Complete multiple pipelines, stage rules, forecasting, activities, calendars, products, projects, documents, collaboration and multi-currency behavior.
- Complete operational email, Cases, queues, SLA timers, escalation, Knowledge Base, Portal and customer-support workflows.
- Deliver tenant-aware CRM APIs plus governed asynchronous import, export, merge and migration tooling.

The tenant fields, layouts, custom objects and relationship sub-gate is recorded in [Phase 3 Customization Exit Gate](../development/phase-3-customization-exit-gate.md).

**Exit gate:** A tenant can operate a connected customer, sales and service lifecycle in which conversion, relationships, activities and support history remain attached to the same governed customer identity.

### Phase 4 - Consent, Content, Segmentation and Campaign Foundation

**Modules:** M08, M09

- Add marketing-contact classification, consent evidence/history, communication preferences, cookie controls and suppression state.
- Build form and landing-page builders, conditional fields, validation, progressive profiling, field mapping and governed form actions.
- Add tenant-aware asset storage, downloads, localization, URL mappings, website content surfaces and reusable presets.
- Build static/dynamic segments, audiences, campaign definitions/enrollment and marketing event records on the Phase 3 customer identity.
- Define event hooks required by tracking, automation and analytics without sending bulk communication yet.

**Exit gate:** Campaign audiences, content, forms and assets are reproducible, consent-aware, tenant-scoped and explainable before tracking or bulk sending begins.

### Phase 5 - Customer Timeline, Tracking and Event Foundation

**Modules:** M11, with M01 event/API contracts

- Introduce a versioned behavioral-event schema, collection API, idempotency contract and transactional outbox boundary.
- Build anonymous and known visitor identity resolution with consent and duplicate-prevention controls.
- Create the permission-aware customer timeline for website, form, asset, email, sales, support, score, segment and external events.
- Add page, landing-page, click, form, asset, video, webinar, purchase, reply and custom API event contracts.
- Define retention, replay, ordering, correlation and high-volume storage boundaries before downstream automation.

**Exit gate:** Events resolve to the correct tenant and customer, remain idempotent and replayable, and provide the trusted history required by email, scoring, automation and attribution.

### Phase 6 - Marketing Email and Visual Automation

**Modules:** M10, M12, then M23 email experiments

- Build drag-and-drop and HTML email editing, templates, personalization tokens, dynamic content and programmable email.
- Add approvals, test sends, scheduling, plan limits, transactional/campaign modes and single-send API.
- Implement sending-domain verification, SPF/DKIM/DMARC guidance, bounce/complaint callbacks, unsubscribe and suppression enforcement.
- Build the visual automation/campaign canvas with enrollment, conditions, branches, delays, business time, actions, retries, cancellation and execution history.
- Add sales, service, form, event, scoring and omnichannel actions with tenant fairness and safety limits.
- Add email A/B testing only after deterministic sending, event tracking and reconciliation are proven.

**Exit gate:** A consented customer can enter a campaign, receive governed communication, generate canonical events, follow deterministic workflow branches and recover from provider or job failure without duplicate actions.

### Phase 7 - Scoring, Personalization and ABM

**Modules:** M13

- Build explainable contact, company and deal scoring models.
- Create target-account workspace, ABM lists and account-based automation.
- Add dynamic personalization backed by deterministic rules before AI-generated decisions.
- Enforce plan limits on scoring models and advanced ABM features.

**Exit gate:** Users can explain why a score or personalization decision occurred and reproduce it from stored inputs.

### Phase 8 - Conversations and Omnichannel Messaging

**Modules:** M14, M15

- Build shared inbox, assignment, views, live chat and draggable widget controls.
- Add conversational bot definitions and safe handoff to staff.
- Add Messenger, SMS and WhatsApp through provider adapters.
- Reuse contact identity, consent, suppression, automation and audit services.

**Exit gate:** One conversation timeline supports channel replies, consent enforcement, assignment and failure recovery.

### Phase 9 - Reporting, Dashboards, Attribution and Customer Journeys

**Modules:** M19

- Establish governed reporting models separated from transactional CRM and high-volume event queries.
- Build the reporting engine for object/property selection, filters, groups, aggregates, tables, charts, funnels, conversion and KPI reports.
- Build personal, team, department, company, marketing, sales, service and custom dashboards on permission-aware metrics.
- Add operational and governed sales, customer, support, campaign and marketing analytics with reconciliation to source records.
- Add first-touch, last-touch and multi-touch attribution with documented model versions.
- Add customer journey analytics, campaign/asset comparison and explainable event paths.

**Exit gate:** Report and dashboard figures reconcile to source records, respect sharing scope and remain stable under documented metric and attribution definitions.

### Phase 10 - Social, Advertising, SEO and Content Intelligence

**Modules:** M16, M17, M18, remaining M23 experiments

- Add social account authorization, publishing, scheduling, inbox and platform-policy controls.
- Add ad accounts, audiences, retargeting and conversion-event synchronization.
- Add SEO recommendations, Search Console data, SEO analytics and YouTube analytics.
- Expand A/B testing to supported marketing content.

**Exit gate:** Each provider adapter has contract tests, token recovery, rate-limit handling, audit trails and graceful degradation.

### Phase 11 - Enterprise Integrations and AI

**Modules:** M20, M21

- Complete the connector framework established in M01 with credential lifecycle, mapping, transformation, conflict resolution, reconciliation and failure queues.
- Add user-authorized Google Calendar and Microsoft Outlook Calendar adapters for configurable one-way or two-way event synchronization with Nexa meetings and calendars.
- Add Google Contacts and Microsoft Outlook Contacts adapters with tenant-scoped ownership, email-based matching, duplicate protection, configurable synchronization direction and field mapping.
- Add provider webhooks where available, scheduled reconciliation, rate-limit handling, retry queues, sync history, conflict resolution, token refresh/revocation and safe disconnect workflows.
- Add prioritized payment, accounting, ecommerce, marketing-platform and custom-application adapters.
- Add Salesforce standard and custom-object synchronization.
- Complete signed webhook subscription, delivery visibility, replay protection and recovery contracts; core domain APIs have already shipped incrementally.
- Build a provider-neutral AI gateway with usage metering, redaction, prompt/version audit and human approval controls.
- Add Anthropic, social agent and social-inbox insight capabilities through the gateway.

**Exit gate:** External sync, webhook and AI failures cannot corrupt CRM records, leak tenant data, duplicate side effects or create unbounded cost.

### Phase 12 - Production Hardening and SaaS Launch

**Modules:** All modules and all NFRs

- Complete load, accessibility, security, tenant-isolation, disaster-recovery and upgrade tests.
- Validate backups and per-tenant restoration; exercise incident and provider-outage runbooks.
- Complete billing reconciliation, usage dashboards, support console and service-status processes.
- Complete licensing review, SBOM, privacy documents, retention policies and operational ownership.
- Run internal alpha, design-partner beta and controlled production rollout.

**Exit gate:** Every Launch requirement has acceptance evidence, an owner, monitoring, support documentation and a rollback path.

## Recommended Release Slices

| Release | Included phases | Product outcome |
|---|---|---|
| R0 Engineering baseline | Phase 0 | The project team can work safely and reproducibly |
| R1 Nexa CRM Alpha | Phases 1-3 | Branded, tenant-aware CRM, sales and service product |
| R2 Marketing MVP | Phases 4-6 | Consent-aware campaigns, email, tracking and automation |
| R3 Growth Suite | Phases 7-9 | Scoring, ABM, conversations and trustworthy analytics |
| R4 Channel Suite | Phase 10 | Social, ads, SEO and content experiments |
| R5 Enterprise Beta | Phase 11 | Enterprise connectors, synchronization and governed AI capabilities |
| R6 SaaS General Availability | Phase 12 | Hardened production SaaS with operational evidence |

Do not build all phases concurrently. Maintain one platform workstream and one product workstream inside the active phase, and finish shared contracts before starting dependent modules.

## Team Delivery and Ownership Model

| Workstream | Primary responsibility | Shared responsibility |
|---|---|---|
| Platform lead | Architecture, database contracts, APIs, tenant isolation, security, infrastructure and CI/CD | Backend review, migrations, releases and operational requirements |
| Product lead | Product workflows, frontend, responsive UX, accessibility and acceptance criteria | Regression testing, API usability and product documentation |
| Both developers | Deliver the active phase and its modules end to end | Planning, integration, testing, documentation and release approval |

Phases define dependency order and release gates, modules define architecture and ownership boundaries, and sprints define the work selected for delivery. Both workstreams operate within the same active phase instead of assigning whole groups of modules permanently to one person.

For every active module, one developer is the implementation lead and the other is the review and integration lead. Ownership is not exclusivity: roles rotate between modules and major features so the platform, data model and product experience remain shared team knowledge.

## Mandatory Definition of Done

A module or feature is not complete until it has:

1. Stable advanced/core/NFR requirement IDs, approved acceptance criteria and plan entitlement.
2. Tenant isolation and permission tests.
3. Desktop and mobile UX using the Nexa design system.
4. Accessibility and localization consideration.
5. Automated unit, integration and relevant end-to-end tests.
6. Audit events, metrics, logs and actionable failure states.
7. Migration, seed data, rollback and upgrade behavior.
8. User, administrator, API, event and data-dictionary documentation where applicable.
9. Security, privacy, retention and provider-cost review.
10. Product-owner acceptance and peer code review.

## Immediate Backlog

1. Complete the Phase 1 shared product experience through issues #43-#46.
2. Deliver the Phase 2 tenant control plane through issues #35-#37.
3. Deliver permissions, identity-provider, usage and billing contracts through issues #39, #40, #47 and #48.
4. Deliver security, audit, impersonation and data-lifecycle controls through issues #49-#51.
5. Pass the Phase 2 isolation and lifecycle gate in issue #38.
6. Expand traceability ranges into Ready issues with acceptance criteria, source strategy, data contracts and release assignment as each module enters discovery.
7. Deliver R1 before beginning marketing-email or automation implementation.

The shared-schema tenancy strategy and trusted tenant runtime are accepted and implemented through ADR-0002, issues #19 and #21, and PRs #20 and #24. They are no longer open product decisions.
