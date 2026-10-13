# Email Experiment Acceptance

## Native Capability Boundary

Native MassEmail, EmailTemplate, EmailQueueItem, campaign enrollment, approved content versions, consent, suppression, allowance and Postal reconciliation remain authoritative. Native EspoCRM does not provide this tenant-scoped persisted test/holdout/winner orchestration in the installed code. Migration `0077` adds experiment governance around those existing components; it does not add another transport or replace the email designer.

Open Marketing Email and select **Experiments**, or open `NexaEmailExperiments` in the current workspace. An active tenant administrator with native MassEmail edit and Contact read access is required. Both variants must be distinct, current approved marketing emails using the same active campaign and consent purpose. Sources with unresolved programmable parameters are rejected; native Contact personalization remains supported.

The catalogue reuses the shared draggable/resizable table, live search and internal scrolling. Additional catalogue rows load through bounded scoped requests without pagination controls. Worker selection rotates the oldest updated experiments so one large audience cannot indefinitely occupy every slot.

## Delivery Contract

1. Create the experiment with test and holdout percentages and a one-minute to seven-day decision window. Creation alone sends nothing.
2. Start snapshots the eligible campaign audience and both approved variants. Invalid or duplicate normalized recipient emails are excluded. Stable keyed ranking assigns exact test and holdout sizes; the test alternates A/B, with at most one-recipient imbalance. Small audiences retain at least one recipient per variant.
3. The existing scoped background job queues at most ten recipients per experiment per pass, with at most five experiments per workspace per pass. Every queue is created by the execution administrator's user-bound native dispatcher. Native consent, suppression, approved content, verified sender, allowance and per-source request throttling are preserved.
4. Queue creation and assignment updates share the native ORM transaction. Intentional keys are `experiment:<experiment-id>:<contact-id>`; retry/restart cannot create another exposure. Editing the approved variant invalidates its snapshot rather than silently sending different content.
5. The decision window starts only after the complete test cohort has been queued or suppressed. A tenant administrator explicitly chooses the winner and records a reason after that window. No automatic winner is selected from privacy-proxy opens. Results count distinct clicking Contacts from native reconciled provider events inside the test window; click rate uses queued test recipients as its denominator. A queued message is not a claim of delivery.
6. Only the unexposed rollout cohort receives the selected winner. Holdouts never receive either variant from the experiment. Normal scheduling and arbitrary programmable sends cannot broadcast an already-started variant version outside its persisted experiment assignments. A fresh reviewed version is required for another intentional campaign exposure.
7. Changed addresses or newly ineligible Contacts are suppressed before queuing. Delivery-time checks also reject an address changed after queuing. Pausing is reversible and holds Pending submissions. Cancelling stops pending assignments and native queues that have not entered Sending; submitted messages cannot be recalled. Unknown submissions retain their governed allowance reservations.
8. Execution-administrator deactivation/revocation pauses orchestration before native writes. Tenant, service, native record and field permissions remain checked. API callers cannot inject scope identifiers or arbitrary runtime parameters into an assignment key.

## Workflow A/B Routing

Migration `0078` persists `ab_split` decisions in the existing workflow runtime. The visual builder offers an A/B split with an integer A weight from 1 to 99 percent and two distinct outgoing branches. Immutable publication, tenant, service, node and Contact identities determine a stable assignment; reenrollment into the same publication keeps a Contact in the same variant. Routing is persisted before advancing the native workflow run, and its event payload records the branch, weight and assignment hash. Native execution-user and Contact ACL checks happen before routing. This is weighted deterministic routing, not a claim that a small audience must have exact percentages.

## Verification

- `tests/tenant/TenantEmailExperimentPolicyTest.php`: stateless exact audience sizes, normalization/deduplication, replay, independent seeds, weighted routing and graph validation.
- `tests/tenant/TenantEmailExperimentTest.php`: rollback-only real approved variants, campaign audience, native queue creation, explicit winner rollout, permanent holdout, replay, source exclusion, role/tenant denial, revoked execution identity, reversible pause and cancellation, and post-queue recipient reservation checks. No provider request is sent.
- `tests/tenant/TenantWorkflowAbRoutingTest.php`: rollback-only actual publication/enrollment/native worker routing, persisted evidence, replay/reenrollment and revoked/foreign-tenant checks.
- `tests/browser/email-experiments.spec.js`: desktop/mobile search, forms, native approved version submission, inline failures, responsive framing, manual winner controls and escaping. These tests use API doubles and are not authenticated delivery evidence.

Run database suites sequentially; do not overlap rollback fixtures with committed live acceptance or installation tests. Public DNS, externally reachable Postal callbacks and real external-inbox delivery remain separate production acceptance dependencies. Never use synthetic fixture-domain verification as evidence of public readiness.

## Final Local Results - 12 October 2026

The combined repository verification passed, including 2,018 stateless experiment/routing checks, 33 native experiment checks, nine persisted workflow A/B checks and the 1,762-check workflow runtime/authorization matrix. The final experiment/split UI suite passed 20 cases across five viewports, including WCAG scans of catalogue, creation and results. The mobile results scroll region is keyboard-focusable; desktop/mobile screenshots were inspected.

Authenticated live acceptance passed both desktop and mobile (54.3 seconds), including the actual tenant route, eight natively eligible Contacts, approved variants, four balanced test sends, two untouched holdouts and two winner sends. Exact native queue MIME attribution and recipient capture matched; worker replay produced no seventh send. This is real local Postal-to-Mailpit evidence, not public delivery. Details and the explicit runner are in phase-6-live-closure-acceptance.md. No commit or push has been made.
