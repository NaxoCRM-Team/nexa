# Phase 6 Native Email Capability Audit

Nexa retains EspoCRM's native `EmailTemplate`, `MassEmail`, `EmailQueueItem`, `CampaignLogRecord`, `CampaignTrackingUrl`, inbound sender configuration, scheduler and ACL behavior. These records already carry mandatory tenant and service ownership in the shared schema and remain the authoritative delivery model.

M10 adds governance that the native model does not provide: content versions, review and approval state, consent-purpose binding, delivery modes, sending-domain ownership, provider reconciliation, plan limits and canonical Phase 5 delivery events. The first slice stores those additions in `nexa_marketing_email_*` tables linked one-to-one with native Mass Email records.

The Nexa Marketing Email workspace replaces the old user experience but does not introduce another template, queue or campaign-log system. Draft creation writes through EspoCRM record services. Scheduling requires approval, provider/domain readiness and sufficient tenant allowance; live Postal acceptance is still pending.

Postal is the initial marketing-delivery adapter, not a domain model. Provider identifiers and payloads stay behind an adapter boundary so tenants and future deployments are not coupled to Postal.

## Delivery readiness boundary

Migration `0063` adds tenant/service-owned provider configuration, sending-domain readiness, suppression and immutable delivery-event storage. Provider credentials use the existing encrypted managed-secret store and are never returned to the browser. A tenant is delivery-ready only after a credential exists and at least one exact sending domain passes ownership, SPF, DKIM, DMARC and custom return-path checks.

Postal remains responsible for accepting and delivering messages, retrying temporary failures and monitoring its configured domains. Nexa owns campaign consent, tenant limits, scheduling authorization, callback idempotency, canonical customer events and suppression decisions. Postal documents that its current HTTP API is intentionally limited to message operations, while sending domains and their DNS records are administered in Postal and checked by its workers: https://docs.postalserver.io/developer/api/ and https://docs.postalserver.io/features/sending-domains/.

Migration `0064` adds the native-queue-to-provider message map and monthly usage ledger. The public callback uses a 48-character one-time token whose SHA-256 hash is stored per tenant and service; administrators can rotate the URL, and repeated provider events are idempotent. Hard bounces and complaints enter the tenant suppression list automatically.

`MarketingDeliveryProvider` is the transport boundary. `PostalDeliveryProvider` retrieves the tenant's encrypted API key only at runtime and submits through Postal's HTTPS message API. It does not select recipients, bypass consent, construct a second campaign queue or own scheduling. The governed dispatcher must continue to select and render native `EmailQueueItem` records before invoking this adapter.

## Governed dispatch boundary

Approved emails can be scheduled only when their campaign is active, their sender domain is verified and the tenant has sufficient monthly capacity. `ProcessMarketingDelivery` runs once per minute inside each tenant's scheduled-job context. It creates native `EmailQueueItem` records from governed campaign enrollments and renders the native `EmailTemplate` for each Contact.

Eligibility is checked again immediately before submission. Withdrawn or expired consent, contact and channel do-not-contact controls, target-list opt-out, native invalid/opted-out email flags and active provider suppressions all prevent delivery. Monthly capacity is locked and reserved before the external request. A delivery-message record is created before calling Postal so concurrent workers cannot submit the same queue item twice. Native Mass Email statuses use `Nexa Scheduled` and `Nexa Sending` while Postal owns transport, preventing the stock SMTP worker from racing the governed dispatcher.

## Callback reconciliation checks

The receiver accepts Postal's event envelope with a nested `payload` as well as the direct payload format. Callback UUIDs provide retry idempotency when present; the fallback identity includes click URL and tracking token. Engagement events cannot reset delivery status, and late delivery/defer notifications cannot overwrite terminal failures. Monthly delivery counters advance only for a matched message status transition, so unmatched callbacks and repeated notifications cannot inflate totals. Postal API response parsing supports recipient-keyed message results.

Local database acceptance covers nested payloads, retries, opens/clicks after delivery, delayed events arriving after delivery, and repeated delivery notifications. Callback message tokens also resolve differences between Postal's API and webhook message identifiers; the stored recipient must match before a known message can be reconciled.

## Unsubscribe and customer history

Governed emails now contain an HMAC-signed public unsubscribe URL, `List-Unsubscribe`, and `List-Unsubscribe-Post` headers. GET displays a confirmation without changing preferences. POST enters the original queue item's tenant/service context and calls native `UnsubscribeService`, retaining native email and target-list opt-out behavior. An active tenant suppression prevents future governed marketing delivery even if a native email is not configured to opt out entirely. Signing requires the existing application `hashSecretKey`; keep it stable across deployments to preserve delivered links.

Matched delivery events and confirmed unsubscribes project once into the existing `nexa_timeline_event` and `nexa_outbox_event` contracts. Contact identity comes from the scoped native queue item, not an email-address search. The associated Account is included only when it shares the Contact's tenant and service. Unmatched callbacks are retained for investigation but do not create customer activity.

`TenantMarketingLifecycleTest.php` verifies the confirmation action, native opt-out, signed-link rejection, repeated unsubscribe, delivery/outbox idempotency, token-based message matching, and tenant isolation. Contact activity consumes marketing events alongside behavioral events. Account activity merges marketing events only for Contacts visible through the native Contact record service; these audit events are displayed without edit/delete actions or fabricated record links. Browser checks cover the actual Contact/Account rendering methods across five viewport sizes. No new migration is required for this slice.

## Early callbacks and delivery reporting

The existing per-tenant `ProcessMarketingDelivery` job revisits up to 100 unmatched, authenticated callback receipts once their provider message ID or token becomes available. Replay preserves the original occurrence time and uses the same row locks, status transition rules, timeline projection and idempotency boundary. Receipts with conflicting recipients cannot attach to a customer or block the recovery batch.

Marketing Email now exposes a per-email delivery-results dialog, with current message outcomes and unique recipient opens, clicks and unsubscribes. Administrators also see the current calendar month's effective limit, reserved usage and remaining capacity. The limit is 20 times the tenant's marketing-contact tier, using its tenant override or subscribed plan hard limit. A lower configured provider cap remains an additional ceiling. Missing, disabled or expired service entitlements block sending; a zero provider cap does not bypass the plan allowance. Scheduling and transactional per-message reservations use the same calculation. Reports do not reveal provider credentials or callback URLs. Native queue/profile scheduling and completion remain separate from actual delivery success.

Local tests cover callback arrival before the provider response is persisted, repeated reconciliation, report/ledger reconciliation and two-tenant isolation. Live Postal delivery, provider-side resolution of uncertain sends, and the remaining Phase 6 editor/automation deliverables are still pending.

## Interrupted submission guard

Completion waits for both Pending and Sending native queue items. The tenant worker pauses a sending profile when a submitting message has had no update for 15 minutes, keeps its monthly reservation and records one `submission_review_required` audit event. The native MassEmail becomes `Nexa Paused`, preventing the stock SMTP worker from sending it. Recovery does not clear the queue or automatically resend: an interrupted worker may have submitted to Postal before losing its response. Provider-side inspection and a reviewed resolution workflow remain required before retrying. Transactional tests cover active submissions, stale pauses, repeated workers and cross-tenant isolation. No schema migration is required.

## Content versions and restoration

The email table includes a content-history dialog listing the latest 100 immutable versions with author and timestamp. A selected version is rendered in an inert sandbox frame; scripts, forms and remote tracking resources remain blocked. History requires native MassEmail/EmailTemplate record access and subject/body field-read permissions in the current tenant/service. Administrators may restore an editable email's earlier content through the native save path: this appends a new version, resets review/approval, preserves current campaign/sender settings and records the source version in an audit event. Scheduled, sending, paused and completed emails cannot be restored. Save/restoration locks the profile so version numbering cannot race another edit. Runtime and browser tests cover immutable history, approval reset, cross-tenant rejection, scheduled restore rejection and responsive controls. No schema migration is required.

## Delivery pause and resume

Tenant administrators can pause scheduled/sending profiles and resume paused profiles through the existing email table. The control action locks the scoped profile, updates native MassEmail to a Nexa-held status, and writes actor/version/status audit evidence in the same transaction. Queue contents and allowance reservations remain intact. Resume checks campaign/provider/domain readiness and refuses any Sending queue items; uncertain submissions cannot be retried through this control. Queue preparation and per-recipient reservation recheck the profile under lock, so a paused profile cannot reserve more recipients. Messages already reserved/submitted can still complete. Completion and failure updates cannot overwrite a pause. Native consent and per-message capacity checks remain active after resuming. Tests cover scheduled pause/resume, tenant isolation, in-flight rejection and role-aware button visibility. No migration is required.

## Inert content preview

The existing HTML draft editor now includes a live desktop/mobile preview with subject and preheader. Its sandboxed iframe and restrictive Content Security Policy block scripts, forms and remote resources, including tracking pixels. The design preview leaves personalization placeholders literal. "Preview as Contact" uses the native Contact selection modal and a private, no-store endpoint that applies native record and field ACL, explicit tenant/service ownership checks and the native EmailTemplate processor with attachment copying disabled. Unsaved content can be rendered without creating records or queuing mail. Content edits invalidate the rendered preview, preventing stale personalization from being displayed. Suggested tokens now use native syntax such as `{Contact.firstName}` and `{Contact.accountName}`. Browser tests cover live content updates, mobile width, script isolation, recipient rendering and blocked external image requests across five viewport sizes. Runtime tests cover native personalization, oversized inputs, no queue side effects and cross-tenant rejection. A visual content builder remains pending.
