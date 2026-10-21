# Phase 8: Website Live Chat

Status: local package accepted; code is uncommitted and the full Phase 8 exit gate remains open. Nexa remains on WAMP.

## Native Capability Audit

Native Email/InboundEmail handles operational email, not an anonymous website chat transport. Existing manually logged live-chat activities are activity records, not a website connection. This package therefore adds the missing chat storage and widget, without replacing either native email or manual activity logging.

`NexaChatConversation` uses native ORM entities, record access, field ACL, own-record roles, assignment fields and version increments. Messages and widget settings use tenant/service-qualified tables. The existing tenant ownership registry explicitly covers all three new tables. The 133-table historical Espo conversion inventory stays unchanged; the manifest separately records additional tenant-scoped tables.

Migrations 0089 and 0090 create the channel and protect its schema constraints. Native metadata sets `skipRebuild` for this migration-owned table: native ORM/ACL still applies, but rebuild must not remove capability/idempotency constraints. Migration 0090 restores these constraints if an early rebuild compared the new table to generic native metadata. Applied migration checksums are not rewritten. No Contact, Account, Email or real mailbox data is copied.

## Try It Locally

1. Sign in as a tenant administrator and open **Channels -> Live Chat**.
2. Open the settings icon, or `/w/<tenant-slug>/NexaConversations/widgets`.
3. Set the widget name, heading, welcome message, brand color, left/right position, draggable setting and optional privacy-notice URL.
4. Enter approved website origins, one per line. Use `http://localhost` for a local WAMP website, or `http://localhost:8081` if that website uses port 8081. Origins do not include the website path. Non-local websites must use HTTPS.
5. Enable and save the widget, then copy the generated script into a website served from an approved origin. Do not open the website as a `file://` document.
6. Start a visitor conversation. Staff see it in Live Chat, can reply, assign it and change Open/Pending/Closed status. A visitor follow-up reopens closed work. Polling currently runs every five seconds in the widget and six seconds in the staff view; this package does not claim WebSocket transport.

Staff roles need **Live Chat** read/edit access in the existing Roles administration. Own-record permissions operate on native ownership. Hidden message fields and read-only roles are enforced at the API, not just through disabled buttons. Widget configuration is tenant-admin only. Staff search and assignment use native User access checks.

First-page chat discovery refreshes automatically. Candidate and history cursors remain bounded; use **Load more** and **Earlier messages** for additional results. Native message bodies are rendered as text, never inserted as HTML. There are no guest file uploads in this package.

## Identity and Logout

An anonymous message is not proof of a Contact's identity. Public requests cannot supply a Contact ID, owner or trusted evidence. The public API returns no CRM profile or staff identity information.

After a visitor starts/resumes a session, the widget emits `nexa:chat-session` on the website document with `{id, visitorKey}`. It does not expose the bearer token in that event. The website's authenticated backend may use its existing Nexa API credentials to:

1. Establish the existing verified personalization identity proof for that same visitor key through `/Nexa/personalization/identify`, with `{visitorKey, contactId, method: "authenticated_session", reference}`. Map the Contact from the website's authenticated server session; never trust a browser-supplied Contact ID or put Nexa API credentials in the embed.
2. POST `/Nexa/chat` with `{op: "identify", id, visitorKey}` using that same authorized backend user.

The chat adapter references the canonical proof; it does not create another identity database. Issuer, tenant, service, visitor hash, proof expiry/revocation and native Contact access must all match. Existing personalization consent/privacy rules remain in force; chat does not silently grant analytics or marketing consent. Anonymous support remains possible without personalization.

On customer logout/account switch, the website must revoke the existing backend proof and dispatch:

```javascript
document.dispatchEvent(new CustomEvent('nexa:chat-reset'));
```

This clears local chat history/session storage and best-effort ends a known server session. In-flight UI responses from before reset cannot repopulate its history. Website-backend revocation remains required; a browser event alone is not an authenticated logout signal. Visitor capabilities expire after 24 hours even without logout. Staff replies to expired/ended sessions are rejected. A disabled widget or inactive tenant/service denies public access immediately.

Verified Contacts' existing `live_chat`/all-channel Do Not Contact restrictions are enforced at the outbound boundary. Staff history remains visible only under native chat ACL. No marketing consent is inferred from starting a support chat.

## Security and Bounds

- Public API: `POST/OPTIONS /Nexa/public/chat/<public-key>`, with an exact approved Origin. CORS never grants credentials or wildcard access. Staff API: authenticated `POST /Nexa/chat`.
- The widget creates a random 256-bit bearer secret when sending the first message. Only its SHA-256 hash is stored server-side. The bearer travels in `X-Nexa-Chat-Token`, not a URL. Same-tab session storage is used only after initiation; no tracking cookies are created.
- A session is bound to widget, tenant/service, origin, expiry and secret. Knowing the conversation ID or public widget key is insufficient to read it.
- UUID message keys and start keys protect retries. Reusing a key with different content/author fails; replay does not append another message or staff audit. A lost start response can be resumed without resending the message.
- Message limit: 4,000 characters / 16 KB. Request JSON: 20 KB. Up to 100 messages per history response, 1,000 per conversation, 1,000 new sessions per tenant/service/day and 10,000 messages per tenant/service/hour.
- Up to ten widgets per tenant/service and ten origins per widget. The existing persistent public limiter allows 120 requests/minute per widget/fingerprint and ten starts/hour per widget/fingerprint. Fingerprints include remote address and user agent; these are not represented as authenticated user identities.
- Admission is serialized per tenant/service, not across tenants. Maximum-capacity and sustained-load/fairness acceptance remain part of the Phase 8 exit gate.
- Staff replies, assignment/status changes, identity links and widget changes use the existing immutable audit. Raw message bodies and bearer secrets are not copied to audit records.

Chat retention/privacy lifecycle, public HTTPS website/backend acceptance, unified cross-channel customer timeline/automation, advanced routing and third-party messaging adapters remain tracked Phase 8 work. Closing a chat retains its history; this package does not introduce a new permanent-delete UI.

## Verification

### Guided Bot Runtime

`ChatBotDefinition` validates bounded conversational graphs: messages, explicit choices,
staff handoff and completion. It rejects cycles, unreachable steps, invalid targets,
duplicate choices, arbitrary action types and unexpected settings. Stable fingerprints
ignore JSON key and node ordering while preserving meaningful choice order.
`ChatBotTransition` plans deterministic responses and terminal handoff without database
writes or external calls. Run `php tests/tenant/ChatBotDefinitionTest.php` for its checks.

Open **Channels → Live Chat → widget settings**, save or select a widget, then use
**Automated greeting and handoff**. Configure its greeting, question, two choices,
handoff and completion messages; enable it and select **Publish chat flow**. The
guided editor deliberately covers this greeting/handoff flow, not an arbitrary visual
journey builder or an AI assistant. The validated API accepts bounded message/choice/
handoff/end graphs. CRM actions continue to belong to the existing automation engine;
this channel runtime does not introduce another CRM action executor.

Migration 0092 adds private conversation snapshots and choice receipts. A new enabled
conversation receives the published definition once. Later publication affects future
conversations, not an active snapshot. Configuration updates require tenant admin access
and the widget's current version. Each new choice requires the authorized session, the
current server-side waiting step, an integer option and a UUID retry key. Reusing a key
for another answer fails. Successful retries return current history without rerunning
the transition. Choice receipts are bounded to 50; messages retain the existing hourly
and per-conversation quotas. Unknown actions, arbitrary owner assignment and graph loops
are not supported. Messages are rendered as text with an explicit **Automated assistant**
label, never as a real staff identity.

Service admission and conversation row locks serialize choice writes. Staff replies,
explicit owner assignment and closing a conversation stop waiting automation. Visitor
staff requests stop it through the same handoff path. Disabling the bot stops a waiting
conversation on its next read/choice request; re-enabling does not restart that stopped
conversation. Backend-verified Contacts' live-chat Do Not Contact restrictions also
block new automated choice responses. Anonymous support does not grant marketing consent.
Bot handoff records a request, not guaranteed staff availability or automatic routing.
Revision-aware widget controls ignore stale bot state responses. Browser logout/reset
clears choices alongside chat history and ends the visitor capability.
The visitor's selected server-defined label is stored as a visitor message so staff
can follow the question/answer history. Retry receipts prevent duplicate answers too.

Local bot-runtime evidence, 16 October 2026:

- Migration 0092 applied through the checksum runner; native metadata rebuilt.
- 67 rollback-only chat checks and 27 definition/transition checks passed, including
  bot publication permissions, immutable active snapshots, start/choice retries,
  conflicting choices, human takeover, disabling and verified Contact restrictions.
- A clean browser pass completed all ten ordinary-chat and bot-assisted-chat cases
  across desktop, tablet, both landscape layouts and mobile. The bot case publishes
  through the guided editor, loses a real choice response, recovers through polling,
  verifies the transcript and staff request, and receives a real authorized staff reply.
  WCAG checks passed after correcting the choice group's accessible role.
- Existing native email regression suites passed 79 checks. Desktop/mobile bot
  screenshots inspected. These are local WAMP/cross-origin fixture results, not public
  HTTPS, external messaging-provider or full Phase 8 production acceptance.
- Work remains local and uncommitted; no GitHub publication is claimed.

### Visitor Staff Requests

After starting a conversation, visitors can select **Request staff help**. The request
is stored independently of native triage status and assignment, using migration 0091.
It appears in the staff conversation list and thread, and remains visible across reloads.
The existing bearer/origin/expiry and tenant/service checks apply. Public requests cannot
select an owner or mark themselves answered. A successful, authorized, nonduplicate staff
reply records the answered timestamp in the same transaction as its message and audit.
Status changes and visitor follow-up messages do not clear the request. Replays preserve
the original timestamps and do not increment the native revision. One request is retained
per chat session, so a delayed retry cannot reopen an already answered request.
The first request also writes one immutable `chat.handoff_requested` audit with a
visitor actor and no staff user ID; retries do not duplicate it.

This request does not promise online-agent availability, response time or routing.
Bot-triggered handoff uses the same request path; automatic queue routing remains pending.
Native assignment permissions continue to control
which staff can change the owner.

Local handoff acceptance on 16 October 2026: migration 0091 applied, native rebuild
and asset timestamp updated; 49 rollback-only chat checks and 27 bot-definition/
transition checks passed. Browser handoff request/reply acceptance passed across all
five viewports, including WCAG checks. The first run passed desktop, tablet and mobile;
tablet-landscape was interrupted by the development asset-update prompt and mobile-
landscape timed out awaiting widget save. Both landscape cases passed on a separate
rerun after the update completed. Repository verification and diff whitespace checks
passed. Automatic bot execution/publication is still not connected. Nothing was
committed or pushed.

Run `scripts/dev/verify-live-chat.ps1 -PhpPath <php-executable> -Live` for rollback-only native checks followed by authenticated WAMP browser checks. Omit `-Live` for native checks only.

Browser tests use synthetic tenants and an intercepted local website document at `http://127.0.0.1:8099`; the widget script, CORS/API requests, persistence and staff replies are real WAMP traffic. Chromium's local-network permission is explicitly granted only to that synthetic website, using the [supported Playwright permission API](https://playwright.dev/docs/api/class-browsercontext#browser-context-grant-permissions). Browser security and Nexa's origin checks are not globally disabled. This is local cross-origin acceptance, not a public website deployment claim.

The runner exercises lost-response retry, reload/resume, safe text rendering, real staff replies, native-role/tenant boundaries, forbidden public identity input, draggable/keyboard controls, responsive panel bounds, logout reset and WCAG checks on staff and visitor surfaces. Committed fixtures are cleaned without deleting immutable audit evidence.

### Local Evidence: 16 October 2026

- Migrations 0089/0090 applied through the checksum runner. Native rebuild and frontend timestamp update completed; verified the tenant foreign key and unique start/retry indexes survive rebuild.
- Native chat suite: 40 rollback-only checks passed, including central foreign-entity write rejection, anonymous actor attribution, native own/read-only/field permissions, origin/token boundaries, duplicate prevention, proof revocation/expiry and live-chat Do Not Contact enforcement.
- Authenticated browser acceptance passed all five viewports, with WCAG A/AA checks on both staff and shadow-DOM visitor surfaces. Corrected timestamp contrast and keyboard access to scrollable message regions during acceptance.
- Additional desktop/mobile passes verified actual pointer dragging as well as keyboard movement. Session reset clears local history and makes the old bearer unable to read server history.
- Existing shared-email regression suites passed 79 checks (39 inbox + 40 historical/recovery). CRM database smoke tests and repository verification passed. No real account credentials or mailbox settings were changed.
- Desktop/mobile screenshots inspected. Deliberate forbidden native/cross-tenant requests can leave a temporary Not found toast in staff acceptance screenshots; the chat exchange itself succeeds.
- No public HTTPS website, external customer-backend deployment, sustained-load/capacity acceptance or overall Phase 8 completion is claimed. Work remains local and uncommitted.

### Verified Chat in Contact Activities

The existing Contact Activities tab now includes a **Live chat** filter and
expandable, searchable visitor messages, staff replies and distinctly labelled bot
messages. These are reads of the existing chat message rows, not duplicate Notes
or copied timeline records. Message content remains escaped plain text; authoritative
chat messages cannot be edited or deleted from these cards.

An existing, current backend-verified visitor proof must bind the conversation to
that Contact. Issuing proof still uses the existing website-consent gate; a browser
cannot attach an arbitrary Contact ID through the public widget. Anonymous chats do
not appear in a guessed Contact timeline. Proof expiry, revocation, issuer
deactivation or identity mismatch removes the association from subsequent snapshots,
without deleting the original chat. This follows the current identity-proof policy,
not a new permanent Contact-link policy.

Native Contact read, Chat record ownership and message-field permissions apply.
Staff names additionally require native User read/name access; otherwise the reply
is attributed generically to a staff member. Staff adapters recheck current active
user, tenant and service state. Queries are explicitly scoped and bounded to 200
message candidates. Account timelines are not broadened by this change.

Migration `0100` adds only an index on the existing native chat conversation table.
It changes no customer fields, proof lifetime, message content or mailbox settings.
Public HTTPS/customer-backend deployment and overall Phase 8 acceptance remain open.

### Contact Timeline Acceptance: 18 October 2026

- Migration `0100` was applied by the checksum-ledger runner. Earlier migrations
  were skipped unchanged; only the existing conversation index was added.
- Native acceptance passed 27 bot-definition/transition and 87 rollback-only chat
  checks. New coverage includes anonymous exclusion, bounds, native Contact and
  Chat ownership, hidden Contact/message fields, hidden staff identities, deleted
  chat roots, proof expiry/revocation, inactive issuer/current user and service scope.
- Existing SMS acceptance passed all 253 checks, and customer foundation runtime
  isolation tests passed. Combined chat/SMS checks total 367, without provider calls.
- Eight desktop/mobile browser tests passed: four focused chat/SMS rendering tests
  and four authenticated bot/widget/staff/Contact workflows. These verify search,
  channel filters, bot/staff attribution, escaped HTML-like text, read-only cards,
  successful consent-gated identity issuance and revocation. Initial missing-consent
  fixture failures were corrected by exercising the existing consent API, without
  weakening the application gate. Existing responsive/widget/WCAG checks remain in
  the authenticated flows. Desktop/mobile Contact card screenshots were inspected.
- Changes remain local, uncommitted and unpushed. No real customer identity was
  asserted, mailbox settings changed, external message sent, or public HTTPS
  production acceptance claimed.
- Repository verification, JavaScript syntax and whitespace checks passed. No
  synthetic acceptance tenant created today remains active after fixture cleanup.
