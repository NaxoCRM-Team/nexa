# Nexa CRM Product Requirements Inventory

## Purpose

This document is the canonical inventory of Nexa's **86 advanced SaaS, marketing and growth capabilities** and **70 non-functional SaaS requirements**. Every listed requirement must be implemented and operational in the product.

The operational CRM, sales, service, marketing automation, data, API and delivery requirements are defined in the [Unified CRM and Marketing Automation Specification](unified-product-specification.md). The [Requirements Traceability Matrix](requirements-traceability.md) connects both requirement sets to modules, phases, dependencies and acceptance evidence.

Implementation order is defined in the [Nexa CRM Module and Build Roadmap](module-build-roadmap.md).

## Advanced Functional Requirements

| ID | Domain | Requirement |
|---|---|---|
| F-001 | Plans and usage | Email marketing tiers and limits |
| F-002 | Brand | Complete Nexa rebranding and removal of upstream product branding where licensing permits |
| F-003 | Product | Additional features (scope must be defined) |
| F-004 | Automation | Simple marketing automation |
| F-005 | Analytics | Campaign reporting with plan limits of 5,000 and 10,000 campaigns |
| F-006 | Social | Social accounts, publishing, scheduling and post limits |
| F-007 | Marketing | Marketing events object |
| F-008 | Conversations | Rebrandable live chat |
| F-009 | Automation | Email automation with unlimited actions subject to plan and safety limits |
| F-010 | Privacy | Cookie management tools |
| F-011 | Conversations | Facebook Messenger integration |
| F-012 | Experience | Mobile optimization |
| F-013 | Email | Email health reporting |
| F-014 | Domains | Subdomain and country-code top-level domain availability |
| F-015 | Identity | Social login |
| F-016 | Automation | Form automation with unlimited actions subject to plan and safety limits |
| F-017 | Advertising | Ad management |
| F-018 | Conversations | Conversational bots |
| F-019 | SEO | SEO recommendations and optimization |
| F-020 | Email | Team email |
| F-021 | Analytics | Website traffic analytics |
| F-022 | Email | Email reply tracking |
| F-023 | Advertising | Ad retargeting |
| F-024 | Security | Permission sets and reusable permission templates |
| F-025 | Personalization | Personalization tokens |
| F-026 | Data model | Required fields |
| F-027 | Support | Email and in-app chat support |
| F-028 | Support | One-to-one technical support by email, chat and phone according to plan |
| F-029 | CRM | Multiple currencies |
| F-030 | Advertising | Simple ad automation |
| F-031 | Email | Programmable email powered by CRM-object data |
| F-032 | Content | URL mappings and redirects |
| F-033 | Personalization | Smart content for marketing email |
| F-034 | Content | Video hosting and management |
| F-035 | Analytics | Custom reporting with plan limits of 100 and 500 reports |
| F-036 | Conversations | Custom views in the shared inbox |
| F-037 | Automation | Omnichannel marketing automation |
| F-038 | Organization | Teams with plan limits of 10 and 300 teams |
| F-039 | Data quality | Duplicate management |
| F-040 | Data model | Association labels between object pairs |
| F-041 | Experimentation | A/B testing |
| F-042 | Content | Multi-language content |
| F-043 | Analytics | Filtered analytics views |
| F-044 | Marketing | Campaign management |
| F-045 | ABM | Account-based marketing tools and automation |
| F-046 | Messaging | Marketing SMS add-on |
| F-047 | Data model | Calculated properties |
| F-048 | Messaging | WhatsApp integration |
| F-049 | Scoring | Lead scoring application with plan limits of 5 and 50 models |
| F-050 | Collaboration | Collaboration tools |
| F-051 | Personalization | Dynamic personalization |
| F-052 | AI | AI social agent equivalent to the referenced Breeze capability |
| F-053 | Tracking | Logged-in visitor identification |
| F-054 | Analytics | Marketing asset comparison reporting |
| F-055 | Configuration | Reusable presets with plan limits of 5 and 100 presets |
| F-056 | Scoring | Standard contact scoring |
| F-057 | Integrations | Salesforce integration |
| F-058 | Integrations | Google Search Console integration |
| F-059 | Experience | Standard CRM interface configuration |
| F-060 | Attribution | Contact creation attribution |
| F-061 | Scoring | Deal and company scoring |
| F-062 | SEO | SEO analytics |
| F-063 | Conversations | Draggable chat widget |
| F-064 | Advertising | Ad conversion events |
| F-065 | ABM | Target accounts home |
| F-066 | Automation | Behavioral event triggers and reporting |
| F-067 | Organization | Team organization and hierarchy |
| F-068 | Data model | Custom objects |
| F-069 | Analytics | Customer journey analytics |
| F-070 | Tracking | Custom events |
| F-071 | Governance | Email approval workflows |
| F-072 | Security | Limit access to content and data |
| F-073 | Administration | Log in as another user with auditing and safeguards |
| F-074 | Identity | Single sign-on |
| F-075 | Email infrastructure | Hosting and domains for marketing email |
| F-076 | Administration | Admin notification management |
| F-077 | SaaS | Standard sandbox account |
| F-078 | Integrations | Salesforce custom-object synchronization |
| F-079 | Privacy | Sensitive-data classification and protection |
| F-080 | AI | Anthropic integration |
| F-081 | Attribution | Multi-touch revenue attribution |
| F-082 | Tracking | Visual custom-event builder |
| F-083 | Security | Field-level permissions |
| F-084 | AI | AI social-inbox insights |
| F-085 | Email API | Marketing email single-send API |
| F-086 | Integrations | YouTube analytics integration |

## Core Product Requirement Namespaces

The detailed specification uses stable domain namespaces so operational requirements can be traced without renumbering the advanced `F-*` catalogue.

| Namespace | Product area |
|---|---|
| `PLAT-*` | Platform runtime, shared services, jobs, files and notifications |
| `IAM-*` | Identity, teams, permissions, sessions, privacy and security |
| `CRM-*` | Unified customer record, core entities, lifecycle and associations |
| `SALES-*` | Opportunities, pipelines, activities, calendars and forecasting |
| `SERVICE-*` | Cases, SLAs, queues, portals and operational email |
| `MKT-*` | Consent, segments, campaigns, forms, content and marketing email |
| `EVENT-*` | Visitor identity, behavioral events and customer timeline |
| `AUTO-*` | Workflow definitions, execution, branching, delays and actions |
| `ANALYTICS-*` | Reports, dashboards, attribution and governed metrics |
| `EXT-*` | APIs, webhooks, provider connections and synchronization |
| `DATA-*` | Customization, relationships, formulas and data management |
| `DEL-*` | Source, schema, documentation, testing and release evidence |

The ranges and module assignments are controlled by the [Requirements Traceability Matrix](requirements-traceability.md). A requirement is not implementation-ready merely because it has a namespace; its backlog issue must satisfy the readiness contract below.

## Non-Functional SaaS Requirements

### Tenancy and SaaS Operations

| ID | Requirement |
|---|---|
| NFR-001 | Strong tenant isolation across application, database, cache, queues, files, logs and analytics |
| NFR-002 | Automated tenant provisioning, suspension, reactivation and deletion |
| NFR-003 | Subscription plans, billing cycles, invoices, taxes, trials, coupons and failed-payment handling |
| NFR-004 | Central feature entitlements and plan-based capability enforcement |
| NFR-005 | Accurate usage metering for contacts, sends, storage, events, users, workflows and integrations |
| NFR-006 | Hard and soft quota enforcement with warnings, grace periods and overage handling |
| NFR-007 | Custom domains with automated DNS verification, TLS issuance and renewal |
| NFR-008 | Tenant-aware configuration, secrets and branding |
| NFR-009 | Tenant-level data export, account closure and secure erasure |
| NFR-010 | Per-tenant backup, restore, cloning and sandbox creation |

### Security, Identity and Privacy

| ID | Requirement |
|---|---|
| NFR-011 | Encryption in transit and at rest, including backups and object storage |
| NFR-012 | Central secrets management, credential rotation and separation by environment and tenant |
| NFR-013 | Multi-factor authentication, secure session handling and account-recovery controls |
| NFR-014 | Least-privilege authorization with deny-by-default behavior |
| NFR-015 | Immutable security, administrator, impersonation and data-access audit trails |
| NFR-016 | OWASP-aligned application security, CSRF/XSS/SSRF/SQL-injection controls and secure headers |
| NFR-017 | API rate limiting, abuse detection, bot protection and denial-of-service safeguards |
| NFR-018 | Dependency, container, secret and source-code vulnerability scanning |
| NFR-019 | Privacy rights workflows for access, correction, portability, objection and erasure |
| NFR-020 | Configurable data retention, legal holds and automated deletion schedules |
| NFR-021 | Consent evidence including purpose, source, policy version, timestamp and withdrawal history |
| NFR-022 | Data classification, masking, redaction and controlled export of sensitive fields |
| NFR-023 | Regional data residency and tenant-selectable processing regions |
| NFR-024 | Security incident response, breach investigation and notification procedures |

### Reliability and Data Integrity

| ID | Requirement |
|---|---|
| NFR-025 | Defined service-level objectives for availability, latency and job completion |
| NFR-026 | Automated backups with documented retention and routinely tested restoration |
| NFR-027 | Disaster recovery with defined recovery-point and recovery-time objectives |
| NFR-028 | Idempotent background jobs and webhook processing |
| NFR-029 | Retry policies, exponential backoff, dead-letter queues and controlled replay |
| NFR-030 | Transactional outbox or equivalent protection against lost integration events |
| NFR-031 | Cross-system reconciliation and repair for contacts, messages, usage and billing data |
| NFR-032 | Graceful degradation when email, AI, social or advertising providers are unavailable |
| NFR-033 | Safe database migrations with validation, rollback and tenant-by-tenant rollout |
| NFR-034 | Zero- or low-downtime deployment with rapid rollback |

### Performance and Scalability

| ID | Requirement |
|---|---|
| NFR-035 | Explicit response-time, throughput and concurrency targets for critical workflows |
| NFR-036 | Horizontal scaling for web, queue-worker, tracking and API workloads |
| NFR-037 | Database indexing, query budgets, slow-query detection and capacity management |
| NFR-038 | Caching strategy with tenant-safe keys and predictable invalidation |
| NFR-039 | High-volume event ingestion separated from transactional CRM workloads |
| NFR-040 | Bulk import, export, update and deletion without blocking interactive users |
| NFR-041 | Email throughput controls, provider rate adaptation and fair use across tenants |
| NFR-042 | Storage lifecycle policies for attachments, exports, tracking data and logs |

### Observability and Supportability

| ID | Requirement |
|---|---|
| NFR-043 | Centralized structured logs with correlation, tenant and request identifiers |
| NFR-044 | Metrics, distributed tracing, dashboards and actionable alerting |
| NFR-045 | Queue, scheduled-job, webhook and integration health monitoring |
| NFR-046 | Per-tenant operational health and usage visibility for support staff |
| NFR-047 | Public service-status communication and planned-maintenance procedures |
| NFR-048 | Support tooling that protects sensitive data and audits every privileged action |
| NFR-049 | Runbooks for incidents, failed jobs, provider outages, restoration and tenant migration |

### Email, Messaging and External Providers

| ID | Requirement |
|---|---|
| NFR-050 | SPF, DKIM, DMARC, return-path and sending-domain verification |
| NFR-051 | Global and tenant suppression, complaint, hard-bounce and unsubscribe enforcement |
| NFR-052 | Deliverability monitoring, reputation protection, warm-up and provider feedback processing |
| NFR-053 | Provider abstraction and failover strategy without duplicate message delivery |
| NFR-054 | External API versioning, token refresh, permission review and platform-policy compliance |
| NFR-055 | Signed webhooks with replay protection, ordering strategy and delivery visibility |

### Engineering Quality and Delivery

| ID | Requirement |
|---|---|
| NFR-056 | Version-controlled schema metadata, migrations, fixtures and idempotent seeders |
| NFR-057 | Automated unit, integration, contract, end-to-end, accessibility and security tests |
| NFR-058 | Continuous integration with required reviews and protected release branches |
| NFR-059 | Reproducible local, staging and production environments for Docker, XAMPP and WampServer contributors |
| NFR-060 | Feature flags and staged releases for high-risk capabilities |
| NFR-061 | API and event-schema versioning with backward-compatibility policy |
| NFR-062 | Maintained architecture decisions, API documentation, data dictionary and operational guides |
| NFR-063 | Software bill of materials, third-party licence inventory and commercial-use review |
| NFR-064 | Automated tenant-aware acceptance tests before deployment |

### User Experience and Accessibility

| ID | Requirement |
|---|---|
| NFR-065 | WCAG 2.2 AA accessibility target for customer-facing and administration interfaces |
| NFR-066 | Supported browser, device and responsive-layout policy |
| NFR-067 | Consistent Nexa design system across CRM, marketing, conversations, analytics and billing |
| NFR-068 | Internationalization for language, locale, currency, date, number and timezone handling |
| NFR-069 | Global navigation, search and notifications across all product modules |
| NFR-070 | Clear loading, empty, error, partial-failure and recovery states |

## Required Traceability Fields

Every advanced, core-product and non-functional requirement must include before implementation:

| Field | Purpose |
|---|---|
| Status | Proposed, approved, in progress, delivered, or retired |
| Source | Espo reuse, Espo extension, Nexa build, or external integration |
| Acceptance criteria | Observable behavior required for completion |
| Plan entitlement | Launch, Growth, Scale, add-on, or internal only |
| Dependencies | Required platform, provider, data model, or preceding capability |
| Release | Target product milestone |
| Owner | Responsible developer |
| Tests | Required automated and manual verification |
| Operational impact | Scaling, security, support and cost implications |
