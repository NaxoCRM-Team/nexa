# Delivery Management

## Planning Workspace

[Nexa Product Delivery](https://github.com/orgs/NaxoCRM-Team/projects/1) is the internal planning workspace for product and engineering delivery. GitHub Issues are the canonical backlog; the roadmap documents define strategy and dependency order but do not replace issue tracking.

The Project is private to the organization. The public repository does not make internal planning data public.

## Planning Hierarchy

```text
Release
  -> Phase milestone
    -> Module
      -> Requirement range (F, domain and NFR IDs)
        -> Issue or vertical-slice story
          -> Sub-issue or technical task
            -> Pull request
```

- Releases group customer-facing outcomes from R0 through R6.
- Phase milestones are dependency and exit gates from Phase 0 through Phase 12.
- Modules M01 through M23 define architecture and ownership boundaries.
- Advanced `F-*`, detailed domain and `NFR-*` IDs provide end-to-end traceability without turning every bullet into a separate issue.
- Sprint iterations are two-week delivery commitments.
- Pull requests close scoped issues after review and required CI.

## Project Fields

| Field | Purpose |
|---|---|
| Status | Backlog, Ready, In Progress, In Review, Blocked or Done |
| Milestone | Phase gate maintained by the repository |
| Requirement IDs | Related advanced `F-*`, detailed domain and `NFR-*` identifiers |
| Data impact | Entities, migrations, events, retention and rollback expectations |
| Sprint | Two-week iteration beginning with Sprint 01 on 20 July 2026 |
| Module | Owning module from M01 through M23 |
| Priority | P0 Critical, P1 High, P2 Normal or P3 Low |
| Workstream | Platform, Product or Shared |
| Size | XS, S, M or L; split L items before sprint commitment |
| Release | R0 Engineering Baseline through R6 SaaS General Availability |
| Start date | Planned start for roadmap display |
| Target date | Planned completion for roadmap display |

## Required Views

Maintain these Project views:

1. Current Sprint: board layout, filter to the current Sprint and group by Status.
2. Product Backlog: table layout, exclude Done and group by Milestone then Module.
3. Roadmap: roadmap layout using Start date and Target date, grouped by Release.
4. Platform Work: table or board filtered to Workstream = Platform or Shared.
5. Product Work: table or board filtered to Workstream = Product or Shared.
6. My Work: table filtered to the current viewer and excluding Done.

GitHub currently requires view layout and saved-view configuration through the Project interface. Project fields, iterations and backlog data are managed through the organization Project.

## Labels

Labels describe repository-level classification that is useful outside the Project:

- `type:*`: bug, feature, technical, NFR or documentation.
- `area:*`: frontend, backend, database, security, infrastructure, integration, automation or analytics.
- `status:blocked`: an external dependency prevents progress.
- `status:needs-decision`: an architecture or product decision is required.
- `status:ready`: acceptance criteria and dependencies are complete and the issue may be picked up.
- `sprint:*`, `module:*`, `priority:*`, `size:*` and `release:*` mirror delivery fields when an issue must remain understandable outside the private Project.
- `duplicate`, `invalid`, `question` and `wontfix`: issue-triage outcomes.

Priority and Workstream are Project fields, not labels. Phase is a milestone, and Sprint is an iteration.

## Sprint Cadence

Sprints run for two weeks. During planning:

1. Confirm the sprint goal and available capacity.
2. Select only Ready issues from the active phase.
3. Set module, priority, workstream, size, release and sprint. Ready issues remain unassigned.
4. Split any item that cannot satisfy the Definition of Done inside the sprint.
5. Limit each developer to one major In Progress issue at a time.

During delivery, a developer assigns an issue to themselves when moving it from Ready to In Progress, then moves it to In Review when its pull request opens. Move it to Done only after required checks, review, documentation and acceptance evidence are complete.

At sprint close, review completed outcomes, return unfinished work to Ready or deliberately schedule it, and record process improvements. Do not silently carry incomplete work into the next sprint.

## Active Delivery Plan

The Project is the live source for item status and assignment. This section records the agreed near-term sequence so repository documentation, milestones and Project iterations remain aligned.

| Sprint | Dates | Goal | Committed scope |
|---|---|---|---|
| Specification alignment gate | Before Phase 3 backlog refinement | Align the advanced catalogue, detailed product specification, modules, data contracts and acceptance model | Unified specification, traceability matrix, roadmap and Customer 360 schema-design backlog |
| Foundation closeout | Through 19 July 2026 | Complete the engineering baseline, module conventions, design tokens and initial responsive shell | #11, #13-#16 and #25-#29 |
| Sprint 04 | 31 August-13 September 2026 | Complete the shared Phase 1 product experience | #43 authentication and signup, #44 navigation and search, #45 tenant home and dashboards, #46 common CRM list and record workflows |
| Sprint 05 | 14-27 September 2026 | Establish the Phase 2 tenant and entitlement control plane | #35 tenant lifecycle and provisioning, #36 plans/entitlements/quotas, #37 tenant-admin and platform-operator boundary |
| Sprint 06 | 28 September-11 October 2026 | Deliver identity, authorization, metering and billing contracts | #39 permissions/teams/field restrictions, #40 SSO/MFA/social identity contracts, #47 usage ledger, #48 billing boundary |
| Sprint 07 | 12-25 October 2026 | Complete the security and tenant-isolation release gate | #49 secrets/encryption, #50 audit and impersonation, #51 retention/export/deletion, #38 Phase 2 acceptance suite |

Issues #15, #34 and #41 are delivery umbrellas and are not sprint commitments. Every committed issue above remains unassigned while it is Ready. A developer assigns an issue to themselves only when they start it.

Phase 1 owns the responsive authentication experience, including login, signup, email-code verification, forgotten-password and password-reset screens. Phase 2 issue #40 owns the secure SSO, MFA and social identity-provider contracts behind those screens. Social sign-in and signup controls are shown only when a provider is configured.

Before Phase 3 work enters Ready, its vertical slices must reference the [Unified Product Specification](../product/unified-product-specification.md), [Requirements Traceability Matrix](../product/requirements-traceability.md) and approved Customer 360 data contracts. Phase 5 event foundations must complete before Phase 6 tracked email and automation acceptance.

## Issue Readiness

An issue is Ready only when it has:

- a clear objective, stable advanced/core/NFR requirement IDs and testable acceptance criteria;
- a phase milestone, module, priority, workstream, size and release;
- identified phase dependencies plus tenant, service, permission, consent and security implications;
- entity, migration, event, API, retention, rollback, responsive UI and documentation expectations where applicable;
- no assignee while it waits in Ready; the developer who starts it assigns themselves.

Every implementation branch references its issue, and every pull request uses `Closes #<issue>` when the change completes that issue.
