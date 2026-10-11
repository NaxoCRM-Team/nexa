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
| Programmable marketing sends | Approved reusable sources, validated parameters, scoped integration access and intentional-send idempotency exist; local HTTP/API-key/worker/Postal capture acceptance is recorded below. Public delivery remains separate. |
| Conditional email content | Native/custom scalar properties, exact decimals, fixed/relative dates and timestamps, grouped rules, rich branches, bounded distinct behavior counts and exact-page filters exist. |
| Workflow runtime | Immutable versions, manual enrollment, four automatic event sources, branches, waits, business hours, cancellation and execution history exist. |
| Native workflow actions | Lifecycle, owner, Task, Case, Opportunity, notification, static-list membership and governed email queue creation exist. |
| Postal | Local capture, callbacks and manual uncertain-submission review exist; this is not public delivery acceptance. |

Implementation references: [workflow service](../../espocrm/custom/Espo/Custom/Tools/Automation/WorkflowService.php), [workflow contract](../../espocrm/custom/Espo/Custom/Tools/Automation/WorkflowDefinition.php), [email dispatcher](../../espocrm/custom/Espo/Custom/Tools/MarketingEmail/MarketingDispatchService.php), [native capability audit](../architecture/phase-6-native-email-capability-audit.md).

## Fixed Remaining Checklist

Unchecked means incomplete or unverified, not necessarily absent. Finish and verify whole packages before declaring them delivered.

### A - Complete Automation

- [x] **A01 Visual builder:** connected journey canvas, branching, ordering, validation, publication and accessible desktop/mobile operation. Immutable runtime definitions are preserved; responsive regressions and real authenticated builder-to-worker acceptance passed on 11 October.
- [ ] **A02 Enrollment:** complete record-created/updated/field-changed, segment-enter/leave, activity-completed, stage/lifecycle, score threshold, date, email-open/click and authenticated API/webhook trigger families. Apply tenant/service/ACL filters before enrollment; persist deduplicated receipts and explicit reenrollment policies.
- [ ] **A03 Conditions and timing:** cover associated Company/Opportunity/customer state, segments, campaign behavior, multi-branch decisions and bounded multi-event conditions promised in the ledger. Retain existing date/count/page rules. Verify wait-until-date, condition polling, timezone/business-hour boundaries and changing records.
- [ ] **A04 Actions:** complete governed scoring adapters, configured webhooks, team notifications, tags, approved native record/field/status and associated-record updates, and form/event action connections. Reuse native CRM validation, ownership, SLA and permissions. Define guarded external-CRM/omnichannel adapter contracts; do not advertise unavailable providers as executable.
- [ ] **A05 Execution safety:** implement external-effect leases/attempt history, bounded retries, timeout/uncertain-state reconciliation, cancellation races, plan quotas, concurrency limits and demonstrable tenant fairness. Prove restart/replay cannot duplicate native writes or external effects.
- [ ] **A06 Email bindings:** add validated Contact/custom-property runtime parameter bindings to approved workflow email sources, retaining delivery-time approval, consent, suppression, sender and allowance checks.

The 11 October implementation adds `opportunity.stage_changed`, `activity.completed`, tenant custom-property `date.reached` and administrator-authenticated `api.event` enrollment to the existing events. Record-created/updated events remain Contact-subject events, not a claim that every CRM object can enroll independently. Native Contact field changes remain distinct from tenant custom-property writes. Executable actions also include bounded scoring, tags, approved native Contact/Account/Opportunity updates, team notification and managed native webhook delivery. Publication and execution recheck native ACL, scope and configuration. The progress/evidence section below records acceptance limits; catalogue availability alone is not acceptance.

### E - Complete Email Modes And API

- [ ] **E01 Transactional mode:** establish explicit purpose/legal-basis and suppression rules, approved templates, separate allowance semantics, UI/API and delivery-time enforcement. The existing `transactional` label does not implement this policy; do not bypass marketing consent by relabeling a send.
- [x] **E02 Programmable acceptance:** authenticated local HTTP submissions, validated parameters, distinct intentional-send keys, replay/conflicts, native API-key grants/revocation, scoped worker execution and actual Postal-to-Mailpit capture are verified. This is local acceptance, not public delivery proof.
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
| A01 | Shared journey canvas, pointer/keyboard connections, cycle protection, persisted draft positions and non-overlapping automatic placement. | Local editor acceptance passed, including authenticated desktop/mobile browser-to-worker execution. |
| A02 | Contact, membership, delivery, activity completion, Opportunity stage, custom-date and authenticated API sources with scoped deduplicated receipts. | Authenticated HTTP intake/replay and cross-tenant route rejection passed; complete source-specific ACL matrix remains a wider gate. Tenant custom-property change events are not advertised. |
| A03 | Native Company/Opportunity state, list membership, grouped conditions, bounded chronological source-backed event sequences and optional native campaign filters for email opens/clicks; existing waits and business hours retained. | Complete changing-record/source-permission matrix; native campaign matching and nonmatching fixtures passed. |
| A04 | Native adapters for scoring, tags, approved record properties, team notifications and native Webhook/queue records. | Complete adapter matrix and external transport acceptance. No unavailable CRM/channel provider is advertised. |
| A05 | Native plan entitlement, tenant-wide workflow/enrollment/daily-step capacity, oldest-first workflow rotation, per-workflow bounded batches, transactional native retries and durable external leases/attempts. | Simultaneous workers, replay/restart, quota independence, concurrent capacity and cancellation/finalization races passed. Approved external transport remains unverified. |
| A06 | Approved email sources with native/custom Contact parameters, missing-value fallbacks, native escaping and governed dispatcher. | Authenticated browser/API-to-delivery acceptance with the complete package. |

Migrations `0072` (execution capacity/attempts) and `0073` (managed webhook effects/attempts) were applied to WAMP through the checksum-tracked local runner. No existing migration checksum was changed. Native metadata was rebuilt, caches cleared and frontend timestamp refreshed. No configuration secrets were edited or published.

Final evidence on 11 October, against base revision `58e2f09a` with an uncommitted working tree: `powershell -ExecutionPolicy Bypass -File scripts/dev/verify.ps1` passed. It included 156 graph-contract checks, 27 email-binding checks and 331 rollback-only runtime checks. `npx playwright test tests/browser/automation-workspace.spec.js --workers=1` passed 10 responsive/accessibility cases across five viewports. Runtime evidence includes native API action parsing/replay/scope rejection, reached dates, activity completion, Opportunity stage changes, ordered delivery-event sequences, campaign matching/nonmatching, associated Company state/update, native tags/team notifications, lease ownership, stale tokens, safe pre-submission retry, uncertain-state review, cancellation, cross-tenant rejection, blocked network ranges, native hook rollback/retry, daily capacity and workflow fairness. The expanded fixture initially used an outdated ORM relationship method; after correction to the native relation API, both the focused rerun and final combined run passed. Browser tests use API doubles; desktop/mobile screenshots were inspected and an automatic-position overlap was corrected. Read-only WAMP HTTP checks returned login 200 and unauthenticated workflow API 401. These are not authenticated live browser-to-worker or simultaneous-worker acceptance. Public webhook delivery requires an explicitly approved endpoint; none has been provided. No acceptance checkbox or delivery issue was closed.

Webhook HTTP occurs only after the worker commits a lease. HTTPS/443, public direct addresses, DNS pinning, TLS verification, no redirect/proxy forwarding, bounded time/body and native signing secrets are enforced. One stable effect ID is the receiver's idempotency key. Only positively pre-submission failures retry; timeout, ambiguous server responses and expired leases hold for evidence review. Administrative review records an evidence hash and outcome and never resends. Cancellation stops pending effects; it cannot recall an already submitted request. Native CRM writes and advancement share a transaction/savepoint, with attempts outside the step savepoint. Validation/permission failures are terminal. These guarantees still require the simultaneous-worker/live transport acceptance above.

### 11 October Live Automation Acceptance

The local acceptance harness is now implemented: [runbook](automation-acceptance.md), [runner](../../scripts/dev/verify-automation-acceptance.ps1), [real-browser test](../../tests/browser/live-automation-acceptance.spec.js) and [CLI fixture](../../tests/tenant/AutomationAcceptanceFixture.php). It logs in with random temporary tenant administrators, creates/publishes through the real rendered builder and native HTTP API, and advances persisted runs through independent native system-worker processes. No API doubles or production security bypasses are used in this test.

The final desktop/mobile run passed both local scenarios (2 passed, 2 public-delivery scenarios explicitly skipped). It covers the browser/API/worker path, intentional-event idempotency, rejected client scope overrides, cross-tenant denial across workflow endpoints, protected-property rejection, native property updates, competing workers, six native-action cancellation races, revoked/deactivated execution identities, deleted and service-moved Contacts, competing webhook leases, claim/cancellation and completion/cancellation races, stale lease recovery and tenant concurrency capacity. Tenant A's worker also completes while tenant B deliberately holds a quota transaction. Synthetic lease finalization proves orchestration, not external HTTP delivery.

The real application exposed defects that doubled-browser and rollback-only fixtures did not catch. The editor's `options()` helper collided with native `view.options` and prevented creation; it is now `optionMarkup()`, and the regression fixture includes native options. Workflow-owned outer transactions now use the native ORM transaction manager so Record-service updates do not attempt a second PDO transaction; native savepoints still make writes and advancement atomic. New-workflow creation is disabled while its catalogue loads. Quota catalogue reads now hold compatible shared locks on common plan metadata, while tenant capacity remains exclusively serialized. Workflow mutations and webhook finalization consistently take tenant locks before workflow/run/effect locks, fixing a concurrent create/publication deadlock. All 10 responsive editor regressions passed. An overlapping repository/acceptance run also timed out during fixture cleanup; database suites are now run sequentially, and cleanup skips tables without fixture-owned rows. The delivery ledger records final regression evidence.

Public webhook acceptance remains **externally blocked**: no approved HTTPS receiver has been supplied. The separate real-transport test is implemented and explicitly skipped without that input. No request was sent to the placeholder destination. No new migration is required, no configuration/password was changed, and no issue or phase was closed. Work is based on committed revision `bb356387` with local acceptance changes still uncommitted. Fixture cleanup preserves immutable native audit entries in suspended fixture tenants while removing their working records, credentials and entitlements.

This entry supersedes the previous "no authenticated browser-to-worker or simultaneous-worker evidence" statements for these tested local paths. It does not establish approved-email end-to-end delivery, the entire source/adapter permission matrix, installation acceptance, transactional email, email experiments or public Postal. A01's local editor/runtime proof is available; A04/A05 external delivery and the wider A/E/X/V/P closure requirements remain governed by the fixed checklist.

### 11 October Catalogue Authorization Acceptance

The subsequent [catalogue permission matrix](automation-acceptance.md#catalogue-permission-matrix) covers every advertised trigger and action: 18 of each. Native management operations reject ordinary staff, foreign tenant administrators and mismatched service contexts, using exact authorization/not-found assertions rather than accepting validation failures. Inactive or downgraded execution administrators cannot publish, enroll or submit API events.

The expanded rollback-only workflow suite passed 1,762 checks. All 16 automatic trigger families record `access_denied` and consume no source receipts after execution-administrator revocation. Seeded action boundaries from real immutable publications verify all 17 non-email adapters fail before execution, with unchanged native record snapshots and no adapter attempts or webhook effects. The approved reusable-email fixture separately passed actual enrollment followed by revocation, with no queue item or governed send key. These probes do not claim browser enrollment for every combination; the existing live desktop/mobile acceptance remains separate evidence.

Catalogue-wide management and execution-identity permission acceptance is now available. Public webhook transport and receiver signature verification remain blocked on an owner-approved HTTPS receiver. No transport security restriction was bypassed to obtain a local pass. Other Phase 6 email, installation and public Postal gates are not closed by these checks.

### 11 October Programmable Email Acceptance

Following automation commit `d28cdc2b`, E02 now has committed-fixture acceptance over the actual WAMP HTTP API and native worker rather than service calls inside a rollback transaction. All four final desktop/mobile scenarios passed using the runner's `-Suite Programmable -LocalPostalCapture` options. The two no-network scenarios verify authenticated administrator submissions, validation, immutable resolved native content, concurrent-key/replay/conflict semantics, separate intentional sends, tenant isolation and worker consent withdrawal. The two positive-capture scenarios verify native read-only API-key authentication, HTTP grants/revocation, edit denial, scoped workers and actual Postal-to-Mailpit delivery with sender, recipient, personalization, unsubscribe, text alternative and queue-item MIME attribution. Worker replay creates no duplicate queue submission or SMTP capture.

Credentials are read only from the existing ignored local Postal configuration and held in process memory. Synthetic domain-readiness records belong only to disposable fixture tenants; they do not establish public DNS verification. Local TLS verification remains enabled using the existing local CA only in the capture-worker process. Native security audit evidence is preserved by fixture cleanup. E01, E03, X, V and P remain open; E02's local proof is not public Postal or transactional-email acceptance. The delivery ledger records the final combined regression result and commit state.

### Prior 10 October Progress (Historical)

The automation package is still incomplete. The connected canvas renders labeled branches and supports mouse/touch positioning and keyboard arrows. Node positions now persist in the scoped draft JSON and do not affect the execution fingerprint. Direct output-port connections support pointer and keyboard selection, Escape cancellation and client-side cycle prevention; the authoritative backend graph validator remains unchanged. Full real-application acceptance remains open under A01.

A02 now reads native Contact outbox events, native segment membership history and source-backed marketing delivery projections. Field-change enrollment is limited to authorized native Contact fields; custom-field writes are not advertised as native outbox triggers. Score enrollment requires a recorded below-to-threshold crossing, not merely a Contact whose current score exceeds the threshold. New native customer and dynamic segment events explicitly use UTC; existing historical timestamps are not rewritten. Opportunity-stage, activity-completed, scheduled date and authenticated API/webhook enrollment remain pending.

A03 now includes durable explicit-offset absolute-date waits with UTC due times and cancellation. Associated-record conditions and the remaining checklist requirements are still open. No new external action, commercial quota or fairness claim is made by these changes.

A06 now has execution-time native/custom Contact-property bindings, optional missing-value fallbacks, publication-time catalogue checks and native approved-template validation/escaping. Disabled or unavailable catalogue properties fail closed; parameter values are not recorded in workflow audit payloads. Rollback-only native-worker tests verify current-value rendering and replay prevention. These tests do not replace live HTTP-to-worker/delivery acceptance.

Focused checks on 10 October 2026 passed: 27 binding contract checks, 149 graph contract checks, 266 rollback-only workflow runtime checks and 10 responsive/accessibility editor browser cases across five viewports. The runtime suite exercises real ORM writes, native segment operations, shared layouts, absolute waits, crossing semantics and delivery-evidence-backed email enrollment. Email source fixtures are database projections, not live Postal callback acceptance. Browser tests use API doubles and do not establish authenticated live acceptance. WAMP rebuild, cache clearing and asset timestamp refresh completed. No full repository or package acceptance run has been recorded for the latest changes. No additional migration was required beyond the existing `0071` event-enrollment migration. A01-A05 remain open; no webhook execution was enabled merely because native queue/sender classes exist.

Local acceptance requires A, E, X and V evidence. Public acceptance additionally requires P. Phase 6 and its delivery issues remain open until their required gates pass; externally blocked public acceptance must be reported separately, not marked complete. No issue/board closure, commit, push, schema change or new acceptance run was performed by this documentation audit.
