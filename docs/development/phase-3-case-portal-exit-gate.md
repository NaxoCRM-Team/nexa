# Phase 3 Case and Portal Exit Gate

Issue: `#89`

## Native capabilities retained

- The native `Case` entity, repository, API, record lifecycle and relationships remain authoritative.
- Native single-owner and team assignment remain authoritative. The earlier parallel multi-assignee override was removed and existing links were reconciled to `assigned_user_id`.
- Native Portal runtime, Portal roles, portal layouts and contact/account Case ACL remain in use.
- Native inbound email-to-Case creation and owner distribution remain in use.

## Nexa additions

- Modern Case list, create, edit and three-column service workspace views.
- Customer-safe Portal list and detail layouts using the same Case record.
- Tenant/service-owned SLA policies with priority and optional category matching.
- First-response and resolution deadlines, pause/resume accounting, completion timestamps and escalation state.
- Commercial context links for Opportunities and quote snapshots.
- A scheduled SLA monitor and an explicit first-response action.

## Isolation and authorization

- Case records continue through the central tenant query processor and native record ACL.
- SLA policies are keyed by `tenant_id` and `service_id`; their API requires the current tenant context and CRM entitlement.
- SLA policy administration is limited to tenant administrators.
- Portal users retain native Case ownership checks and cannot access internal Cases.

## Verification evidence

- `tests/workflows/CasePortalWorkflowTest.php` verifies native reuse, Portal restrictions and the redesigned contract.
- `tests/tenant/TenantCaseSlaTest.php` verifies policy selection, lifecycle timestamps and two-tenant isolation against MariaDB.
- `tests/browser/live-case-workspace.spec.js` verifies the authenticated Case workspace at desktop, tablet and mobile viewport families.
- The shared migration runner applies `0042_add_case_service_sla.sql` once and records it in `nexa_schema_migration`.

## Operational notes

- Run `php rebuild.php`, `php clear_cache.php` and `php command.php update-app-timestamp` after deployment.
- Keep `cron.php` active so the `MonitorCaseSla` scheduled job can mark breached commitments.
- Configure Portal users and roles through the retained native Administration screens; this delivery does not introduce a second portal engine.
