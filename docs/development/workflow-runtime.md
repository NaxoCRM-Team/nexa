# Workflow Runtime

## Behavioral Counts

Branch and wait conditions on existing `behavior:` fields support `count_at_least`, `count_at_most` and `count_equals`. `value` remains the whole-day window (string, 1-365); `count` is an integer threshold from 0-10,000. Presence conditions reject a count, as do nonbehavior fields. An empty editor count is invalid, not zero.

Counts reuse the tenant/service/Contact-scoped timeline and each event's approved source type and visibility. Only events with source timestamps within the UTC window are included; future events are excluded. Distinct source identities prevent duplicate projections from increasing the count. The query stops at 10,001 distinct identities, which preserves every supported threshold comparison. Zero is a valid comparison against an empty history.

Website-visit conditions now also accept an optional `pageUrl`, validated as an exact HTTP(S) URL without credentials, whitespace or a fragment. A blank editor filter omits the setting. Both presence and count conditions resolve canonical `BehaviorEvent` source records in the same tenant, service and Contact, preserving path case and query identity. The URL is a parameterized database comparison, not a fetch target. Existing unfiltered definitions keep their original behavior. This does not add ordered multi-event journeys, a separate tracking store, lead-score modification or consent overrides. Conditional marketing email count blocks and page filters reuse their native template helper.

## Governed Email Action

`email.send` selects a current approved marketing email source and immutable source version. Optional parameters follow the source's existing bounded text contract. The editor offers scoped approved sources and generates parameter inputs; it does not allow sender overrides.

Publication and execution validate approval, active campaign, current version, native content integrity, files/images, sender and execution-user permissions. Execution calls the existing reusable-send service with a stable key derived from tenant, service, run, workflow version and node. Queue creation and workflow advancement are atomic. The action audit records the native queue ID and `deliveryState: queued`; provider acceptance/delivery is not claimed. Existing dispatcher consent, suppression, allowance, recovery and retry rules remain authoritative.

Only one queue-creation attempt is allowed. This is marketing delivery, not transactional delivery. Cancellation before execution prevents queue creation; cancellation after queueing does not retract an already committed email queue item.

## Calendar-Date Conditions

Enabled tenant Contact properties of type `date` are available in branches and condition waits. Comparisons support `equals`, `not_equals`, `before`, `after`, `on_or_before`, `on_or_after`, `has_value` and `is_empty`, including existing flat all/any groups. Comparison values must be valid `YYYY-MM-DD` calendar dates; timestamps and invalid leap days are rejected for calendar properties. Missing values only satisfy `is_empty`, not `not_equals`. The shared date helper compares calendar dates without worker-timezone conversion. Execution reads existing `value_date` storage in the enrolled Contact's tenant/service; archived or disabled properties fail closed.

Enabled `datetime` properties support the same comparisons using existing `value_datetime` UTC storage. Thresholds require seconds and an explicit offset, for example `2028-02-29T10:00:00+01:00`. The shared email date-time helper compares equivalent instants independently of the worker timezone and preserves stored microseconds. Graph validation accepts calendar or timestamp syntax; publication and execution enforce the actual property's type. Waits reread the scoped value at each due poll, retaining existing timeout semantics. No migration is required.

Relative operators `within_next_days` and `within_last_days` take a canonical whole-day string from `0` to `365`. Calendar properties compare inclusive UTC dates, including today; a zero window means today. Timestamp properties compare inclusive elapsed UTC instants and preserve microseconds; a zero window means only the current instant, not the whole day. Missing values do not match either window. Evaluation uses the current clock at preview, delivery or workflow execution, not draft creation; wait polls evaluate again without extending their existing timeout. These operators cannot be applied to text or numeric properties. Tenant-local calendar-day rules are not implemented.

## Native Opportunity Creation

`opportunity.create` uses the native Opportunity record service, relationships, validation and sales lifecycle hooks. Settings require `name`, an active scoped `pipelineId` and `stage`, a nonnegative `amount` decimal string (up to 12 integer digits and two decimal places), an enabled tenant `currency` and an ISO `closeDate`. Optional `ownerId` defaults to the publishing execution administrator. Native currency persistence/calculations remain authoritative; this adapter does not introduce a second monetary model.

Publication and execution recheck native create/field/assignment ACL, active same-tenant/service staff, active pipeline/stage and enabled currency. Stages requiring extra properties outside the action's supplied native fields are rejected rather than inventing values. The enrolled Contact is linked through the native primary and many-contact relationships; no Account is inferred automatically. Existing stage probability, forecast category, expected revenue and revenue history remain governed by sales hooks. Creation, native ID/action-key audit and advancement are atomic, with one attempt, replay protection and cancellation. No migration is added.

## Native Staff Notifications

`notification.create` uses the existing system-notification model and bell, not a separate inbox or email sender. Settings require `ownerId` and a message of up to 1,000 characters. Publication and execution require an active regular/admin recipient in the same tenant and service, assignment permission and recipient Contact-read scope. Execution also checks the recipient's access to the enrolled Contact before storing its native related-record reference. The native creator save option retains the publishing execution administrator.

Notification creation, its audit ID/recipient/stable action key and step advancement share the existing atomic transaction. Only one atomic attempt is supported; replay, cancellation and recipient deactivation cannot silently create a second notification. No additional migration or real email delivery is involved. The editor exposes this action as **Notify staff**.

This increment delivers tenant/service-scoped storage, a background runtime and native Contact lifecycle/owner actions. The [workflow step editor](workflow-editor.md) provides tenant-admin UI controls. [Initial canonical-event enrollment](workflow-event-enrollment.md) is available for four identified Contact event types. A free-form journey canvas and remaining trigger subscriptions/filters remain pending. Unsupported triggers and action steps can be saved in drafts but cannot be published or executed.

## Storage And Identity

Migration `0070_add_workflow_runtime.sql` adds workflow drafts, immutable published versions, durable Contact enrollments and append-only audit events. It leaves native CRM tables unchanged.

Only an active, persisted administrator in the current tenant and service can configure, publish, manually enroll or cancel. The publishing administrator is the execution identity for that version. The worker runs in the native scheduled job's tenant context and rechecks the persisted identity, Contact record access, field access, soft-delete state and service availability. It never changes to a global unrestricted CRM identity.

Each enrollment pins a published version. Saving and publishing another draft does not redirect an existing enrollment to new rules. Version fingerprints are checked before enrollment, resume and execution; corrupted versions fail closed. Draft revision checks reject lost updates.

Request keys are case-sensitive, 8 to 128 characters, and use letters, digits, `.`, `_`, `:` or `-`. Their hashes are unique within a tenant/service/workflow. Replaying a key returns the original enrollment and version, including while paused; a different Contact cannot reuse it. Contact access is still required for replay. `never` prevents subsequent enrollments for that Contact. `after_completion` allows a new key only when every earlier enrollment completed successfully; active, failed and cancelled enrollments do not qualify.

## Executable Steps

- Manual enrollment.
- Automatic identified Contact enrollment from canonical form submission, website visit, asset download and recorded email reply events, with migration `0071` processing receipts.
- End/completion.
- Yes/no conditions on allowlisted native Contact properties, enabled tenant custom properties and recorded activity windows. Flat groups support all/any of one to six rules; nested groups are rejected.
- Elapsed delays of one second to one year.
- Condition waits with bounded timeout and checks no more frequently than once per minute.
- IANA-time-zone working hours with selected weekdays and same-day opening/closing times.
- `lifecycle.set`: update a configured non-empty lifecycle option through the native Contact record service.
- `owner.assign`: assign an active regular/admin staff member in the same tenant and service through native assignment validation.
- `task.create`: create a native Task linked to the enrolled Contact, with a required name and an optional active staff owner. If omitted, the publishing administrator is the owner. Native create/field ACL, assignment checks, validation and hooks remain authoritative.
- `case.create`: create a native Case with the enrolled Contact as its primary and associated Contact. A name is required; an optional active staff owner, configured priority and category are supported. Omitted owner uses the publishing administrator; omitted priority retains the native default. Native Case validation, relationships and SLA initialization hooks remain authoritative.
- `segment.add` / `segment.remove`: change membership of an active static list through native Target List link/unlink services. Only tenant/service-owned editable lists are selectable. Dynamic lists remain rule-controlled and are rejected.

List actions serialize on the scoped segment definition and recheck native Contact/list access, relationship field permissions, deletion and archive state. Membership, refreshed counts, the existing segment run/membership evidence and workflow advancement commit together. Existing membership is a no-op rather than a duplicate entered/exited event. Native list opt-outs are retained through removal and re-addition; membership never grants consent or enables marketing. Static eligible counts include list-level opt-outs. Workflow history records the list and membership run IDs, while segment history records the execution administrator and UTC occurrence time. These actions need no new native entity or migration.

Actions execute with the publishing administrator's record and field ACL. Publication and each execution recheck the configuration and assignment target. Contact update actions lock the scoped Contact before reading its current state, so different workflows cannot race a read/modify/write. Already matching values advance without another native write. Task creation verifies persisted tenant/service ownership, records the native Task ID and commits creation together with advancement and audit. Native hooks, validation and audit behavior remain in place; native background updates retain native system attribution, while the workflow event explicitly identifies the publishing execution administrator.

Action changes, node advancement and workflow audit commit in one transaction. A nested savepoint rolls back native writes if validation or a later hook rejects the step. Stable action keys derive from tenant, service, enrollment, immutable version and node; repeat processing does not repeat a committed step. These database-only actions require `maxAttempts: 1`. Configuration/access errors fail the run; unexpected database errors roll back and are left to native job retry. This is not an external-provider lease protocol.

Case actions recheck native create/field permissions, configured options and the active tenant/service-owned assignment target at publication and execution. Creation verifies persisted scope and primary Contact before advancing. Workflow history includes the native Case ID and stable action key. Cancellation before execution creates nothing. The action does not grant portal access, fabricate consent or send an email; existing native support workflows remain separate. No new Case table or migration is needed.

Lead score remains a read-only system-managed property. `score.increase` and `score.decrease` cannot publish until a dedicated scoring adapter exists. Email, webhook and other action names also remain blocked; the workspace capability response lists only executable actions.

Native integers and stored custom numbers/currencies use exact decimal comparison, not floating-point conversion. Custom text, single-select and boolean properties are supported; disabled/archived definitions fail publication and execution revalidation. Text and enum values are type-checked; enum comparisons require a configured option. Missing values do not satisfy equality, inequality or numeric comparisons; use the explicit presence conditions instead. Recorded website visits and email opens/clicks/replies reuse the existing canonical timeline source mapping, exclude restricted events and use bounded 1-365-day windows. These are recorded events, not a new website tracker or mailbox listener. Every group member is authorized even when another any-condition already matches.

Delay deadlines are measured from entry into the step. Due times and audit timestamps are stored as UTC. Expired condition waits take their timeout branch: a current match cannot prove that a match occurred before the deadline. Business-hour steps remain on that step while waiting and recheck the interval at wake-up, so a late worker cannot bypass a closed interval.

`ProcessWorkflows` uses the existing native scheduled-job infrastructure. Each tenant job processes at most 25 due enrollments and one step per enrollment per batch. Locks use workflow-then-enrollment order, shared by cancellation and publication. Transition and audit writes commit together. There are no external effects in this increment, so no provider retry or uncertain-submission recovery is implied. Future action adapters will require stable action identity, leases and appropriate recovery boundaries.

Pause holds existing enrollments without deleting their state; resume continues their original versions. Archive cancels unfinished enrollments and cannot be undone. Individual cancellation is idempotent. Deleted Contacts and inactive/deleted/demoted execution administrators fail closed. Disabled service scopes hold processing rather than silently completing records.

## API

Paths are relative to the application's configured API base, not a hardcoded hostname or folder.

| Method | Path | Input |
| --- | --- | --- |
| GET | `Nexa/workflows` | None; up to 200 workflows and the actual supported capabilities |
| POST | `Nexa/workflows` | `name`, `definition` |
| GET | `Nexa/workflows/:id` | None |
| PUT | `Nexa/workflows/:id` | `name`, `definition`, integer `expectedRevision` |
| POST | `Nexa/workflows/:id/publish` | Integer `expectedRevision` |
| POST | `Nexa/workflows/:id/control` | `status`: `paused`, `published` (resume) or `archived` |
| POST | `Nexa/workflows/:id/enroll` | `contactId`, `requestKey` |
| POST | `Nexa/workflows/:id/runs/:runId/cancel` | Empty object |
| GET | `Nexa/workflows/:id/history` | None; latest 100 enrollments and 200 audit events |

Read responses are private and no-store. Ownership and execution-user overrides are rejected. There is no API for invoking the system worker, editing published versions, deleting audit evidence or retrying failed actions.

Example definition:

```json
{
  "schemaVersion": 1,
  "trigger": {"event": "manual", "reenrollment": "never"},
  "entry": "qualify",
  "nodes": [
    {"id": "qualify", "type": "branch", "condition": {"field": "leadScore", "operator": "greater_than", "value": 50}, "yes": "wait", "no": "end"},
    {"id": "wait", "type": "delay", "seconds": 3600, "next": "end"},
    {"id": "end", "type": "end"}
  ]
}
```

## Verification

`tests/tenant/TenantWorkflowRuntimeTest.php` passes 115 rollback-only database/API-wrapper checks. Coverage includes revision conflicts, immutable versions, original-version execution, key replay, reenrollment, waits, timing, pause/resume, draft/published archive, cancellation, truthful capability catalogues, API input validation, private responses, deleted Contacts, inactive execution administrators, version tampering and service/tenant isolation, including global service disable. Canonical-event checks add publication cutoffs, UTC receipts, event replay, batch draining, eligibility skips, trigger block/recovery and native Contact form-hook projection/rollback. A second PDO connection verifies a lock conflict; this is not a full concurrent-worker acceptance test.

The existing 58 graph checks and 22 clock checks remain. Test fixtures use isolated identities and `.test` email addresses, roll back their data and never submit mail. API-wrapper tests exercise the native request parser and action classes, not a live authenticated browser session.

On 9 October 2026, repository verification passed with the runtime registered in the verification runner. The final global-service guard then passed the focused 71-check rerun. Migration `0070` was applied locally and skipped safely on an incremental rerun. Native rebuild provisioned the workflow job per tenant, and rollback-only testing left no saved workflow fixtures.

That pending statement describes the initial runtime, not the 11 October tree. The canvas, expanded Contact-subject triggers, native adapters, transactional retries, subscription capacity, fair workflow selection and managed webhook delivery are now implemented. Complete live/concurrent acceptance remains open in the [canonical checklist](phase-6-closure-plan.md#11-october-implementation-and-acceptance).

## Execution Safety Configuration

Automation uses the existing active subscription and enabled plan-service grant. The effective native hard limit caps non-archived workflows tenant-wide. Plan configuration may set `maxActiveRuns`, `maxStepsPerDay` and `maxConcurrentWebhooks`; tenant grant configuration can override those technical limits. Defaults are 10,000 active enrollments, 100,000 UTC-day steps and two concurrent webhook leases. Invalid/inactive entitlements fail closed. Capacity checks acquire the tenant lock before workflow/run locks. Exhausted automatic enrollment leaves its event unconsumed; exhausted daily processing defers runs rather than failing them.

Each worker batch rotates least-recently-processed workflows, interleaves at most five due runs per workflow and caps the batch at 25 steps. Native action retries persist attempt counts, roll back failed ORM writes before scheduling bounded exponential backoff, and do not retry validation/permission errors. Email queue creation retains one atomic attempt; email delivery retries stay with its existing dispatcher.

`webhook.send` selects an active native Webhook with a signing secret and stages a native WebhookQueueItem in `Managed` state, outside native Pending dispatch. Migration 0073 stores its stable effect ID, immutable endpoint/body hashes, lease and attempt evidence. The background job sends outside a transaction with public HTTPS DNS pinning and bounded transport. Pre-submission failure may retry; uncertain outcomes require an administrator's evidence review in workflow history. The workflow stays on its webhook step until delivery is confirmed. Cancellation stops pending effects, never blindly resubmits in-flight/uncertain effects, and cannot recall a request already handed to the network.

The focused runtime suite now passes 84 checks, including native lifecycle updates, no-op actions, stable action audit identity, repeat-worker safety, cancellation, configured options, same-tenant owner assignment, foreign/disabled-owner rejection and read-only score protection. The complete local repository verification also passed with these actions on 9 October 2026. No new schema migration is needed for these action adapters. These results do not establish visual-editor, external-provider or complete Phase 6 acceptance.
