# Phase 3 Activity and Collaboration Exit Gate

## Scope

This evidence covers the M06 activity, calendar and collaboration slice of Phase 3:

- a tenant-aware agenda across native Tasks, Calls and Meetings;
- a redesigned native Calendar as the sole calendar workspace;
- owner and activity-type filters governed by native record and scope ACL;
- native create, detail, reminders, recurrence, attendees and scheduling behavior rather than replacement entities;
- a redesigned native Document module as the sole document workspace;
- tenant-owned Projects with status, priority, dates, ownership and archive lifecycle;
- project updates and associations to native Tasks and Documents;
- tenant, service and native record ACL enforcement on project reads and writes;
- responsive desktop, tablet and mobile layouts with loading, empty, error and permission-denied states.
- legacy duplicate Activity Calendar and Documents URLs redirected to their authoritative native modules.

This sub-gate does not close Phase 3. Service and operational-email delivery and the complete Phase 3 data-operation acceptance gate remain attached to their own backlog issues. Google Calendar, Microsoft Outlook Calendar and contact synchronization remain Phase 11 integration work.

## Database Evidence

Migration `0041_add_project_collaboration.sql` adds service-owned project, project-update and native Task/Document association tables. Every new table requires both `tenant_id` and `service_id`; project links do not copy or replace native activity and document records.

All four tables are classified in `database/shared/table-ownership-manifest.json`, and the migration is part of the required clean-install verification set.

## Runtime Evidence

`ActivityWorkspaceContractTest.php` proves that:

- activity candidates are explicitly tenant and service scoped;
- every aggregated native Task, Call and Meeting passes record ACL;
- native reminder and scheduling implementations remain authoritative;
- projects use the existing Task permission boundary;
- only ACL-visible native Tasks and Documents can be associated;
- project associations remain tenant and service scoped;
- the native record picker is reused by the responsive collaboration interface.

`live-activity-workspace.spec.js` authenticates to a synthetic tenant and verifies Agenda and Projects, then opens the authoritative native Calendar and Document modules. It confirms the retired Activity Calendar URL redirects to native Calendar. It also creates a temporary project, associates and removes an available native Task, adds an update, reads the project back and archives it. The flow runs at desktop, tablet, tablet-landscape, mobile and mobile-landscape viewports.

## Verification

Run:

```powershell
powershell -ExecutionPolicy Bypass -File scripts/dev/verify.ps1 -Ci
npx playwright test tests/browser/live-activity-workspace.spec.js
```

The sub-gate passes when repository verification, migration replay, activity contracts and all five authenticated responsive workflows pass.
