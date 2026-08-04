# Application Screen Inventory

This inventory is the redesign map for the authenticated application, unauthenticated acquisition experience and tenant administration. Permission variants are verified with a tenant administrator and a restricted member.

| Area | Routes and screens | Desktop behavior | Tablet and mobile behavior | Shared foundations | Module | Decision |
|---|---|---|---|---|---|---|
| Public | `/`, pricing anchors, feature sections | Full acquisition page and persistent header actions | Collapsed header, stacked content and touch-sized actions | Public header, footer, plan and feature patterns | M02 | Replace |
| Authentication | `/login`, password recovery and secure reset | Branded sign-in, neutral recovery and configuration-gated social entry points | Single-column form with visible labels, focus order and errors | Auth layout, form, alert, loading and expiry states | M02/M04 | Replace |
| Signup | `?signup=<plan>`, email-code verification and resend | Plan-aware account and workspace creation with an eight-digit email code | Single-column progressive workflow with one-time-code input | Auth layout, form, code input, success and failure states | M02/M04 | Extend |
| Home | `#Home`, dashboard layouts and dashlets | Persistent sidebar, header and configurable dashboard | Drawer navigation and single-column dashlets | App shell, toolbar, grid, empty/loading states | M02/M19 | Replace |
| CRM lists | Accounts, Contacts, Leads, Opportunities, Cases, Targets | Dense table, filters, bulk actions and saved views | Responsive rows, horizontal containment and compact filters | Table, filters, toolbar, pagination | M05/M06 | Extend |
| CRM records | create, detail, edit, relationship panels, stream | Two-column record workspace and related panels | One-column sections and sticky primary action | Form, field, tabs, activity stream, dialog | M05/M06 | Extend |
| Activities | Emails, Calls, Meetings, Tasks, Calendar | Shared activity toolbar and calendar modes | Touch calendar/list modes and compact composer | Composer, calendar, table, dialog | M06/M12 | Extend |
| Marketing | Campaigns, Target Lists, Mass Email, Email Templates | Campaign workspace and asset tables | Drawer navigation and stacked editors | Editor shell, table, status, approval | M08-M10 | Replace |
| Automation | workflows, journeys, scoring, lifecycle automation and personalization | Canvas/list workspaces when routes are delivered | Readable list fallback; canvas pans without page overflow | Canvas toolbar, inspector, status, history | M12/M13 | Add |
| Engagement | shared inbox, chat, bots, SMS, WhatsApp, social and advertising | Multi-pane operational workspace | Single-pane drill-in navigation | Inbox, thread, composer, channel badge | M14-M17 | Add |
| Intelligence | reports, dashboards, attribution, SEO and contextual AI | Filterable report and insight workspaces | Stacked charts and accessible data tables | Chart frame, filters, comparison, export | M18/M19/M21 | Extend |
| Documents | Documents, Knowledge Base, templates and governed assets | Folder/list workspace and editor | List-first navigation and full-width editor | Tree, table, uploader, editor | M05-M08 | Extend |
| Tenant settings | `#Admin`, users, teams, roles, integrations, preferences, plan and workspace configuration | Tenant-only settings index and detail routes | Searchable settings list and full-page detail | Settings shell, form, permission notice | M03/M04/M20/M22 | Extend |
| Personal settings | preferences, profile and account actions | Account menu and focused settings form | Full-width account menu and form | Account menu, form, session controls | M02/M04 | Retain |
| Platform operations | future operator console | Separate operator application boundary | Responsive operational views | Platform shell, audit and impersonation notice | M01/M03/M04/M22 | Add later |
| Errors | 401, 403, 404, 409, 422 and 500 states | In-context problem with recovery action | Full-width, concise recovery state | Alert, empty state, retry action | M02 | Replace |

## Shared Layouts and Permissions

- The public, authentication, tenant application and future platform-operator shells are separate layouts.
- Tenant identity is always visible in the authenticated shell. Platform controls never appear in the tenant shell.
- Navigation is generated from route availability and permissions. Planned items may be shown as disabled roadmap markers, never as working links.
- Restricted members see the same layout with unavailable records and actions removed. A hidden action is not a substitute for server authorization.
- Desktop baseline is 1440 x 900, tablet baselines are 1024 x 768 and 768 x 1024, and mobile baselines are 390 x 844 and 844 x 390.
## Canonical Tenant Navigation

The tenant sidebar is organized around stable user workspaces rather than individual requirements. Existing routes are reused and missing routes remain disabled until their backend, permission and entitlement contracts are available.

| Workspace | Standard submenu |
|---|---|
| Home | Dashboard, my tasks and recent activity |
| CRM | Companies, contacts, leads, customer timeline, lists and segments, custom objects |
| Sales | Deals, activities, calendar, pipelines, forecasts, products, quotes and documents |
| Marketing | Campaigns, marketing contacts, marketing email, forms, landing pages, content and assets, events, social, advertising, SEO and experiments |
| Automation | Workflows, customer journeys, scoring and lifecycle, personalization, target accounts and ABM |
| Service | Cases, operational email, knowledge base, shared inbox, live chat and bots, queues and SLAs, customer portal, SMS and WhatsApp |
| Analytics | Dashboards, reports, campaign analytics, attribution, journey analytics, website traffic and email performance |
| Data & Integrations | Tracking and events, data quality, import and export, integrations, API and webhooks |
| Administration | The existing `#Admin` application is expanded for tenant users, teams, roles, permissions, security, channels, domains, consent, integrations, data management, plan, usage and billing settings |

Administration is not duplicated in the tenant sidebar. Tenant administrators continue to open the existing Administration application from the account menu. Cross-tenant SaaS operations belong to a separate operator application and are never exposed in the tenant workspace.

## Redesign Sequence

M02 shell and shared components are delivered first. CRM list and record patterns follow, then activity and marketing workspaces. New automation and engagement screens are added only when their routes and backend contracts exist.
