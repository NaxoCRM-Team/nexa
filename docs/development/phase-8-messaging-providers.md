# Phase 8 Messaging Providers

Status: outbound SMS composer, encrypted queue, dispatch worker, delivery tracking,
bounded retries, uncertain-send recovery and tenant quotas are implemented locally,
alongside signed inbound receipts and native conversation/Contact integration.
Real sending remains deployment-gated. Public provider acceptance is still pending.

Current acceptance and release status is recorded in [the combined gate](phase-8-combined-acceptance.md).
Earlier dated checkpoints below are historical; their references to uncommitted
work or incomplete opt-in/dispatch packages do not override the current checklist.
Live-only follow-ups are tracked in [GitHub #163](https://github.com/NaxoCRM-Team/nexa/issues/163).

## WhatsApp Operations

The local two-way text workflow now extends the existing signed callbacks,
native messaging conversations, encrypted outbox, attempt ledger and dispatch
worker. Open **Channels > WhatsApp**, or
`http://localhost/nexa/w/isolation-alpha/NexaConversations/whatsapp`.
Real provider acceptance remains separate from local fake-provider tests.

- Save a WhatsApp connection with its own Twilio account/token and
  `whatsapp:+<country-code-and-number>` sender. Select the saved connection, enter
  its `XE...` Sender SID, then choose **Verify WhatsApp sender**.
- Verification makes bounded, fixed-host read-only requests to the active account
  and the v2 Sender endpoint. Only exact `sender_id`, `XE` SID and `ONLINE` status
  are accepted. SMS `PN` proof cannot verify WhatsApp.
- Migration `0102` retains sender exclusivity and adds channel-specific proof
  constraints. It changes no customer records and enables no dispatch.
- Verified WhatsApp connections expose **Check template approval** for `HX...`
  Content SIDs. Approval and Content records must belong to the connected account.
  Current inspection supports approved UTILITY/MARKETING text templates; other
  categories and rich templates fail closed rather than become unrestricted text.
  Inspection registers the approved template in this connection's catalogue
  (maximum 50). Staff select those templates in the conversation composer.
- The pure policy validates exact variable keys, bounded single-line values and
  rendered text. The session policy requires trusted provider inbound time, rejects
  future/absent evidence and closes one minute before the 24-hour boundary. This
  policy is enforced at preparation, held-draft release and dispatch. Receipt
  arrival time alone must never reopen a session after a delayed callback.
- `WhatsAppSessionLookup` retrieves the exact provider message from a fixed account
  path and verifies account, message ID, inbound direction, received status, sender,
  peer and strict provider creation time. Old delayed callbacks cannot extend the
  service window. Preparation and dispatch inspect up to three recent incoming
  receipt identities; no positive proof means no free-text submission. A valid
  recent message outside that bounded candidate set can require operator review;
  the system fails closed instead of extending the window.
- Free text requires a customer-service purpose. UTILITY templates require a
  customer-service purpose; MARKETING templates require current marketing consent
  and the native marketing Contact status. All sends require a readable explicitly
  linked Contact with a valid matching primary number. Linking is not consent.
- The worker rechecks the original actor's current permissions, purpose, consent
  event, DNC, signed opt-out history, connection revision and provider session or
  template approval. A changed template cannot silently change a queued message.
- `nexaWhatsAppDispatchEnabled` is a separate deployment gate. The shared
  connection activation operation selects the gate by its server-owned channel;
  SMS approval cannot enable WhatsApp. Each connection additionally needs tenant
  activation and sender proof no older than 24 hours.
- Public callbacks use the existing canonical HTTPS messaging base URL and
  per-connection signature/identity checks. Arrival time is not session evidence.
  Native receipt recovery projects incoming messages; no automatic replies occur.
- Migration `0104` adds only a template catalogue column and JSON constraint to
  the existing connection table. No competing conversation, outbox or attempt
  table was introduced. Payload encryption retains the shared outbox context.
- The native `DispatchWhatsApp` scheduled job runs every minute with bounded
  candidates and at most two POST attempts per workspace run. SMS and WhatsApp
  share the persisted workspace attempt allowance (default five/minute and
  1,000/UTC day); one tenant cannot consume another tenant's allowance.
- Definite rate-limit rejection permits bounded delayed retry. Timeouts and
  ambiguous results become uncertain, never automatic resend. Exact-attempt
  signed callbacks can resolve uncertainty, including WhatsApp read status;
  known provider IDs also receive read-only status inspection. An unknown ID is
  never recovered by guessing phone/body matches.
- Provider lookup outages wait with backoff before any POST. Worker interruption
  becomes uncertain. Cancel applies only before submission, not a false recall.
- Signed STOP blocks all replies/templates. START does not release suppression.
  A separate governed WhatsApp opt-in release UI is not claimed in this package.
  Incoming media is represented by attachment metadata only; sending remains
  text/template-only. Real dispatch settings and credentials were not changed.

References: [Twilio Senders API v2](https://www.twilio.com/docs/whatsapp/api/senders),
[Content and approval API](https://www.twilio.com/docs/content/content-api-resources),
and [customer-service windows](https://www.twilio.com/docs/whatsapp/api).

### Two-Way Local Acceptance: 20 October 2026

- Migration `0104` applied through the checksum-ledger runner; previous migrations
  were skipped unchanged. WAMP metadata rebuilt and the asset timestamp refreshed.
- Combined messaging regression passed 485 checks, including 52 WhatsApp provider
  contracts and 34 rollback-only WhatsApp dispatch checks. Coverage includes signed
  incoming projection, separate channel APIs, tenant boundaries, encrypted drafts,
  exact template variables/category, fresh session proof, consent withdrawal, DNC,
  STOP, paused/changed templates, rate-limit backoff, read callbacks, uncertainty,
  interrupted-worker recovery, stale claims/proof and independent tenant quotas.
- Four authenticated desktop/mobile browser checks passed for settings and inbox:
  registered template selection and variable inputs, escaped incoming text,
  fake-provider accepted outgoing messages, real authenticated draft cancellation,
  cross-tenant API rejection, disabled real sending and WCAG AA checks.
- Screenshots were inspected. Browser sending itself was not enabled: outgoing
  acceptance is seeded through the actual CLI service/worker with fake provider
  clients. This does not establish browser-to-real-Twilio delivery.
- The native WhatsApp worker is registered Active every minute. The application
  dispatch gate remains disabled. Current-run synthetic tenants were cleaned up;
  older unrelated fixtures were left untouched.
- Repository verification and whitespace checks passed. Changes remain local,
  uncommitted and unpushed. No external messages or credential changes occurred.

### Earlier Sender-Proof Acceptance

The earlier combined messaging runner passed 383 checks, including 45
fake-provider/session/template checks and 49 rollback-only connection checks,
alongside the existing callback and SMS dispatch suites.
Authenticated desktop/mobile settings tests passed, including verified WhatsApp
labels, XE proof input, approval-inspection controls, no SMS activation control and
WCAG AA checks. Fake proof is confined to explicitly opted-in synthetic fixture
tenants. A first browser run used an incorrect label locator for the channel select;
the corrected accessible-combobox rerun passed. Migration `0102` was applied through
the checksum-ledger runner; earlier migrations were skipped unchanged. Repository
verification passed. No actual provider messages or credentials were changed.
Run committed browser fixtures separately from rollback-only database suites: a
concurrent rerun hit fixture setup lock contention, not provider delivery. The
final UI rerun is sequential. This WhatsApp increment remains local and uncommitted.

## Outbound SMS Operations

### Consent at Dispatch and Opt-In Review

The existing dispatcher rechecks active purpose/channel/policy version, effective
and expiry times, the canonical nonvoid consent source event, evidence and legal
basis, Contact marketing eligibility, primary phone, DNC and provider suppression.
It also rejects queued messages whose source consent event changed after preparation.

An administrator can now review opt-in in the SMS conversation. Release requires:

- A verified signed STOP and a later verified signed START for the same connection
  and recipient, with historical preference recovery complete.
- A new explicit `granted` / `FreelyGivenConsent` decision recorded after START for
  the selected SMS purpose; contract exceptions do not release suppression.
- An active administrator with native conversation edit and Contact/field access.
- A valid matching Contact phone, no DNC/phone opt-out and a meaningful review reason.

The UI shows eligible purposes and a **Release suppression** action. Record renewed
consent through the existing consent workspace first. Same-second or otherwise
ambiguous STOP/START/consent ordering fails closed; obtain clearly later evidence.
An approval is specific to tenant, service, sender connection, recipient, Contact,
purpose, current consent event and the exact STOP/START evidence snapshot.

Release is appended to the existing immutable `nexa_audit_event` ledger, with actor,
time and reason. No new suppression table or migration is needed. It does not edit
consent, remove Contact DNC, clear native phone opt-out or enable deployment sending.
Repeated approval is idempotent. A new STOP, newer START, consent replacement,
withdrawal/expiry or a policy/restriction change blocks the affected send again.
Blocked/expired old messages are never revived; prepare a new intent after review.
Historical STOP receipts remain visible and immutable approval evidence is retained.

Consent-review acceptance (18 October 2026): combined messaging acceptance passed
331 checks, including 53 rollback-only dispatch/review checks. Coverage includes
administrator versus editable-staff permissions, tenant isolation, short reasons,
DNC protection, fresh versus old consent, per-purpose release, stale snapshots,
replacement consent, stale held drafts and renewed STOP before dispatch. Independent
worker quota/fairness acceptance passed with the new consent-state locking. Repository
verification passed. Authenticated desktop/mobile review and WCAG AA checks passed;
the existing composer/dispatch desktop/mobile regressions also passed. An initial
empty-history ARIA failure was fixed, and a login-navigation timeout was rerun with
an explicit successful authentication-response assertion. All tests used synthetic
data and fake transport. No real credentials, activation settings or SQL schema were
changed for this consent-review increment. Changes remain local and uncommitted.

Migration `0101` extends the existing outbox and adds a tenant/service-owned attempt
ledger. Legacy held rows cannot be released by the new composer. No duplicate
Contact, conversation or consent model is introduced.

The SMS conversation composer requires a readable linked Contact with a matching
primary phone, native record/field permissions, a current purpose-specific consent
decision and no channel restriction or provider STOP suppression. These checks are
repeated immediately before submission, including actor/workspace activation.
New message bodies and recipients are encrypted at rest. Browser-supplied recipients
are never accepted. Drafts can be cancelled or released with revision checks.

Deployment activation requires all of:

- `nexaSmsDispatchEnabled` set to true by the deployment operator (currently off).
- `nexaMessagingPublicBaseUrl` set to the canonical public HTTPS application URL.
- A verified tenant SMS connection with sender proof refreshed within 24 hours.
- Explicit tenant administrator activation in Messaging connections.
- The native `DispatchSms` scheduled job and normal CRM cron processing.

Connection revisions invalidate queued intents prepared against older settings.
Refreshing sender proof is an operational requirement; expired proof fails closed.
No actual credentials or deployment configuration were changed for this package.

Each worker run submits at most two messages per workspace and performs at most
one read-only inspection. Serialized claims and a durable attempt ledger enforce
`nexaSmsAttemptsPerMinute` (default 5, maximum 60) and `nexaSmsAttemptsPerDay`
(default 1000, maximum 10000). These count API attempts, not billable SMS segments.
Preparations and active queue capacity are separately limited to 1000 per workspace;
queued messages expire after 24 hours. One throttled workspace does not consume
another workspace's allowance.

Only definite HTTP 429 rejection is automatically retried, with persisted backoff
and at most three attempts. Timeouts, ambiguous responses and interrupted submission
leases become uncertain: they are never blindly resent. A signed callback bound to
the exact attempt can recover a lost response. Known provider message IDs also receive
read-only status inspection. Unknown outcomes without an ID or callback require
operator investigation; no unsafe matching by message text, recipient or timestamp.
Cancellation cannot recall an already submitting or accepted message.

Verification commands (local fake transport only):

```powershell
powershell -File scripts/dev/verify-messaging-providers.ps1 -Database -PhpPath C:\wamp64\bin\php\php8.2.29\php.exe
$env:NEXA_PHP_PATH='C:\wamp64\bin\php\php8.2.29\php.exe'
node tests/tenant/sms-dispatch-concurrency.cjs
```

Authenticated browser acceptance is in `tests/browser/live-sms-dispatch.spec.js`.
Live public HTTPS callbacks, actual external SMS delivery and provider billing
acceptance remain external requirements, not claims made by local fake tests.
The sections below retain earlier delivery evidence and describe the inbound foundation.

### Outbound Acceptance: 18 October 2026

- Migration `0101` applied through the checksum-ledger runner; native rebuild and
  application timestamp update completed. Native `DispatchSms` jobs are registered.
- Combined provider acceptance passed 311 checks (22 webhook forms, 56 contracts,
  14 sender lookups, 25 HTTP transport, 119 callback, 42 connection and 33 dispatch).
- Dispatch coverage includes encryption, idempotency, foreign-tenant denial,
  recipient injection, stale claims, bounded safe retry, interrupted leases,
  late callbacks, delivery regression, DNC changes and actor deactivation.
- Cancellation during submission is rejected; an early signed delivery callback
  is not overwritten by a later queued creation response.
- Two independent native PHP worker processes passed shared-quota serialization
  without duplicate submissions. A second tenant progressed while the first was
  throttled. Tests use synthetic tenants and fake transport, then clean up fixtures.
- Authenticated browser acceptance passed all five desktop/tablet/mobile viewports,
  including draft/cancel, API preparation, native fake-worker dispatch, delivery
  polling, Contact timeline projection, HTML escaping and WCAG AA checks.
- Desktop and mobile screenshots were inspected. A timestamp contrast failure
  found during the first run was fixed before the complete five-viewport pass.
- No real SMS was sent. Real credentials and deployment activation were unchanged.
  This work remains local, uncommitted and unpushed.
- Repository verification and whitespace checks passed; no active acceptance
  fixture tenants created today remain after cleanup.

## Implemented Locally

- A shared form-webhook signature helper accepts an explicitly supplied connection
  secret. Existing Twilio Voice verification delegates to it without changing the
  Voice credential lookup or URL configuration.
- The Twilio SMS/WhatsApp adapter builds text-send request payloads for a fixed
  Twilio API host. It returns no authentication material and makes no network calls.
- Signed incoming and status callbacks are normalized only after account and sender
  matching. The caller must supply the expected connection account, channel, sender
  and exact canonical callback URL from trusted server configuration, not payloads
  or forwarded Host headers. A matching account alone is not tenant authorization.
- SMS uses E.164 phone addresses; WhatsApp requires `whatsapp:` addresses. Incoming
  text and up to ten media references are supported. Media references must match the
  provider account/message path and approved Twilio API hosts. Nothing is downloaded.
- Delivery reconciliation ignores duplicate/regressive callbacks and preserves
  stronger delivery/read evidence. Positive delivered/read evidence may supersede
  a prior failure. This is Nexa's reconciliation policy, not a claim that callbacks
  arrive in order. Durable storage must still retain every accepted receipt and
  surface conflicting evidence for inspection rather than discard its audit trail.

The signature implementation preserves the existing project's form-HMAC behavior
and is checked against Twilio's published illustrative vector. All supplied form
fields, including newly introduced provider fields, participate unchanged. This
helper supports flat string form fields only, not JSON/bodySHA256 or multivalued
parameters. Unsupported input fails closed. The HTTP boundary uses the strict
raw-form parser described below, rejecting duplicate/ambiguous keys before verification.
Twilio recommends its SDK validator; when a managed SDK dependency is introduced,
retain the pinned vector and adapter tests while replacing this shared primitive.

No real account credentials, Voice settings or customer data were changed. The
illustrative auth token in the tests is published example data, not a live secret.

## Tenant Settings and Held Staging

Open **Channels → Live Chat → widget settings → Messaging connections**, or
`/w/<tenant-slug>/NexaConversations/messaging`, as a tenant administrator. Configure
the Twilio account SID, SMS/WhatsApp sender and auth token. Existing connection tokens
are never filled back into the browser. Leave the token blank to retain it; enter a
new token to replace it. Connections can be disabled. SMS verification is provider-backed;
clients cannot directly set a verified status or activate dispatch. Account/channel/sender identity is immutable after
creation; use a separate connection for a different sender.

Migrations 0093/0094 add required tenant/service-owned settings and held outbox tables,
registered in the existing ownership manifest/runtime. The existing deployment-owned
`ProviderSecretCipher` encrypts tokens with tenant, service and connection ID as
authenticated context. Listing/save responses and immutable audits omit both plaintext
tokens and ciphertext. Optimistic versions reject stale edits. Configuration is capped
at twenty connections per tenant/service. Unverified configurations do not reserve a
sender globally: proving exclusive sender ownership is still required before activation.

The internal `MessagingConnectionService::stage` method accepts a native Contact,
resolves its phone number under native record/field access, checks channel restrictions,
known provider opt-outs and historical preference-recovery completeness,
and writes an idempotent held intent with its actor and connection revision. It rejects
foreign Contacts/connections, disabled connections, invalid channel addresses, changed
request-key intents and more than 1,000 held messages per tenant/service. No recipient
address is accepted from a browser payload. The public settings API exposes only list
and save, plus the read-only SMS verification command; there is no public staging,
activation or sending operation.

### SMS Sender Verification

For a saved, enabled SMS connection, select **Verify SMS sender**. This makes read-only
HTTPS GET requests to Twilio's account and IncomingPhoneNumbers resources using that
connection's decrypted credentials. The account must match and be active; the exact
E.164 number must belong to it and have a Boolean SMS capability. The lookup does not
send an SMS, buy a number, change callbacks or enable dispatch. Trial restrictions,
regulatory requirements and actual deliverability are not established by this check.

Migration 0095 adds the last successful check time, provider phone-number SID and an
exclusive verified-sender constraint. Unverified configurations still cannot reserve
global ownership. If another tenant already holds verified ownership, verification
fails without exposing its identity; transfers require platform-operator review.
Provider requests run outside the write transaction. The current connection revision
and active tenant administrator are checked again before accepting proof, preventing
a token change or disable operation from being overwritten by a late result.

Name-only edits retain unchanged proof. Token replacement or disabling clears it.
A definitive provider rejection on recheck revokes matching old proof; a transport
timeout does not create new proof or refresh the previous check time. Verification
is point-in-time ownership evidence, not permanent availability or dispatch permission.
Future dispatch must enforce proof freshness and current provider/tenant status.

SMS transport targets are fixed to `api.twilio.com`; HTTPS certificate validation stays
enabled, redirects are refused, and connection/total timeouts and response sizes are
bounded. Provider error bodies and auth tokens are not returned to the browser or
copied into audits. WhatsApp now has a separate v2 verification button and proof;
an SMS-capable number still does not prove WhatsApp approval.

Legacy held staging is not runnable. The current SMS composer/worker uses the
encrypted queue described above; WhatsApp has no runnable dispatch yet. Saving
settings or legacy staging never sends a message or proves consent.

### SMS Opt-Out Admission

Migration `0099` adds indexed preference metadata to existing encrypted receipts;
there is no separate Contact identity or consent store. After signature/account/sender
validation, SMS `OptOutType` values STOP, START and HELP are retained. Invalid values
fail closed. Without that field, exact standard English opt-out keywords are treated
conservatively as STOP; ordinary messages containing a keyword are not. See
[Twilio Advanced Opt-Out](https://www.twilio.com/docs/messaging/tutorials/advanced-opt-out)
and [Twilio's opt-out keyword reference](https://www.twilio.com/docs/api/errors/30630).

A STOP blocks preparation immediately, even before conversation projection or
Contact association. The boundary is tenant/service/connection/recipient scoped.
Native sender uniqueness and receipt foreign keys prevent replacing settings to
discard their suppression history. Name/token edits do not remove receipts.
The SMS thread displays an opt-out warning only after native message read access.

START is recorded as review evidence, not automatic permission to send. Callback
arrival order is not authoritative provider event order, so it cannot automatically
clear a previous STOP, Nexa purpose consent or do-not-contact. HELP does not change
preferences. No confirmation SMS is generated by Nexa. An approved opt-in
review is required before suppression release; the implemented administrator review
is described above. External provider-side suppression still remains authoritative.

The existing per-tenant recovery job indexes up to 100 historical receipts per run.
Unreadable entries retain a generic failure and bounded retry backoff; other entries
continue. Preparation fails closed while any inbound preference remains unindexed
for that connection. No provider queries or media downloads occur during indexing.
Historical payloads that predate retention of `OptOutType` cannot reconstruct lost
localized provider metadata; provider-side reconciliation remains a dispatch gate.

This is a known-opt-out admission boundary, not delivery activation or a claim that
all external provider suppression has been synchronized. Existing held messages
remain held. A future dispatcher must recheck current consent, suppression and
provider state instead of trusting the original staging snapshot.

## Still Required

- Live SMS/WhatsApp acceptance and sender proof freshness/transfer operations.
  The local WhatsApp conversation/composer/worker package is implemented; real
  provider delivery is not claimed. Each tenant owns its messaging connection,
  not the platform Voice account.
- Media ingestion through the tenant library with size/type/privacy controls and
  safe downloads. Remote content types are declarations, not verified file types.
- Messenger live provider/CDN acceptance. Local delivery/read projection, private
  media and governed opt-out release are implemented alongside signed replies; see
  [Messenger scope and acceptance](phase-8-messenger.md).
- Remaining channel timelines, automation events, live HTTPS acceptance and Phase 8 exit gate.

Alphanumeric SMS senders, outgoing media and WhatsApp template payloads are not yet
supported by this initial text-request builder. They must not silently fall back to
another sender or an unrestricted text message.

## Verification

Run:

```powershell
powershell -ExecutionPolicy Bypass -File scripts/dev/verify-messaging-providers.ps1 -PhpPath C:\wamp64\bin\php\php8.2.29\php.exe
```

The contract suite has 46 checks, including the published signature vector, Voice
delegation, invalid signatures, altered URLs/parameters, wrong account/sender,
channel mismatches, media references, form encoding and out-of-order statuses.
The contract tests use no network calls or database writes. Add `-Database` for
rollback-only encrypted connection and held-staging checks; add `-Live` for those
checks plus authenticated local browser settings acceptance. Live tests use temporary
tenants and random non-working provider tokens, and make no provider requests. The
existing deployment encryption key must be configured for the browser/API path.
Live delivery and public callback acceptance remain pending.

### Local Evidence: 16 October 2026

- Migrations 0093/0094 applied through the checksum runner; authenticated settings
  routes rebuilt and frontend assets refreshed on WAMP.
- 46 provider contract checks and 23 rollback-only connection/staging checks passed.
  Coverage includes tenant/service/connection-bound encryption, secret-free responses
  and audits, native Contact access, suppression, disabled connections, stale edits
  and held-intent replay/conflicts.
- Authenticated settings acceptance passed across five screen sizes, including save,
  rename without secret replacement, disable, foreign-tenant denial and WCAG checks.
  Read-only contrast was corrected during testing. The final four non-desktop cases
  passed together; desktop passed separately after an unexpected worker-process exit.
- Desktop/mobile screenshots inspected. No active synthetic messaging connections
  from this test were left after acceptance. Repository verification and whitespace
  checks passed. Work is local and uncommitted; no provider calls or live sends occurred.

### SMS Verification Evidence: 17 October 2026

- Migration 0095 applied via the checksum runner. Verified sender claims are exclusive;
  unverified connections remain tenant-scoped and do not reserve global ownership.
- 46 provider-contract checks, 14 fake-transport sender-lookup checks and 42 rollback-only
  connection checks passed. These cover account/number capability matching, proof
  conflicts, revision races, token invalidation, provider rejection, administrator
  deactivation and tenant suspension during lookup. No test contacted Twilio.
- Settings browser acceptance passed all five screen sizes. Verification controls are
  visible for eligible SMS connections and absent when disabled; the tests do not click
  them with synthetic credentials. A further authenticated desktop pass checked the
  final tenant/administrator guards. Successful proof persistence is native fake-provider
  acceptance, not a claim of live third-party verification through the browser.
- Repository verification and whitespace checks passed. Dispatch is still disabled,
  all staged records remain held, and WhatsApp verification/public callbacks/live sending
  are still pending. Changes remain local and uncommitted.

## Provider References

- [Twilio request signature security](https://www.twilio.com/docs/usage/security)
- [Incoming messaging webhook fields](https://www.twilio.com/docs/messaging/guides/webhook-request)
- [Message resource and delivery statuses](https://www.twilio.com/docs/messaging/api/message-resource)
- [IncomingPhoneNumber ownership and capabilities](https://www.twilio.com/docs/phone-numbers/api/incomingphonenumber-resource)
- [Provider account status](https://www.twilio.com/docs/iam/api/account)
# Callback Parsing Hardening (17 October 2026)

`TwilioWebhookForm` parses raw URL-encoded callback bodies without PHP field-name
normalization. It rejects duplicate decoded names, bracket/dot/space aliases,
malformed escapes, invalid UTF-8, NUL values, unsupported content types and
oversized bodies/field sets. Signed values retain their whitespace and literal
plus signs; unknown simple provider fields are preserved for signature checking.
The provider verification runner includes its standalone regression suite.

## Durable SMS Callback Ingress (17 October 2026)

Migration `0096` adds tenant/service-owned encrypted callback receipts with a
composite connection foreign key. The POST-only public route is
`/api/v1/Nexa/public/messaging/<connection-id>/<inbound|status>`.

Deployment administrators must configure `nexaMessagingPublicBaseUrl` to the
canonical HTTPS application root (including any installation subfolder). It is
not inferred from request Host, forwarded headers, cookies or tenant input. Without
this configuration callbacks fail closed. No real deployment configuration has
been changed, and the local HTTP WAMP URL is not a public provider endpoint.

Only active tenant/service scopes with a verified SMS connection accept receipts.
The exact canonical URL, connection secret, provider account and sender are checked.
Connection disable/rotation is rechecked under the same tenant/service admission
lock used by settings writes. The HTTP wrapper rejects query parameters and
multiple signature/content-type headers, and acknowledges with empty TwiML (no
automatic reply). WhatsApp ingress remains disabled pending its own verification.

Inbound provider message IDs deduplicate signed retries; conflicting content for
the same ID is rejected. Distinct status observations are stored separately as
`unmatched_status`: no dispatched provider-ID binding exists yet, so none can change
held outbox records. Receipts retain the normalized payload encrypted with a
tenant/service/receipt-specific purpose. No media is fetched or Contact linked.
Admission is bounded per source and to 10,000 new receipts per tenant/service/hour.

Acceptance: strict form (22), provider contract (46), fake sender lookup (14), and
32 rollback-only callback integration checks (including native HTTP wrappers and reader permissions). These use synthetic signatures and no
provider traffic. Public HTTPS delivery,
dispatch binding/recovery and live SMS/WhatsApp acceptance remain pending.

## Incoming Message Inspection

Tenant administrators can open **Messaging connections → Incoming messages**, or
`/w/<tenant-slug>/NexaConversations/messages`. The read-only screen shows incoming
SMS or unmatched delivery receipts, filtered by connection. It uses 50-record
keyset pages and a scrollable list/content layout, with mobile back navigation.
Historical receipts remain inspectable after a connection is disabled; accepting
new callbacks still requires current verification and an active connection.

The authenticated reader rechecks the active tenant administrator, tenant and
service, scopes every query, and decrypts each payload using its receipt-specific
purpose. Foreign receipt IDs return not found. Ordinary staff are denied on this
administrator endpoint; the separate SMS workspace uses native per-record
permissions. Chat permissions do not implicitly authorize SMS receipt access. Browser responses omit ciphertext,
provider message identifiers and privileged provider media URLs. Text is escaped;
media count/type is shown without fetching or rendering remote content.

This administrative inbox does not manage staff ownership; use the native SMS
workspace below for that. Automatic Contact identification, read/unread state,
outbound replies and a unified customer timeline remain pending. This screen does
not activate sending.

## Native Staff SMS Conversations

Migration `0097` adds `NexaMessagingConversation` roots using the native ORM,
assignment relationships, field ACL and optimistic concurrency. It does not
replace website chat or copy message content. Website-chat roots require widget
and visitor-session capabilities, so SMS has a separate root rather than faking a
website session. SMS receipt content remains encrypted in its existing store.

Open **Channels → SMS**, or `/w/<tenant-slug>/NexaConversations/sms`. Tenant
administrators can read and assign conversations. Grant ordinary staff **SMS
Conversations** read/edit scope and field permissions through native Roles.
`own` access requires assignment; read-only access does not permit triage updates.
Assignment also requires native assignment permission and status/owner edit access.
SMS permissions do not grant administrator receipt/connection access.

The native `RecoverMessagingConversations` scheduled job runs per tenant/service
with up to 100 receipts per invocation. It groups accepted incoming SMS by stored
connection and sender, with an encrypted peer address and no inferred Contact
identity. Recovery is idempotent and keeps the original receipt as history. New
incoming activity may reopen closed work without changing ownership; recovered
history older than staff triage does not undo that triage (timestamps have the
existing receipt store's one-second resolution).

Each receipt projects atomically. Unreadable content records a generic failure
code and exponential retry delay capped at one hour, and does not block other
receipts. Successful recovery clears that failure. The administrator reader reports
unavailable content rather than failing the whole inbox. New public callbacks
remain gated by verification; historical receipts can recover after a connection
is disabled because their acceptance already occurred.

Failure backoff is written while tenant/service admission remains locked. A worker
that scanned a receipt before another worker failed it rechecks its retry time
under the receipt lock, instead of bypassing the newly recorded backoff.

Staff list/detail APIs check native record ownership before decrypting data. Hidden
peer/message fields do not leak through list previews. History is bounded to 50
messages per keyset request; privileged media URLs are never returned or loaded.
Triage rejects stale versions, foreign/inactive owners and unauthorized fields,
and records the actor in immutable audit. The view has no composer and the API
has no send operation. All outgoing records remain held.

Outbound SMS consent/purpose enforcement and delivery,
WhatsApp verification, Messenger, remaining channel timeline integration and live
provider acceptance remain pending. A sender phone is not proof of CRM identity.

### Explicit Contact Association

Migration `0098` adds an optional Contact link to the native SMS conversation.
Channels > SMS provides a searchable Contact chooser and unlink control. Matching
phone numbers never create an association automatically. Linking requires native
SMS edit permission, the `Contact association` read/edit field permissions, and
native edit access to the selected Contact in the same tenant and service.
Unreadable associations are not exposed. Search requires two characters and is
bounded; only eligible Contact IDs and names are returned.

Link and unlink commands lock the conversation, reject stale versions and append
an immutable audit with the actual actor. Repeating the same association is a
no-op. Linking does not verify identity, change consent or do-not-contact, copy
messages into duplicate timeline records, or enable outgoing delivery. Existing history and
unsaved triage controls remain intact when the association changes.

### Received SMS in Contact Activities

The existing customer foundation snapshot now reads received SMS from original
encrypted receipts through the current explicit Contact association. The Contact
Activities tab renders expandable, searchable received-message cards with an SMS
type filter and a link to the SMS workspace. It does not create Notes or copies
of message bodies, claim verified identity, or present messages as sent outreach.

Both Contact read access and native SMS conversation/association/message read
permissions are required. Conversation ownership is checked before decryption;
the query is explicitly tenant/service scoped and bounded to 200 candidates.
Unlinking removes messages from that Contact's next snapshot; relinking exposes
them only through the new permitted association. Missing decryption data is
reported as unavailable without exposing ciphertext. Provider media is not fetched.
The existing read-only audit history is unchanged; timeline cards do not offer
editing or deletion of authoritative callback receipts.

### Local Inspection Acceptance: 17 October 2026

- Combined messaging runner passed 156 checks (22 strict form, 46 provider
  contracts, 14 fake lookup, 32 callback/reader and 42 connection checks).
- Authenticated WAMP browser acceptance passed all five desktop/tablet/mobile
  viewports, including 53-message keyset loading, connection filters, cross-tenant
  known-ID denial, escaped script-like content, no provider media URL exposure and
  WCAG AA checks. Initial readiness timing failures were corrected before the
  successful complete rerun. Synthetic fixtures were cleaned up after every case.
- Repository verification, JavaScript syntax and whitespace checks passed.
  Desktop, mobile and short-screen screenshots were inspected.
- No new schema migration was needed beyond `0096`. WAMP cache/frontend timestamp
  were refreshed before browser acceptance. No real provider credentials were
  changed and no external messages were sent. Work remains local and uncommitted.

### Staff SMS Acceptance: 18 October 2026

- Migration `0097` was applied and native metadata rebuilt. The recovery schedule
  is active every minute for both demo tenants; no provider configuration changed.
- Combined messaging acceptance passed 187 checks: 22 form, 46 provider contract,
  14 fake lookup, 63 callback/reader/projection/ACL and 42 connection checks.
  Database fixtures roll back. Coverage includes own-record access, hidden fields,
  fresh active-user checks, stale triage updates, service boundaries, history
  recovery, poison-receipt isolation and a stale worker's retry-time recheck.
- Authenticated administrator/job-entry/staff browser acceptance passed desktop,
  tablet, tablet landscape, mobile and mobile landscape across the completed runs.
  It covers 53 incoming messages grouped into one root, history cursors, native
  ownership assignment, read-only staff access, foreign-tenant denial, conflicting
  updates (one HTTP 200 and one HTTP 409), escaped content and WCAG AA checks.
- The interrupted four-viewport run was rerun. Tablet landscape initially exposed
  a test startup race, then an inappropriate header-box visibility check. Its
  successful final rerun uses the existing `nexa-shell-ready` signal, without
  reloading on failures or changing application authentication. Synthetic fixtures
  were cleaned up by each completed case. Permission-denial notifications in
  screenshots are intentional negative-test results.
- Repository verification, JavaScript syntax and whitespace checks passed.
  Desktop and both landscape layouts were visually inspected. Sending remains
  disabled; no external provider requests or messages were made by these tests.
  Work is local, uncommitted and unpushed; the Phase 8 exit gate remains open.

### Contact Association Acceptance: 18 October 2026

- Applied migration `0098` and rebuilt native metadata. Combined messaging checks
  passed 209 assertions: 22 form, 46 provider contract, 14 fake lookup,
  85 callback/reader/projection/ACL/association and 42 connection checks.
- Rollback-only fixtures cover explicit link/unlink, repeat no-op, stale versions,
  tenant/service boundaries, deleted Contacts, own-record Contact edit access,
  hidden association fields, actual-actor audit, unchanged consent/do-not-contact,
  and recovery retaining the selected association.
- Authenticated desktop/mobile browser flows passed link, unlink, relink and
  restricted-staff visibility checks. Expanded chooser WCAG AA checks passed;
  desktop and mobile picker screenshots were visually inspected. An initially disabled Link button exposed
  an undefined boolean state; the application fix passed the rerun.
- Repository verification, JavaScript syntax and whitespace checks passed.
  No provider requests or messages were sent. Changes remain local, uncommitted
  and unpushed. At this checkpoint Contact timeline integration and outbound
  delivery remained pending; received SMS timeline integration follows below.

### Contact SMS Timeline Checks: 18 October 2026

- Combined messaging acceptance passed 222 checks, including 98 rollback-only
  callback/reader/projection/ACL/association/timeline assertions. Existing customer
  foundation runtime isolation tests passed.
- Six desktop/mobile focused browser tests passed for SMS rendering and existing
  Contact/Account score-history regression coverage. SMS tests include unavailable
  content, escaped HTML-like text, read-only cards, SMS icons and isolation of the
  separate agenda's flex layout from Contact timeline cards.
- The browser screenshot review exposed unscoped Activities-workspace styles
  squeezing Contact cards into horizontal rows. Those conflicting layout selectors
  are now scoped to their own workspace. No new database migration is required.
- Repository verification, JavaScript syntax and whitespace checks passed.
  Outgoing SMS remains held; no provider requests or external messages were sent.
- Final authenticated desktop/mobile acceptance passed both complete flows,
  including 53 SMS cards in the real Contact Activities tab, live search, the SMS
  filter, expandable escaped message content and absence of receipt edit/delete
  controls. Desktop and mobile screenshots were inspected. The test login helper
  now asserts the workspace URL and native shell-ready signal rather than waiting
  for an unrelated full-load lifecycle event. Earlier interrupted/stalled attempts
  are not counted as acceptance. No fixtures created today remain active.
- Work remains local, uncommitted and unpushed. This completes received SMS
  Contact-timeline integration, not outbound delivery or the Phase 8 exit gate.

### SMS Opt-Out Checks: 18 October 2026

- Applied additive migration `0099` with the checksum-ledger runner; previous
  migrations were skipped unchanged. Combined messaging acceptance passed 253
  checks: 22 strict forms, 56 provider contracts, 14 fake lookups, 119 rollback-only
  callback/conversation/timeline/preference checks and 42 connection checks.
- Coverage includes signed STOP/START/HELP, unsigned preference tampering,
  exact-keyword fallback, replay idempotency, tenant/service/connection/recipient
  isolation, actual held-staging denial, historical indexing, poisoned-receipt
  backoff/recovery and the existing sender-uniqueness database boundary. START
  cannot release suppression or change Contact consent/do-not-contact.
- Desktop authenticated browser acceptance passed the warning, worker recovery,
  Contact association and 53-message Contact Activities flow. Mobile exposed a
  detached-card assertion during an existing asynchronous render; the assertion
  now uses Playwright's retrying locator check. The subsequent complete mobile
  rerun passed. Screenshot inspection also led to internally scrollable SMS
  details on small screens so controls cannot be clipped by the preference band.
- The final complete mobile rerun passed with internal scrolling asserted. Desktop
  and mobile warnings were visually inspected; all synthetic tenants created today
  were cleaned up. The staff screenshot's access-denied notification is the
  intentional administrator-only receipt-access negative test, not an inbox error.
- Repository verification and whitespace checks passed. There were no external
  provider calls or messages. Credentials were not changed. All work remains local,
  uncommitted and unpushed; opt-in release/reconciliation and dispatch remain open.
