# Workflow Editor

The journey canvas shows action/wait/finish nodes and labeled yes/no connections. Nodes support pointer dragging and keyboard-arrow positioning; activating a node opens its step controls. Output ports connect directly to a target node through pointer or keyboard activation, with Escape cancellation and cycle prevention. Positions persist in the scoped workflow draft and are shared between authorized staff without affecting execution fingerprints. New nodes avoid saved node positions. The [fixed closure plan](phase-6-closure-plan.md) distinguishes implementation from remaining real-application acceptance.

Approved email parameters can use **Fixed text** or an available native/custom Contact property through the parameter's **Value source** control. Property values are read when the action executes; an optional fallback applies only when the accessible property has no value. Removed/disabled/inaccessible properties fail closed instead of using the fallback. Existing approved-template limits, plain-text validation, native escaping, consent, suppression and dispatcher allowance checks remain authoritative. Sending history contains queue/source identities, not parameter values.

The step editor exposes enabled tenant custom scalar properties, primary Company and associated Opportunity state, list membership, recorded activity windows and groups of up to six rules. Groups can match all, any, or a chronological recorded-event sequence. Sequences require two to six supported behavior events in the same 1-365-day window and source-backed strictly increasing timestamps; equal timestamps do not prove order. Native campaign filters are available for email opens/clicks. Stored numeric thresholds remain exact text, not floating-point values.

New native actions include bounded score adjustment, tag changes, approved Contact/Company/Opportunity properties and scoped team notifications. Native record pickers select existing Team, Opportunity and Webhook records. Retrying native actions uses bounded attempts and exponential delays after rolling back failed record writes. Webhook delivery history exposes uncertain outcomes for evidence-backed administrative review; choosing a review outcome never resends a request. Existing email delivery remains governed by its approved-source/dispatcher contract.

Static list actions are available under **Add step** as **Add to static list** and **Remove from static list**. Select an existing list created under Lists & Segments, save and publish. Only active editable static lists in the tenant/service are offered (up to 200). Dynamic list membership is not directly editable. Adding a Contact to a list does not change opt-outs, marketing status or consent.

**Create Case** is also available under **Add step**. Enter its name, optionally select a staff owner, priority and category, then save and publish. The enrolled Contact becomes the Case's primary and associated Contact. Priority choices come from native Case metadata; **Use native default** does not store a fixed priority in the workflow. Without an explicit owner, the publishing administrator owns the Case. The existing SLA hooks initialize deadlines. Workflow history provides the created Case ID; the record uses the existing Case pages rather than a separate support model.

Static-list action verification on 10 October 2026 passed: 154 workflow runtime checks, 10 browser cases across five viewports and the complete sequential repository verification. An earlier overlapping run reported one conditional-content governance failure; that test passed in isolation and in the subsequent full sequential run. The suite covers membership entry/exit history, native opt-out tombstones, re-addition, already-matching membership, archived/dynamic/foreign lists and consent-versus-list-opt-out counts. Changes remain local; this is not production Postal or Phase 6 exit-gate acceptance.

Tenant administrators can open **Automation > Workflows**, or the tenant workspace route `NexaWorkflows`. This uses the existing workflow APIs and native record-selection dialogs; it does not create another CRM record or automation backend.

## Available Controls

- Search workflows immediately within the loaded catalogue (latest 200).
- Create a named draft and choose once-only or after-successful-completion reenrollment.
- Choose manual enrollment or one of the four supported canonical Contact event types.
- Add/remove delays, branches, bounded condition waits, working hours, lifecycle changes and owner assignments.
- Choose a starting step, next steps and yes/no destinations without entering internal identifiers.
- Choose Contact properties and configured enum/lifecycle options supplied by the authorized backend.
- Set working weekdays with checkboxes and select an IANA time zone.
- Save drafts using optimistic revision checks, publish immutable versions, pause/resume and archive.
- Select a Contact using the native tenant-scoped selector, then enroll against the published version.
- Refresh execution/audit history and cancel active enrollments.

The editor orders displayed steps topologically rather than relying on the canonical JSON node order. Connections remain explicit destination selectors. This is a form-based graphical step editor, **not yet a free-form drag-and-drop journey canvas**. The authoritative backend still rejects cycles, disconnected steps, unknown properties, invalid durations, stale revisions and unavailable actions. Drafts with unsupported steps can be inspected or amended, but cannot publish until supported.

Publication and enrollment are disabled while there are unsaved changes. Published versions continue independently of later draft edits. Archived workflows are read-only; history remains accessible. Archive and enrollment cancellation require confirmation. A failed enrollment attempt retains its request key for retry; successful enrollment clears it so a later intentional enrollment uses a new key, subject to the backend reenrollment policy.

All requests use Espo's configured API base and native authentication. Tenant/service ownership cannot be supplied by the editor. Record and assignment eligibility remain backend-enforced even if a selected record becomes unavailable after selection. Stored names and history values are escaped before display. Sequence guards discard responses after switching workflow or removing the view. Workflow list searching is local to the bounded catalogue; it is not a complete server-side search across more than 200 workflows.

## Local Check

To test calendar dates, create an enabled Contact date property such as **Renewal date** under **Administration > Objects & Properties**. In a workflow branch or condition wait, select that property, choose **On or before** and use the date picker. Missing dates take the nonmatching branch unless the condition is **Is empty**. The same property is available in the marketing email conditional-content builder, including rich matching/fallback content. These are fixed calendar dates, not relative-to-today or date-time conditions.

For timestamps, create a Contact date-time property such as **Appointment time** and select it in a branch or condition wait. Enter an explicit-offset threshold such as `2028-02-29T10:00:00+01:00`. Comparisons use UTC instants and preserve stored microseconds; missing timestamps only satisfy **Is empty**. Optional property visibility and archived definitions are rechecked at publication and execution.

For rolling windows, choose **Within the next (UTC)** or **Within the last (UTC)** and enter a day count from 0 to 365. A calendar renewal date within the next 30 days includes today and the UTC date 30 days ahead. Timestamp windows use elapsed time instead of whole calendar dates. These same choices are available under Marketing Email conditional content. Preview and execution evaluate the current clock, so a saved condition can match differently when delivered later.

For page-specific behavior, select recorded website visits, choose a presence or count comparison and optionally fill **Exact page URL**. For example, count visits to `https://company.example/Pricing` in the last 30 days. Lowercase `/pricing` and an added query string are different pages. Clearing the URL restores the any-page rule; changing the property removes the filter. Branches, waits and conditional email content share these semantics. This is not an ordered multi-event journey condition.

For a sales follow-up, add **Create Opportunity**, enter its name, choose an active pipeline and stage, enter the amount, select an enabled tenant currency and choose the expected close date. Optionally select a staff owner; otherwise the publishing administrator owns the new Opportunity. Changing the pipeline clears the previous stage. Configure pipelines in the existing Sales workspace first if none are available. The result appears in the existing Opportunity list, linked to the enrolled Contact.

For an internal alert, add **Notify staff**, choose a staff recipient and enter the notification message. Save and publish, then enroll a test Contact. After background processing, the eligible recipient receives the message in the existing notification bell. Recipients must have access to that Contact; the step does not send an email.

1. Sign in as a tenant administrator and open **Automation > Workflows**.
2. Create `Customer onboarding`, add a **Set lifecycle stage** step and select a configured stage.
3. Save the draft, then publish it.
4. Enroll a test Contact from that tenant.
5. Refresh history after the native scheduled workflow job runs; confirm the Contact's stage changes once.
6. Pause a second enrollment before processing, then verify its state is retained. Resume to continue, or cancel it.

The job advances at most one step per enrollment per run, on the native minute schedule; this is durable background execution, not an immediate browser-side action. Do not use real marketing delivery as a test of these database-only steps.

## Verification Scope

Create Case verification on 10 October 2026: 169 rollback-only workflow runtime checks, all 10 browser cases across five viewports, and the full sequential repository verification passed. Coverage includes native Case relationships and SLA initialization, tenant/service ownership, invalid settings, deactivated owners, native defaults, replay and cancellation. WAMP was rebuilt, its cache cleared and the app timestamp updated. No additional migration was required. These checks do not close the Phase 6 exit gate.

`tests/browser/automation-workspace.spec.js` uses API and native-selector doubles to exercise real view code across the configured five desktop/tablet/mobile viewports. It covers draft payloads, configured options, topological order, publication controls, enrollment retry keys, pause/resume, owner selection, weekday changes, live catalogue search, escaping, stale responses, accessibility and overflow. These tests are not authenticated end-to-end acceptance or actual delivery evidence. The separate rollback-only runtime suite covers the real database and native record services.

The visual journey canvas now supports shared draft positions, pointer/keyboard movement and direct output-port connections with cycle prevention. Layout changes do not change executable version identity. Full live canvas acceptance, remaining automatic trigger families/filters, scoring and remaining action adapters, transactional email, A/B testing and production Postal acceptance remain pending. [Event enrollment](workflow-event-enrollment.md) documents the currently executable Contact, segment and email triggers. The published trigger, not draft changes, determines whether manual enrollment is available. This screen does not close the Phase 6 exit gate.

Local verification on 10 October 2026: all 10 browser cases passed across five viewports, including accessibility scans, native icon-font loading, exact custom decimal values, activity windows, adding/removing all/any condition rows, native task configuration and archived read-only controls. The 138-check rollback-only runtime suite and full repository verification also passed. WAMP metadata was rebuilt, cache cleared and the app asset timestamp updated. This is not proof of production Postal delivery or the complete Phase 6 exit gate.
