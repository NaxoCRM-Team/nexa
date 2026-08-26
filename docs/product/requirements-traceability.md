# Nexa Requirements Traceability Matrix

## Purpose

This matrix connects every section of the [Unified CRM and Marketing Automation Specification](unified-product-specification.md) to stable requirement namespaces, owning modules and delivery phases. It complements, rather than renumbers, the advanced `F-001` through `F-086` capabilities and `NFR-001` through `NFR-070` requirements.

A section is **aligned** when it has an explicit module and phase home. It is **implementation-ready** only after its individual requirements have acceptance criteria, data contracts, entitlements, dependencies and tests in the GitHub backlog.

## Namespace Rules

| Namespace | Scope |
|---|---|
| `PLAT` | Platform runtime, jobs, files, notifications and shared services |
| `IAM` | Identity, teams, permissions, sessions and security |
| `CRM` | Unified customer records, core entities, lifecycle and relationships |
| `SALES` | Opportunities, pipelines, activities, calendars and forecasting |
| `SERVICE` | Cases, SLAs, queues, portals and support automation |
| `MKT` | Consent, segments, campaigns, forms, content and email marketing |
| `EVENT` | Visitor identity, behavioral events and customer timeline |
| `AUTO` | Workflow definitions, execution, branches, delays and actions |
| `ANALYTICS` | Reports, dashboards, attribution and governed metrics |
| `EXT` | APIs, webhooks, provider connections and synchronization |
| `DATA` | Customization, formulas, import/export and data quality |
| `DEL` | Documentation, deployment, testing and release evidence |

## Specification Coverage

| Section | Requirement range | Primary modules | Delivery phase(s) | Alignment decision |
|---|---|---|---|---|
| 1. Core system foundation | PLAT-001..035, IAM-001..012 | M01, M02, M03, M04, M22 | 0-2 | Shared runtime, SaaS control plane, identity and UX foundation |
| 2. Unified customer record | CRM-001..018, EVENT-001 | M05, M11, M19 | 3, 5, 9 | M05 owns Customer 360 identity; M11 supplies events; M19 supplies governed analysis |
| 3. Core CRM entities | CRM-019..033 | M05, M06, M07 | 3 | Retain useful entity foundations and complete Nexa workflows |
| 4. Contacts | CRM-034..078 | M05 | 3 | Canonical person profile, associations, consent and activity summaries |
| 5. Accounts and companies | CRM-079..105 | M05 | 3 | Organization hierarchy, ownership, scoring and associations |
| 6. Leads | CRM-106..135 | M05, M06, M11 | 3, 5 | Conversion must preserve history, behavior, campaigns, score and segments |
| 7. Opportunities | SALES-001..030 | M05, M06, M12, M19 | 3, 6, 9 | Pipelines, stage rules, automation, forecasting and outcomes |
| 8. Cases and support tickets | SERVICE-001..032 | M07, M12, M19 | 3, 6, 9 | Queues, SLA, escalation, portal and support analytics |
| 9. Activity management | SALES-031..055 | M06, M07 | 3 | One activity model across CRM, service and custom records |
| 10. Calendar | SALES-056..068 | M06, M20 | 3, 11 | Personal/team calendar first; Google Calendar and Microsoft Outlook synchronization through user-authorized adapters |
| 11. Email system | SERVICE-033..055, MKT-001..012 | M07, M10, M11 | 3, 5, 6 | Separate operational email from governed marketing delivery and tracking |
| 12. Anonymous visitor tracking | EVENT-002..012 | M11 | 5 | Consent-aware anonymous identity and deterministic contact resolution |
| 13. Event tracking | EVENT-013..026 | M11 | 5 | Versioned ingestion API and extensible event schema |
| 14. Customer behavior timeline | EVENT-027..040 | M05, M11 | 3, 5 | One permission-aware chronological history for every customer |
| 15. Segmentation engine | MKT-013..036 | M09, M11 | 4, 5 | Static and dynamic membership with explainable recalculation |
| 16. Lead-scoring engine | MKT-037..060 | M13, M11 | 7 | Versioned, explainable scoring models and score history |
| 17. Lifecycle and customer stages | CRM-136..155 | M05, M12, M13 | 3, 6, 7 | Configurable stages are core data; automation and scoring may transition them |
| 18. Campaign builder | MKT-061..105, AUTO-001..020 | M09, M12 | 4, 6 | M09 owns audiences/enrollment; M12 owns visual execution and branching |
| 19. Email marketing | MKT-106..135 | M10, M11, M23 | 5, 6 | Event foundation precedes tracked sending and experimentation |
| 20. Dynamic content | MKT-136..148 | M08, M10, M13 | 4, 6, 7 | Shared deterministic decision contract across content surfaces |
| 21. Form builder | MKT-149..180 | M08, M12 | 4, 6 | Builder and capture in M08; downstream orchestration in M12 |
| 22. Landing-page builder | MKT-181..195 | M08 | 4 | Tenant domains, reusable blocks, forms, SEO, tracking and analytics hooks |
| 23. Asset management | MKT-196..210, EVENT-041 | M08, M11 | 4, 5 | Governed storage plus download and attribution events |
| 24. Website personalization | MKT-211..225 | M08, M13 | 4, 7 | Content surfaces first, deterministic personalization after scoring/segments |
| 25. Automation engine | AUTO-021..065 | M12 | 6 | Versioned trigger/action engine with retries, history and cancellation |
| 26. Customer support automation | SERVICE-056..070 | M07, M12 | 3, 6 | Service owns SLA policy; automation executes governed actions |
| 27. Sales automation | SALES-069..085 | M06, M12, M13 | 3, 6, 7 | Uses lifecycle, behavior, score and ownership contracts |
| 28. Marketing analytics | ANALYTICS-001..025 | M10, M11, M19 | 5, 6, 9 | Operational send/event metrics first; governed analytics in M19 |
| 29. Sales analytics | ANALYTICS-026..040 | M06, M19 | 3, 9 | Reconciled pipeline, conversion, forecast and performance metrics |
| 30. Customer analytics | ANALYTICS-041..052 | M05, M19 | 3, 9 | Governed lifecycle, retention, engagement and value metrics |
| 31. Support analytics | ANALYTICS-053..065 | M07, M19 | 3, 9 | SLA, response, resolution, satisfaction and workload metrics |
| 32. Campaign analytics | ANALYTICS-066..078 | M09, M12, M19 | 4, 6, 9 | Enrollment/execution facts feed governed campaign reporting |
| 33. Attribution | ANALYTICS-079..095 | M11, M17, M19 | 5, 9, 10 | Versioned first-, last- and multi-touch models from canonical events |
| 34. Customization engine | DATA-001..040 | M02, M05 | 1, 3 | Custom entities, fields, layouts, tabs, menus, views and dashboards |
| 35. Relationship engine | DATA-041..058 | M05 | 3 | Typed one-to-one, one-to-many, many-to-many and parent-child associations |
| 36. Dynamic logic | DATA-059..075 | M04, M05, M12 | 2, 3, 6 | Conditional presentation, validation, automation and authorized access |
| 37. Formula engine | DATA-076..090 | M05 | 3 | Versioned mathematical, date, text, conditional and record formulas |
| 38. Reporting engine | ANALYTICS-096..125 | M19 | 9 | Governed object selection, aggregation, funnels, charts and KPI reports |
| 39. Dashboards | ANALYTICS-126..150 | M02, M19 | 1, 9 | M02 owns shell/widgets; M19 owns governed data and sharing scopes |
| 40. API | EXT-001..040 | M01, domain modules, M20 | 0-11 | APIs ship with owning domains under common versioning and tenant contracts |
| 41. Webhooks | EXT-041..060 | M01, M12, M20 | 0, 6, 11 | Signed subscriptions, delivery history, retries, replay protection and events |
| 42. Integration engine | EXT-061..095 | M01, M15-M20 | 0, 8-11 | Provider contract begins in M01; Phase 11 completes Google/Microsoft calendar and contact sync with mapping, reconciliation and conflict handling |
| 43. Data management | DATA-091..115 | M05, M20 | 3, 11 | Governed asynchronous import, export, transform, merge, history and sync |
| 44. Compliance and consent | IAM-013..025, MKT-226..240 | M04, M08, M10, M22 | 2, 4, 6 | Consent evidence, preferences, suppression, privacy export and erasure |
| 45. Security | IAM-026..050 | M01, M04, M22 | 2, 12 | Security controls are release gates, not optional feature work |
| 46. Required development principle | PLAT-036 | All | 0-12 | Shared customer identity and timeline are mandatory cross-module contracts |
| 47. Deliverable | DEL-001..045 | All | 0-12 | Source, schema, APIs, documentation, tests, security and deployment evidence |

## Cross-Cutting Dependency Gates

1. Tenant context, service entitlement and permission enforcement precede tenant-owned feature data.
2. Customer identity, lifecycle and relationship contracts precede marketing segmentation and automation.
3. Consent and suppression precede tracking and bulk communication.
4. Event ingestion and identity resolution precede tracked marketing email, scoring and attribution.
5. Domain APIs and outbox events ship with their owning modules; M20 does not postpone core APIs until Phase 11.
6. Dashboard shells may ship in Phase 1, but governed business metrics ship with M19 and their source domains.
7. Every later feature must reference both its detailed requirement range and any related `F-*` and `NFR-*` requirements.

## Readiness Record

Before a requirement range enters a sprint, its issue must record:

- stable requirement IDs and related advanced capability IDs;
- observable acceptance criteria and plan entitlement;
- source strategy: retain, extend, replace, build or integrate;
- entity, migration, event, API and data-retention impact;
- tenant, service, permission, consent and audit rules;
- dependency and rollback behavior;
- unit, integration, isolation, accessibility and end-to-end evidence;
- user, administrator, API and operational documentation impact.
