# Phase 8: Conversations and Omnichannel Messaging

Status: in progress. Modules M14 and M15. Nexa runs on WAMP; existing personal mailbox connections and Postal marketing delivery are unchanged.

## Fixed Delivery Checklist

- [x] Audit native email, group mailbox, reply, ownership and read-receipt capabilities before implementation.
- [x] Add a shared-email inbox backed by native Email/InboundEmail relationships, not duplicate message storage.
- [x] Provide subject/sender search, status and owner filters, bounded cursor loading, thread view and native reply composition.
- [x] Add Open/Pending/Closed conversation status independently of native Draft/Sending/Sent/Archived/Failed transport status.
- [x] Apply native record/field ACL, tenant/service boundaries, scoped staff search and audited assignment changes.
- [x] Enable native Email versions and reject stale or conflicting inbox updates, including intervening native record edits.
- [x] Reopen shared conversations on new replies from the trusted native importer, preserving native delivery status and owner.
- [x] Add recipient-specific unread/mark-unread and replied views, permission-checked commercial context and a native mailbox-configuration entry point.
- [x] Reconcile historical shared branches without promoting personal ancestors, duplicating rows or altering old message content.
- [x] Complete local configured-mailbox receive/reply/failure-recovery acceptance through real SMTP/IMAP; external-provider acceptance is not claimed.
- [x] Add the local website chat channel, verified-identity bridge and configurable/draggable widget. See [live chat setup and acceptance](phase-8-live-chat.md); public HTTPS and full Phase 8 acceptance remain pending.
- [x] Add bounded conversational bot definitions/execution, a guided greeting/choice editor and persisted staff handoff. See [bot runtime scope and acceptance](phase-8-live-chat.md); automatic queue routing is covered separately below.
- [ ] Complete channel-provider acceptance. Local SMS dispatch, WhatsApp two-way text/template conversations and Messenger signed inbound/text replies are implemented. WhatsApp reuses the existing queue, consent/DNC checks, quotas, attempt binding and uncertainty recovery. Incoming attachment storage and authorized downloads are implemented locally. See [provider scope and activation](phase-8-messaging-providers.md), [Messenger scope](phase-8-messenger.md) and [incoming media](phase-8-incoming-media.md). Real provider and Cloudflare acceptance remain open; local implementation is not production acceptance.
- [x] Enforce known SMS opt-outs at preparation and dispatch, retain signed STOP/START/HELP evidence, recover historical preference indexes and display staff warnings. START does not confer consent. Administrator-reviewed, purpose-specific suppression release requires fresh explicit consent and current signed evidence; release never clears DNC or revives old blocked messages.
- [x] Connect locally implemented chat, SMS, WhatsApp and Messenger history into the governed Contact timeline and automation-event sources, with explicit identity/link permissions and revocation.
- [x] Show currently verified website chat in the existing Contact Activities timeline, with visitor/staff/bot attribution and native ownership/field permissions. Proof expiry and revocation remove association visibility; four-channel automation integration is covered by the unified timeline item above.
- [x] Complete local combined failure, bounded load/fairness, accessibility and end-to-end acceptance for the implemented paths. All 55 browser scenarios passed across the main run and targeted reruns, not one uninterrupted green run; external and unsupported media paths remain open.
- [x] Add website-chat retention, recoverable deletion, administrator erasure, legal hold and durable file-cleanup retry.
- [x] Fetch signed SMS/WhatsApp incoming attachments into private native tenant-library files, with type/size validation, authorized downloads and retryable staging cleanup. See [incoming media](phase-8-incoming-media.md); real provider/Cloudflare acceptance is separate.
- [x] Route chat/SMS/WhatsApp/Messenger through native Team queues and assigned users, explicit leased availability, capacity checks and background reconsideration. Assignment alone does not claim an agent has replied.

The current combined gate, measured limits and explicit external/media gaps are
tracked in [Phase 8 combined acceptance](phase-8-combined-acceptance.md). Historical
evidence below records earlier checkpoints and is not the current scope checklist.
External-only requirements are tracked in [GitHub #163](https://github.com/NaxoCRM-Team/nexa/issues/163)
and the [deployment acceptance checklist](phase-8-external-acceptance.md).

## Shared Email Workspace

Open **Service -> Shared Inbox** or `/w/<tenant-slug>/NexaConversations`.
Only native emails associated with a group mailbox appear. Personal-only messages do not become shared merely because they belong to the same tenant. Empty inboxes are valid when no group mailbox has received email.

Messages are grouped through native `repliedId` links, never guessed from a shared subject. Historical chains start at their earliest mailbox-bound shared message, even when it has a personal predecessor. Private received messages form a boundary: their bodies, descendants and read receipts cannot be pulled into another shared branch. A later shared message below such a boundary starts a separate branch. Native sent replies inside a shared lineage remain eligible, subject to record ACL; a personal sent original before the first shared message is not promoted or shown.

Discovery does not rewrite mailbox links, message content, ownership, native transport status or historical triage state. The shared anchor and required reply lineage must remain native-readable; access to one descendant does not grant access to a hidden canonical ancestor. Deleted mailboxes, removed membership, foreign-tenant edges and cyclic chains cannot widen visibility. Bounds are 100 mailbox-bound candidates per cursor request, 200 messages per thread and 50 parent hops. Descendant candidates are skipped in favour of their canonical anchor, including across cursor boundaries. Search currently matches readable anchor subject/sender fields; it is not a full-body search.

Assignment writes native `assignedUserId`; no second owner table exists. Triage changes lock the scoped root and compare the native version plus expected status/owner, then update through the native record service and its optimistic-concurrency parameters. An intervening native record edit invalidates the old inbox version even if status and owner did not change. Conflicts return HTTP 409 and the workspace reloads current data without silently retrying the user's mutation. The existing immutable audit records the actor and before/after state without copying message bodies.

Mutation requests must use the canonical conversation ID returned by the thread API. If a former anchor has been regrouped under another shared message, the old ID is rejected with HTTP 409 both before and after locking; it cannot silently update a different conversation. Read requests may still resolve a permitted member to its current anchor.

Unread status is computed from native `email_user` read flags for the current recipient and only shared-lineage messages the user can read. Opening the thread marks those native rows read; **Mark unread** returns to the list without immediately reopening it. No receipt row or unread control is invented for a non-recipient administrator. The **Replied** view requires a readable Sent descendant, not merely another received email; transport status remains native. Discovery remains bounded to 100 mailbox-bound candidates per page and traversal to 200 messages per thread; sustained-load acceptance is still part of the Phase 8 exit gate. Oversized threads stay discoverable and open in native Email; their read/reply flags are unknown, not falsely set to read. Such labelled rows remain discoverable in filtered views until native inspection.

The trusted native import save option marks a new Archived message for receipt processing. Reopening waits for its actual native group-mailbox association: the native Fetcher uses `InboundEmail -> emails`, and `Email -> inboundEmails` is also covered. Import save alone cannot reopen shared work or include a private received message. The scoped message lock, root update, audit and marker consumption prevent duplicate processing; when a caller transaction exists they participate through a savepoint. Receipt bookkeeping changes only the internal marker, not message content or the imported message's revision.

A fresh group-bound imported reply advances the shared anchor's native version even if already Open, preserving ownership and invalidating stale close actions. Its marker is accepted for ten minutes; later historical association consumes an expired marker without reopening old work. A failed transaction leaves processing retryable on a subsequent native association/save event. The native `RecoverConversationReceipts` scheduled job also retries up to 100 actual group-bound pending receipts per active tenant/service run, every minute. It does not require another association event, promote personal mail, or reset the ten-minute freshness window. Independent receipts continue after a failure; the job then reports failure to the native scheduler and unconsumed markers remain retryable. Native cron must be running. External IMAP/SMTP delivery acceptance is not yet claimed. Manual archive edits, sent emails, duplicate processing and a client-set `isBeingImported` attribute do not create a trusted receipt. Existing mailbox transport and native duplicate detection are unchanged.

The context link reuses native Email parent relationships for Contact, Account, Lead, Opportunity and Case; hidden relationships or unreadable related records are omitted. Administrators have a settings icon linking to the existing **Administration -> Group Email Accounts**, not another mailbox/credentials store. Mailbox configuration UI and live sender acceptance have not been newly claimed verified.

Replies use native `email-helper` and the existing compose-email modal, preserving reply headers, native transport, custom rich text/file library and backend communication restrictions. No provider is called directly by the inbox. Actual sending requires an already configured authorized sender; this package does not claim external mailbox delivery acceptance. A small native-compose extension retains the exact Draft revision returned by a failed native send. This allows retry after transport repair without fetching over the editor content or ignoring an intervening edit. The native `SendService` failure payload includes that saved revision; native transport, validation and concurrency checks remain authoritative.

Message bodies are displayed as escaped plain text in this package. Native Email remains available for rich HTML and attachments. Live chat and messaging-channel placeholders are not activated before their APIs and permission contracts exist.

## Database and Verification

Run `scripts/dev/verify-conversations.ps1 -PhpPath <php-executable> -Live` for the sequential native and five-viewport authenticated WAMP checks. Omit `-Live` for rollback-only native checks. The runner restores its opt-in environment variables and does not send external email.

Migration `0086_shared_email_conversation_status.sql` adds `email.conversation_status`; `0087_native_email_concurrency.sql` adds the native `email.version_number` attribute and metadata enables native optimistic concurrency. Migration `0088_shared_email_receipt_processing.sql` adds an internal, read-only import-receipt timestamp so processing can wait for mailbox association and remain idempotent. These migrations do not copy messages, rewrite transport state or alter mailbox credentials. Historical discovery itself needs no data backfill. Run the normal local migration runner, rebuild and update the application timestamp after pulling.

`tests/tenant/TenantConversationTest.php` covers native threading, personal-mail exclusion, assignment, stale writes, transport preservation, current-user audit and tenant/service boundaries using rollback-only data.

`tests/tenant/TenantConversationHistoryTest.php` covers shared historical anchors, private ancestors/branches, native sent replies, cursor boundaries, own-mail ACL, foreign edges, cyclic chains, removed/deleted mailbox membership, receipt replay/expiry and transactional retry. The package runner executes both native suites sequentially.

`tests/browser/live-conversation-workspace.spec.js` uses committed, uniquely named synthetic tenants against WAMP. It exercises authenticated API-backed threads, triage, owner search and responsive/accessibility behavior. Fixture mailboxes are inactive and send no real mail; cleanup preserves immutable audit evidence.

### Local SMTP and IMAP Acceptance

Run `scripts/dev/verify-conversation-mailbox.ps1 -PhpPath <php-executable>` with Docker available and WAMP already running. Select another viewport with `-Project mobile`, `tablet`, `tablet-landscape` or `mobile-landscape`. Run one project at a time; each invocation removes its dedicated test container and recreates empty mailboxes. This is a test runner, not a production mail deployment.

`compose.mailbox-test.yaml` uses the [official GreenMail SMTP/IMAP sandbox](https://greenmail-mail-test.github.io/greenmail/) with pinned image version 2.1.13. Only loopback ports 13025 (SMTP) and 13143 (IMAP) are published. Its deliberately public `local-test-only` credentials belong exclusively to synthetic `conversation.test` mailboxes. Do not reuse them for real accounts or expose these ports publicly. GreenMail captures the test mail locally; it is not an outbound delivery relay or a replacement for Postal.

The runner opts into committed synthetic fixtures, restores environment variables and tears down only this named sandbox. Nexa stays on WAMP. Real mailbox credentials, system sender configuration and the existing Postal containers are not changed. Database cleanup retains immutable audits and suspends those synthetic tenants. Native cron may run as usual; tests also call the native fetch service explicitly for deterministic acceptance.

The browser test sends an SMTP message into the sandbox, receives it through the native group-account IMAP fetcher/importer, then replies through the real authenticated composer and native sender. It checks duplicate-fetch idempotency, identical provider Message-IDs in separate tenants, bad IMAP credentials and recovery, failed SMTP delivery with a retained Draft, retry of that same record, exactly one received reply with native headers, stale-send rejection and reopening after a real fetched follow-up. This does not establish Gmail/Outlook OAuth, public DNS, external inbox delivery or production-provider acceptance.

Phase 8 is not complete until all remaining checklist items pass. Phase 7 public website acceptance and production Postal deployment remain separate external follow-ups.

### Local Evidence: 15 October 2026

- Migration runner skipped migrations 0001-0085 and applied additive migration 0086; WAMP metadata rebuilt and frontend timestamp updated.
- Native conversation suite: 20 passing rollback-only checks, including read-only roles and hidden body fields.
- Authenticated WAMP browser suite: five passing viewport checks (desktop, tablet, tablet landscape, mobile and mobile landscape), including WCAG A/AA checks and cross-tenant/personal-mail rejection.
- Desktop/mobile screenshots inspected; short-screen controls and link contrast corrected during acceptance.
- Repository verification (`scripts/dev/verify.ps1 -Ci`) passed.
- GitHub #131 and Phase 8 umbrella #162 are In Progress on the delivery board in current Sprint 07. Phase and sprint numbering are independent.
- Changes remain local and uncommitted. No real external email was sent or claimed verified.

### Coordination Package Evidence: 16 October 2026

- Applied migration 0087, rebuilt native metadata and refreshed the WAMP frontend timestamp.
- Native conversation suite: 38 rollback-only checks passed, including legacy null-version emails, stale native edits, trusted-import reopening, manual/sent/duplicate safeguards, recipient read state, context permissions and oversized-thread recovery.
- Authenticated browser suite: all five viewports passed, including two concurrent requests with one accepted update and one HTTP 409 conflict, imported follow-up reopening, unread controls and accessibility checks.
- CRM database smoke tests and repository verification passed; JavaScript syntax and whitespace checks passed.
- Corrected the mark-unread/open timing race and preserved the user's last read-state action.
- Short-screen landscape follow-up passed: the right column scrolls independently to the final message, keeping the surrounding page fixed. Final screenshot inspected.
- At this checkpoint, historical root reconciliation, real configured-mailbox acceptance, sustained-load evidence and chat/bot/provider packages were still pending. Historical reconciliation is delivered in the next evidence section; no production delivery or git publication was claimed.

### Historical Reconciliation: 16 October 2026

- Applied additive migration 0088 and rebuilt WAMP metadata for the protected receipt marker and both native mailbox-association hook directions.
- Historical inbox discovery now uses native reply relationships and existing group-mailbox membership without copying messages, promoting personal ancestors or changing old triage state.
- Focused historical suite: 35 rollback-only checks passed, including own-mail ACL, cross-tenant corruption, private branches/read receipts, removed/deleted mailbox membership, cycles, receipt replay/expiry/rollback and stale canonical-ID rejection.
- The existing inbox suite passed 39 checks with the corrected association timing. Expanded authenticated WAMP browser acceptance passed all five viewports with private ancestors and branches present, including two distinct legitimate shared conversations.
- Final combined native rerun passed all 74 checks (39 inbox + 35 historical/privacy) after the canonical-ID guard.
- Repository verification, CRM database smoke tests, JavaScript syntax and whitespace checks passed. Desktop and short-screen screenshots inspected; HTTP 409 notifications in acceptance screenshots are intentional conflict-test results.
- Configured-mailbox receive/reply/recovery acceptance and sustained-load evidence remain open under #131/#162. Live chat, bots and channel providers are still separate pending Phase 8 packages. Work remains local and uncommitted.

### Scheduled Receipt Recovery: 16 October 2026

- Added the native tenant-scoped `RecoverConversationReceipts` job and rebuilt WAMP metadata. Confirmed active per-tenant schedules were provisioned with a one-minute interval; no mailbox credentials or native transport settings changed.
- Both rollback-only conversation suites passed: 79 checks (39 inbox + 40 history/recovery).
- New checks simulate a persisted mailbox association whose receipt hook was interrupted. They verify scheduled recovery without another save/link event, exactly one reopening audit, replay safety, personal-mail exclusion and cross-tenant denial.
- Recovery uses the existing marker from migration 0088; this addition requires a rebuild, not another schema migration.
- Real configured-mailbox receive/send acceptance, sustained-load acceptance and the remaining Phase 8 packages are still pending. No external email delivery or production readiness is claimed.

### Native Mailbox Transport Acceptance: 16 October 2026

- Local SMTP -> native IMAP fetch/import -> authenticated browser reply -> native SMTP -> recipient IMAP acceptance passed on desktop, tablet, tablet landscape, mobile and mobile landscape. Each viewport used a fresh disposable mail sandbox and two synthetic tenants.
- Invalid IMAP credentials are rejected, restoring them resumes fetching, and repeated fetching does not duplicate records. Identical provider Message-IDs produce separate native Email records in separate tenants; foreign thread access is denied.
- A failed SMTP send remains a native Draft. Acceptance exposed and corrected a retry HTTP 409: the failure response now carries the saved Draft revision and a small native-compose adapter retains it without replacing editor content or turning off concurrency.
- Retrying sends the same Draft record once, preserves native In-Reply-To headers and uses the selected authorized group sender. An intervening edit is still protected by the native X-Version-Number contract: the stale send returns HTTP 409 before any delivery and leaves the other edit intact.
- An actual customer follow-up delivered into the sandbox and fetched through native IMAP reopens the closed shared conversation. Screenshots include an intentional HTTP 409 notification from the stale-send acceptance scenario.
- No new migration was needed for the retry fix. WAMP metadata was rebuilt and the frontend timestamp refreshed. Repository verification passed. Real credentials and provider configuration were not changed, and no external email was sent.
- This completes the local configured-mailbox acceptance item, not the Phase 8 exit gate. Sustained-load/fairness, live chat, bots and messaging-provider packages remain pending. Work is local and uncommitted.

### Unified Channel History And Automation: 20 October 2026

- Contact activity history now includes received and submitted WhatsApp messages
  and received/accepted Messenger messages alongside existing SMS, website chat
  and native CRM/email activity. Labels, channel filters and inbox links distinguish
  SMS, WhatsApp and Messenger. Failed or uncertain outbound requests are not
  presented as sent messages.
- History is projected from authoritative channel records, not copied into public
  notes. Native Contact and conversation read/ownership/field permissions apply.
  Unlinking a Contact removes the projection on the next read. Private media stays
  behind the authorized library/download boundary; attachment bytes and provider
  URLs are not copied into activity metadata.
- Messenger offers explicit Link Contact / Change Contact / Unlink Contact actions
  using the native Contact picker and tenant-scoped record checks. Association
  requires edit permissions and a current conversation revision, and is audited.
  Linking a Page visitor does not verify their identity or grant marketing consent.
- Automation exposes **Customer message received** (`conversation.received`). The
  existing background scanner reads committed signed SMS/WhatsApp/Messenger inbound
  receipts and authenticated visitor chat messages. Staff replies, bot messages,
  delivery receipts and unlinked visitors are not enrollment sources. Chat requires
  current unrevoked identity proof and an active issuer.
- The existing publication cutoff, immutable trigger ledger, execution-administrator
  checks, per-record permissions, workflow quotas, fairness, cancellation and action
  policies remain in force. Source metadata contains only identifiers and time, not
  message bodies or media URLs. Receipt IDs are provider-namespaced before hashing
  to fit the existing trigger ledger; replays do not create duplicate enrollments.
- No new schema migration or provider credentials are required. This package does
  not complete shared channel media ingestion, advanced routing, retention, live
  provider acceptance or the overall Phase 8 exit gate.
- Local verification: 537 combined messaging checks, 89 rollback-only chat checks
  and 1,799 workflow runtime checks passed. The workflow suite includes all 37
  advertised trigger/action permission-matrix entries. Focused coverage exercises
  signed Messenger receipt -> real enrollment scanner -> worker completion,
  replay deduplication, publication cutoff, Contact unlink/relink, hidden message
  fields and chat identity-proof revocation. Repository static verification passed.
- Authenticated desktop/mobile acceptance passed on both viewports, including
  Messenger Contact association, the real customer-foundation API and rendered
  channel-filtered customer activity cards. Existing private-image pixel checks,
  suppression review and WCAG AA checks also passed. The test waits for the
  asynchronous foundation response before asserting activity content. Screenshots
  were inspected. External Meta/Twilio delivery was not exercised.

### Automatic Routing And Staff Handoff: 20 October 2026

- Tenant administrators configure routing at `NexaConversations/routing`, also linked
  from conversation inbox headers. Website chat, SMS, WhatsApp and Messenger have
  independent enable switches, native Team agent pools and capacity limits (1-50).
  No parallel staff/team or business-record model was introduced. Native shared-email
  distribution is unchanged.
- Staff explicitly select **Available for conversations** in an inbox. Presence is
  tenant/service/user scoped, renewed every 30 seconds while visible, and expires
  after 90 seconds. Closing an inbox or leaving it in the background cannot leave
  someone permanently available. Expired leases require a fresh explicit choice;
  stale heartbeats cannot reverse Away in another tab.
- The native `RouteConversations` scheduled job runs each minute in its own trusted
  tenant/service context. A shared oldest-attempt queue processes at most 20 requests
  per run across the four channels. Unhandled website chats require an explicit
  visitor/bot handoff; SMS/WhatsApp/Messenger require a verified incoming connection.
- Routing uses least active workload, with longest-unassigned preference for equal
  loads. Workload includes open/pending native chat and messaging conversations;
  closed, deleted and expired website sessions do not consume capacity. Eligible
  agents must be active native staff, in the chosen Team, currently available, and
  authorized by native record, ownership and message-field ACL after assignment.
- A tenant/service admission lock plus locked native conversation/presence rows
  protects capacity and prevents simultaneous workers from double-assigning.
  Routing assigns the native `assignedUser`, creates a native assignment notification
  and records a scoped audit. Repeated scans preserve existing/manual owners.
- When no agent qualifies, the request stays waiting and is reconsidered by later
  job runs. Capacity freed by closing work permits queued assignment. An assigned
  conversation is **not** a guaranteed human connection: the handoff is answered
  only after an actual staff reply. Existing assigned conversations are not silently
  moved when their owner goes Away; supervisors retain native reassignment control.
- Migration `0107_conversation_routing.sql` adds only routing policy/presence and
  internal routing state; it is applied locally and registered in both ownership
  manifests. Rebuild provisioned the native per-tenant schedules. Routing defaults
  disabled; no real tenant rules, agent availability, passwords, provider credentials
  or live sending flags were enabled by implementation.
- Local authenticated desktop/mobile tests passed, including real simultaneous CLI
  workers, one-slot capacity, queue recovery, native owner controls and WCAG AA for
  the settings view. Settings and inbox screenshots were inspected. Rollback-only
  tests also cover Team exclusion, hidden fields, inactivity, lease expiry, stale
  heartbeats, foreign tenants/Teams, manual ownership and expired-session rejection.
  This does not close the overall Phase 8 retention/media/load/provider exit gate.
- Final combined evidence: 562 messaging/routing checks and 89 live-chat checks
  passed; four authenticated desktop/mobile routing/Messenger browser tests passed.
  Repository verification and whitespace checks passed. Idle chat owner controls
  follow automatic assignment during polling without overwriting pending manual
  owner/status edits. Changes remain local and have not been committed or pushed.

## Website Chat Privacy Lifecycle

- Tenant administrators open `NexaConversations/privacy` using the shield link in
  the conversation workspace. Policy is isolated by tenant and service. Default
  retention is 90 days of inactivity for closed or expired chats; administrators
  can choose 1-3650 days. Closing the widget or a conversation is not deletion.
- Native record delete permissions control soft deletion. Visitor capabilities are
  revoked immediately, including native ORM removal. Administrators can restore
  staff history for two calendar months, but restoration never reactivates the old
  visitor token. Deleted chats are searchable in the privacy workspace.
- Permanent erasure requires administrator access, `DELETE` confirmation and a
  reason. It removes message content, identity links and bot state, then removes
  owned files before physically removing the root. Minimal scoped audit and
  hashed replay-prevention receipts remain; message bodies and tokens do not.
- Legal hold pauses new permanent erasure and scheduled cleanup. It cannot undo
  content already erased. Shared Contacts, consent records, tracking identities,
  backups and copies outside Nexa are not deleted by this website-chat operation.
- File cleanup is staged and retryable, with at most 20 owned files per attempt.
  Shared native file copies preserve their underlying object. Storage failures
  leave hidden pending work instead of reporting successful physical erasure.
  Generic CRM cleanup excludes privacy-managed chats and their attachments so it
  cannot bypass legal hold or discard metadata before remote cleanup completes.
- Migration `0108_chat_privacy_lifecycle.sql` is applied locally. The native
  `PurgeChatHistory` job runs hourly at minute 15 in the trusted tenant context.
  Eligibility is filtered before batch limits; due retries rotate through the
  queue. Unrelated CRM retention policies are unchanged.
- Approved website origins receive safe CORS error responses for revoked sessions.
  The widget clears cached/rendered history when it observes revocation and
  invalidates late responses. Already viewed or offline external copies cannot
  be remotely erased.
- Acceptance uses rollback-only fixtures and fake object storage, not live
  Cloudflare R2 deletion. These changes do not claim live-provider or full Phase 8
  acceptance and have not been committed or pushed.
- Verification on 21 October 2026: 37 privacy checks, 89 native chat checks,
  27 bot-definition checks and 562 messaging/routing checks passed. Privacy
  browser acceptance passed on desktop, tablet, tablet landscape, mobile and
  mobile landscape, including WCAG AA checks. The combined 15-scenario browser
  run initially passed 13; two older chat scenarios failed on a five-second
  readiness wait and a refreshing-element screenshot. Explicit readiness waits
  and page-level evidence capture corrected those test issues; targeted desktop
  and mobile reruns passed. All 15 scenarios therefore passed across the run and
  reruns, not in one uninterrupted green run. Repository CI/static verification
  and whitespace checks passed; privacy screenshots were inspected.
