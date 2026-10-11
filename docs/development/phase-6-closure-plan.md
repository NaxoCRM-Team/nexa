# Phase 6 Closure Plan

Audit: 10 October 2026. Phase 6 is open. This is the canonical remaining-work checklist for M10, M12 and the email part of M23. The [delivery ledger](phase-6-delivery-status.md) retains historical implementation and test evidence; it is not a completion certificate.

This audit inspected repository code and documentation. It did not run new acceptance tests. Existing focused passes and an earlier complete verification pass do not establish acceptance of the latest working tree.

## Delivered Baseline

Preserve these components and extend them; do not rebuild them or replace the redesigned CRM screens.

| Component | Current boundary |
| --- | --- |
| Native email models | MassEmail, EmailTemplate and EmailQueueItem remain authoritative. |
| Email authoring and governance | Designer, HTML/templates, approvals, scheduling, published images/attachments, sender checks, suppression and purpose-specific marketing consent exist. |
| Marketing allowance | The effective monthly allowance is 20 times the marketing-contact tier; provider restrictions remain additional limits. |
| Programmable marketing sends | Approved reusable sources, validated parameters, scoped integration access and intentional-send idempotency exist; live HTTP-to-worker acceptance remains open. |
| Conditional email content | Native/custom scalar properties, exact decimals, fixed/relative dates and timestamps, grouped rules, rich branches, bounded distinct behavior counts and exact-page filters exist. |
| Workflow runtime | Immutable versions, manual enrollment, four automatic event sources, branches, waits, business hours, cancellation and execution history exist. |
| Native workflow actions | Lifecycle, owner, Task, Case, Opportunity, notification, static-list membership and governed email queue creation exist. |
| Postal | Local capture, callbacks and manual uncertain-submission review exist; this is not public delivery acceptance. |

Implementation references: [workflow service](../../espocrm/custom/Espo/Custom/Tools/Automation/WorkflowService.php), [workflow contract](../../espocrm/custom/Espo/Custom/Tools/Automation/WorkflowDefinition.php), [email dispatcher](../../espocrm/custom/Espo/Custom/Tools/MarketingEmail/MarketingDispatchService.php), [native capability audit](../architecture/phase-6-native-email-capability-audit.md).

## Fixed Remaining Checklist

Unchecked means incomplete or unverified, not necessarily absent. Finish and verify whole packages before declaring them delivered.

### A - Complete Automation

- [ ] **A01 Visual builder:** turn the existing step editor into the journey canvas, with connections, branching, ordering, validation, publication and accessible desktop/mobile operation. Preserve immutable runtime definitions.
- [ ] **A02 Enrollment:** complete record-created/updated/field-changed, segment-enter/leave, activity-completed, stage/lifecycle, score threshold, date, email-open/click and authenticated API/webhook trigger families. Apply tenant/service/ACL filters before enrollment; persist deduplicated receipts and explicit reenrollment policies.
- [ ] **A03 Conditions and timing:** cover associated Company/Opportunity/customer state, segments, campaign behavior, multi-branch decisions and bounded multi-event conditions promised in the ledger. Retain existing date/count/page rules. Verify wait-until-date, condition polling, timezone/business-hour boundaries and changing records.
- [ ] **A04 Actions:** complete governed scoring adapters, configured webhooks, team notifications, tags, approved native record/field/status and associated-record updates, and form/event action connections. Reuse native CRM validation, ownership, SLA and permissions. Define guarded external-CRM/omnichannel adapter contracts; do not advertise unavailable providers as executable.
- [ ] **A05 Execution safety:** implement external-effect leases/attempt history, bounded retries, timeout/uncertain-state reconciliation, cancellation races, plan quotas, concurrency limits and demonstrable tenant fairness. Prove restart/replay cannot duplicate native writes or external effects.
- [ ] **A06 Email bindings:** add validated Contact/custom-property runtime parameter bindings to approved workflow email sources, retaining delivery-time approval, consent, suppression, sender and allowance checks.

The 11 October implementation adds `opportunity.stage_changed`, `activity.completed`, tenant custom-property `date.reached` and administrator-authenticated `api.event` enrollment to the existing events. Record-created/updated events remain Contact-subject events, not a claim that every CRM object can enroll independently. Native Contact field changes remain distinct from tenant custom-property writes. Executable actions also include bounded scoring, tags, approved native Contact/Account/Opportunity updates, team notification and managed native webhook delivery. Publication and execution recheck native ACL, scope and configuration. The progress/evidence section below records acceptance limits; catalogue availability alone is not acceptance.

### E - Complete Email Modes And API

- [ ] **E01 Transactional mode:** establish explicit purpose/legal-basis and suppression rules, approved templates, separate allowance semantics, UI/API and delivery-time enforcement. The existing `transactional` label does not implement this policy; do not bypass marketing consent by relabeling a send.
- [ ] **E02 Programmable acceptance:** verify authenticated live HTTP submissions, validated parameters, distinct keys for intentional sends, replay/conflict behavior, queued dispatch, authorization revocation and scoped worker execution.
- [ ] **E03 Uncertain submissions:** automate provider evidence inspection where supported, retain safe manual review when evidence is unavailable, and prove no blind retry or premature allowance release after an uncertain submission.

### X - Complete Email Experiments

- [ ] **X01 Email A/B:** persist immutable approved variants, deterministic audience assignment, test sizes, measurement windows, reconciled results and explicit winner selection. Recheck consent/allowances for every send, prevent duplicate exposure, and account for unreliable open signals. Connect workflow A/B routing to persisted assignments rather than rerandomizing on replay.

### V - Combined Local Acceptance

- [ ] **V01 Installation:** verify clean installation and incremental migration to the final Phase 6 schema. Add migrations only for required persisted contracts, not duplicate native tables. Do not claim rollback fixtures prove installation.
- [ ] **V02 End-to-end:** authenticated browser/API to actual worker: enrollment, approved send, canonical callback event, deterministic branch, native CRM action and cancellation. Exercise marketing, transactional, programmable and A/B paths together.
- [ ] **V03 Security and recovery:** test multiple tenants and services without hardcoded tenant IDs; record/field ACL; revoked consent and approval; tampered content; quotas/concurrent workers; invalid/duplicate callbacks; failed jobs; restart/replay and fair scheduling. Use real HTTP scenarios alongside existing focused fixtures.
- [ ] **V04 Evidence:** run the combined repository suite on the final tree; record revision/worktree state, schema, commands, results and sanitized artifacts. Include real responsive/accessibility checks. Resolve failures before claiming local acceptance.

### P - Public Postal Acceptance

- [ ] **P01 External prerequisites:** provision a public sending domain, verified sender/DNS/TLS, publicly reachable signed callbacks and an external test inbox. These require deployment/account inputs; local Docker capture cannot substitute for them.
- [ ] **P02 Public proof:** deliver to external inboxes, reconcile delivery/bounce/complaint/unsubscribe outcomes and test uncertain-submission recovery without duplicates. Record sanitized evidence, never credentials.

## Scope And Delivery Order

Source scope: [roadmap](../product/module-build-roadmap.md), specification sections 18-20 and 25 in the [unified specification](../product/unified-product-specification.md), and the [traceability matrix](../product/requirements-traceability.md).

Deliver **A as a complete package**, then **E**, then **X** once sending/event reconciliation is proven, then **V**. Provision **P** alongside local work when external inputs become available. Report completed checklist IDs, failed acceptance and genuine blockers, not a succession of isolated feature counts.

Full scoring models/ABM remain Phase 7; the Phase 6 scoring action/event boundary is still required. Full SMS/WhatsApp/social provider implementations belong to their channel modules; required automation contracts and safety checks are not waived. Journey analytics remain Phase 9; bounded workflow journey conditions stay in A03. Non-email experiments retain their later module scope. Existing personalization increments are baseline, not invitations to add more unrelated conditions.

Do not silently remove required items or expand this checklist. Any newly discovered requirement must be mapped to an existing ID or explicitly agreed as a scope change with its roadmap owner. This plan does not claim those later phases are complete.

## Closure Rule

### 11 October Implementation And Acceptance

The package remains open pending complete acceptance. The following changes are implemented, not a production-readiness certificate:

| ID | Implemented boundary | Remaining proof |
| --- | --- | --- |
| A01 | Shared journey canvas, pointer/keyboard connections, cycle protection, persisted draft positions and non-overlapping automatic placement. | Authenticated real-application browser-to-worker scenario. |
| A02 | Contact, membership, delivery, activity completion, Opportunity stage, custom-date and authenticated API sources with scoped deduplicated receipts. | Live HTTP intake and full source-specific ACL matrix; tenant custom-property change events are not advertised. |
| A03 | Native Company/Opportunity state, list membership, grouped conditions, bounded chronological source-backed event sequences and optional native campaign filters for email opens/clicks; existing waits and business hours retained. | Complete changing-record/source-permission matrix; native campaign matching and nonmatching fixtures passed. |
| A04 | Native adapters for scoring, tags, approved record properties, team notifications and native Webhook/queue records. | Complete adapter matrix and external transport acceptance. No unavailable CRM/channel provider is advertised. |
| A05 | Native plan entitlement, tenant-wide workflow/enrollment/daily-step capacity, oldest-first workflow rotation, per-workflow bounded batches, transactional native retries and durable external leases/attempts. | Full simultaneous-worker/restart acceptance, not merely sequential lease assertions. |
| A06 | Approved email sources with native/custom Contact parameters, missing-value fallbacks, native escaping and governed dispatcher. | Authenticated browser/API-to-delivery acceptance with the complete package. |

Migrations `0072` (execution capacity/attempts) and `0073` (managed webhook effects/attempts) were applied to WAMP through the checksum-tracked local runner. No existing migration checksum was changed. Native metadata was rebuilt, caches cleared and frontend timestamp refreshed. No configuration secrets were edited or published.

Final evidence on 11 October, against base revision `58e2f09a` with an uncommitted working tree: `powershell -ExecutionPolicy Bypass -File scripts/dev/verify.ps1` passed. It included 156 graph-contract checks, 27 email-binding checks and 331 rollback-only runtime checks. `npx playwright test tests/browser/automation-workspace.spec.js --workers=1` passed 10 responsive/accessibility cases across five viewports. Runtime evidence includes native API action parsing/replay/scope rejection, reached dates, activity completion, Opportunity stage changes, ordered delivery-event sequences, campaign matching/nonmatching, associated Company state/update, native tags/team notifications, lease ownership, stale tokens, safe pre-submission retry, uncertain-state review, cancellation, cross-tenant rejection, blocked network ranges, native hook rollback/retry, daily capacity and workflow fairness. The expanded fixture initially used an outdated ORM relationship method; after correction to the native relation API, both the focused rerun and final combined run passed. Browser tests use API doubles; desktop/mobile screenshots were inspected and an automatic-position overlap was corrected. Read-only WAMP HTTP checks returned login 200 and unauthenticated workflow API 401. These are not authenticated live browser-to-worker or simultaneous-worker acceptance. Public webhook delivery requires an explicitly approved endpoint; none has been provided. No acceptance checkbox or delivery issue was closed.

Webhook HTTP occurs only after the worker commits a lease. HTTPS/443, public direct addresses, DNS pinning, TLS verification, no redirect/proxy forwarding, bounded time/body and native signing secrets are enforced. One stable effect ID is the receiver's idempotency key. Only positively pre-submission failures retry; timeout, ambiguous server responses and expired leases hold for evidence review. Administrative review records an evidence hash and outcome and never resends. Cancellation stops pending effects; it cannot recall an already submitted request. Native CRM writes and advancement share a transaction/savepoint, with attempts outside the step savepoint. Validation/permission failures are terminal. These guarantees still require the simultaneous-worker/live transport acceptance above.

### Prior 10 October Progress (Historical)

The automation package is still incomplete. The connected canvas renders labeled branches and supports mouse/touch positioning and keyboard arrows. Node positions now persist in the scoped draft JSON and do not affect the execution fingerprint. Direct output-port connections support pointer and keyboard selection, Escape cancellation and client-side cycle prevention; the authoritative backend graph validator remains unchanged. Full real-application acceptance remains open under A01.

A02 now reads native Contact outbox events, native segment membership history and source-backed marketing delivery projections. Field-change enrollment is limited to authorized native Contact fields; custom-field writes are not advertised as native outbox triggers. Score enrollment requires a recorded below-to-threshold crossing, not merely a Contact whose current score exceeds the threshold. New native customer and dynamic segment events explicitly use UTC; existing historical timestamps are not rewritten. Opportunity-stage, activity-completed, scheduled date and authenticated API/webhook enrollment remain pending.

A03 now includes durable explicit-offset absolute-date waits with UTC due times and cancellation. Associated-record conditions and the remaining checklist requirements are still open. No new external action, commercial quota or fairness claim is made by these changes.

A06 now has execution-time native/custom Contact-property bindings, optional missing-value fallbacks, publication-time catalogue checks and native approved-template validation/escaping. Disabled or unavailable catalogue properties fail closed; parameter values are not recorded in workflow audit payloads. Rollback-only native-worker tests verify current-value rendering and replay prevention. These tests do not replace live HTTP-to-worker/delivery acceptance.

Focused checks on 10 October 2026 passed: 27 binding contract checks, 149 graph contract checks, 266 rollback-only workflow runtime checks and 10 responsive/accessibility editor browser cases across five viewports. The runtime suite exercises real ORM writes, native segment operations, shared layouts, absolute waits, crossing semantics and delivery-evidence-backed email enrollment. Email source fixtures are database projections, not live Postal callback acceptance. Browser tests use API doubles and do not establish authenticated live acceptance. WAMP rebuild, cache clearing and asset timestamp refresh completed. No full repository or package acceptance run has been recorded for the latest changes. No additional migration was required beyond the existing `0071` event-enrollment migration. A01-A05 remain open; no webhook execution was enabled merely because native queue/sender classes exist.

Local acceptance requires A, E, X and V evidence. Public acceptance additionally requires P. Phase 6 and its delivery issues remain open until their required gates pass; externally blocked public acceptance must be reported separately, not marked complete. No issue/board closure, commit, push, schema change or new acceptance run was performed by this documentation audit.
