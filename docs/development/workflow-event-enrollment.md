# Automatic Workflow Enrollment

## Page Selection

Website-visit workflows may set an optional `trigger.pageUrl`. It must be an absolute HTTP(S) URL, at most 2,048 bytes, without credentials, whitespace or a fragment. It is available only for `page.viewed`; other trigger types reject it.

The immutable published definition retains the exact URL. Enrollment uses a parameterized, binary comparison against the canonical event's `page_url` before applying the bounded batch limit. Path case and query strings matter; unmatched events consume no receipts. Omitting the filter preserves all-page enrollment. Existing identity, consent/timeline evidence, publication-time, tenant/service, execution-user and reenrollment checks still apply. No network request is made to the supplied URL.

This increment connects the existing workflow runtime to canonical behavioral events. It does not create another tracking/event store or dispatch marketing email.

## Supported Events

| Trigger | Required source |
| --- | --- |
| `page.viewed` | A consent-validated event recorded by the existing trusted event ingestion/collector, attached to a verified Contact |
| `asset.downloaded` | An identified canonical asset-download event; the workflow does not itself instrument arbitrary download links |
| `email.replied` | A recorded canonical reply event; this is not a new IMAP/mailbox polling integration |
| `form.submitted` | A canonical Contact form-submission event; the native published-form submission hook now projects one when its resolved target is a Contact |

The editor also exposes native Contact created/updated/property-changed, lifecycle changes, score-threshold crossings, list entry/exit and recorded email opens/clicks. Property-change enrollment requires an authorized native Contact property. Score enrollment requires a nonnegative integer threshold and recorded previous/current scores crossing it; staying above the threshold is not another crossing. List events require an active, readable tenant/service list. Email events require both the canonical Contact timeline projection and the matching existing marketing delivery event; an unsupported timeline label alone is not enough.

The current runtime enrolls Contacts only. A default form that creates a **Lead** does not automatically become a Contact and does not enroll in these workflows. Lead/Account enrollment, activity-completed, Opportunity-stage, scheduled date and authenticated API/webhook triggers remain pending. Unsupported trigger families remain blocked at publication.

New native customer outbox and dynamic segment membership events explicitly record UTC occurrence times. This avoids local-database clock differences making freshly committed events appear to occur in the future. Existing historical timestamps are not rewritten.

## Processing Rules

Migration `0071` adds scoped receipt and scan-state tables, plus an index on the existing event store. No event payload is copied. Receipts remain after canonical event retention/purge and do not hold website URLs, form contents or message bodies.

The native `ProcessWorkflows` job first scans automatic workflows, then advances due runs. Only the system worker can invoke event processing; it always uses the current tenant/service. Published versions determine the trigger and execution identity; unpublished draft changes cannot change the running subscription. The publishing administrator must remain active and retain native record/field/assignment access.

Each scan handles at most 25 workflows, oldest scan first, and at most 25 events per workflow. Workflow locks serialize scanning with publication, pause/archive and cancellation. Receipt, enrollment and enrollment audit commit together. Unexpected failures roll back and leave source events retryable; no receipt is written for a partial transaction.

Eligibility requires matching tenant/service, event type/version, an identified Contact and a corresponding non-restricted canonical timeline projection. Both event occurrence and receipt time must be on/after the current publication, and occurrence must not be in the future. There is no historical backfill. New ingestion explicitly records UTC receipt time even when the database's server time zone is local. Existing receipt timestamps are not rewritten. The occurrence boundary also protects new publications from pre-existing local-time receipt values.

No increasing-ID/timestamp cursor is used. Eligible late commits and subsequently identified events remain discoverable through an anti-join against processing receipts. Each event can be consumed at most once per workflow, including across publication versions. A new intentional reenrollment requires a distinct eligible event and the configured successful-completion policy. Events arriving while the Contact is ineligible are recorded as skipped, not deferred until completion.

Pause retains eligible events; resume scans retained events against the same published version. Archive cancels active runs and stops scanning. Disabled service scopes hold processing. Deleted/inaccessible Contacts receive durable skip reasons. An unavailable execution identity or invalid workflow configuration holds the subscription; a blocked-state transition and later recovery are audited without logging the same failure on every scan.

Native published Contact form captures now project `form.submitted` through `BehaviorEventService`, after their governed form actions and optional consent evidence. The bridge uses the server-resolved target, not a browser-supplied Contact ID or visitor key. Form evidence, consent/actions and canonical projection share a transaction/savepoint. Submission-hook replay does not repeat the projection. Lead-target submissions keep the existing native behavior and are not falsely identified as Contacts.

## Local Testing

Local verification on 10 October 2026 passed: 115 workflow runtime checks, 10 responsive/accessibility browser cases, the native form runtime suite and complete repository verification. Migration `0071` was applied and a repeat update skipped it safely. WAMP rebuild, cache clearing and application timestamp refresh completed. This evidence does not replace live public-form or production Postal acceptance.

Open **Automation > Workflows**, choose an event in **Enrollment**, add a supported step, save and publish. A subsequent matching canonical event for an identified Contact in that tenant is enrolled by the native minute job. Refresh history to inspect `enrolled`, source event identity, skip/block reasons and completion. There is no manual Enroll button for an automatically published workflow.

The rollback-only runtime suite covers canonical ingestion, native Contact action execution, event/hook replay, publication cutoffs, UTC receipt time, pause/resume, inactive administrators and recovery, skipped eligibility, anonymous/restricted/deleted Contacts, all four event types, reenrollment, batch draining, disabled scope and two-tenant separation. The form bridge test invokes the real native hook service but is not a live browser form submission. Browser tests separately cover selecting triggers and preserving the published trigger while changing drafts.

This is initial type-based automatic enrollment, not complete journey/automation acceptance. Advanced trigger filters, more record types, external actions/recovery, quotas and the combined Phase 6 exit gate remain open.
