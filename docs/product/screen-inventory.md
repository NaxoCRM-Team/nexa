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
| Activities | Emails, Calls, Meetings, Tasks, Calendar | Shared activity toolbar and calendar modes | Touch calendar/list modes and compact composer | Composer, calendar, table, dialog | M07/M12 | Extend |
| Marketing | Campaigns, Target Lists, Mass Email, Email Templates | Campaign workspace and asset tables | Drawer navigation and stacked editors | Editor shell, table, status, approval | M08-M10 | Replace |
| Automation | planned forms, workflows, journeys, scoring and events | Canvas/list workspaces when routes are delivered | Readable list fallback; canvas pans without page overflow | Canvas toolbar, inspector, status, history | M09-M11 | Add |
| Engagement | planned inbox, chat, bots, SMS, WhatsApp and social | Multi-pane operational workspace | Single-pane drill-in navigation | Inbox, thread, composer, channel badge | M12-M15 | Add |
| Intelligence | reports, dashboards, attribution, SEO and AI | Filterable report and insight workspaces | Stacked charts and accessible data tables | Chart frame, filters, comparison, export | M18-M21 | Extend |
| Documents | Documents, Knowledge Base and templates | Folder/list workspace and editor | List-first navigation and full-width editor | Tree, table, uploader, editor | M17 | Extend |
| Tenant settings | `#Admin`, users, teams, roles, integrations, preferences | Tenant-only settings index and detail routes | Searchable settings list and full-page detail | Settings shell, form, permission notice | M03/M04/M22 | Extend |
| Personal settings | preferences, profile and account actions | Account menu and focused settings form | Full-width account menu and form | Account menu, form, session controls | M02/M03 | Retain |
| Platform operations | future operator console | Separate operator application boundary | Responsive operational views | Platform shell, audit and impersonation notice | M01/M04 | Add later |
| Errors | 401, 403, 404, 409, 422 and 500 states | In-context problem with recovery action | Full-width, concise recovery state | Alert, empty state, retry action | M02 | Replace |

## Shared Layouts and Permissions

- The public, authentication, tenant application and future platform-operator shells are separate layouts.
- Tenant identity is always visible in the authenticated shell. Platform controls never appear in the tenant shell.
- Navigation is generated from route availability and permissions. Planned items may be shown as disabled roadmap markers, never as working links.
- Restricted members see the same layout with unavailable records and actions removed. A hidden action is not a substitute for server authorization.
- Desktop baseline is 1440 x 900, tablet baselines are 1024 x 768 and 768 x 1024, and mobile baselines are 390 x 844 and 844 x 390.

## Redesign Sequence

M02 shell and shared components are delivered first. CRM list and record patterns follow, then activity and marketing workspaces. New automation and engagement screens are added only when their routes and backend contracts exist.
