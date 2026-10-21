# Messenger

Open **Channels > Messenger** or
`http://localhost/nexa/w/isolation-alpha/NexaConversations/messenger`.
Native conversations, ownership and message-field permissions remain authoritative.

## Configuration

Page tokens and app secrets are encrypted; verification tokens are hashed.
Credential rotation clears verification and activation. Verification checks token
identity, app, scope, expiry and Page subscriptions: `messages`, `message_echoes`,
`message_deliveries` and `message_reads`.

Operators configure `nexaMessengerGraphVersion` and canonical public HTTPS
`nexaMessagingPublicBaseUrl`. Sending additionally requires
`nexaMessengerDispatchEnabled`, fresh Page proof, webhook verification and tenant
activation. Real credentials and dispatch settings were not changed by acceptance.

## Delivery And Read Tracking

Raw-body SHA-256 signatures and verified Page/app routing authenticate messages,
echoes and receipts. Delivery IDs bind the exact Page, peer and reply. Read
watermarks require signed provider echo time, never Nexa's local send clock.
Server-generated nonces in echo metadata can resolve an uncertain submission
without resending. Conflicting echo identity is rejected; duplicates are
idempotent and read status never regresses.

Unmatched receipts remain evidence, not guessed associations or customer messages.
Reconciliation is bounded to 1,000 recent peer receipts and 1,000 non-read reply
candidates. Older missing evidence can leave a status unknown/accepted; no false
read claim is made. Phase-wide maximum-capacity acceptance remains separate.

## Media

- Signed incoming references are encrypted and queued. The existing recovery job
  downloads at most two files per workspace run, with bounded retries and
  interrupted-download recovery. A 1,000-item pending cap marks excess attachments
  unavailable without discarding their message text.
- Only HTTPS Meta CDN hosts (`fbcdn.net` / `fbsbx.com` and subdomains). Public IPv4
  DNS is validated and pinned for TLS; redirects, proxies, credentials, alternate
  ports and private/reserved addresses are rejected.
- Files are limited to 8 MB. Actual bytes determine type; images also have dimension
  limits. Supported types: PNG/JPEG/GIF/WebP, MP3/OGG/WAV/M4A, MP4/WebM, PDF, plain
  text and ZIP. Unsupported/expired references remain unavailable.
- Received files are private native Attachments parented to the conversation, not
  globally reusable library items. Native download routes also enforce ownership
  and message-field permissions. Authorized staff can preview images, play
  audio/video and download supported files.
- Outgoing files use the existing tenant file picker/upload API. One text message
  or one attachment is sent per reply. The backend rechecks file access, scope,
  type and size, then uploads binary bytes to Meta without creating a public file URL.
- Production uses configured native storage, including Cloudflare R2 tenant
  prefixes. Tests use fake fetches and temporary local storage, not real R2 or Meta
  delivery. Type validation is not an antivirus-scanning guarantee.

## Governed Opt-Out Review

Signed STOP blocks replies. A later signed START/UNSTOP/SUBSCRIBE does not silently
release suppression. An active tenant administrator with message edit permission
must review renewed opt-in within 24 hours, recording a reason, renewed-consent
evidence and policy version. The immutable audit binds the conversation, signed
opt-in and previous opt-out fingerprint. Stale evidence is rejected; a new STOP
invalidates the release. Review is bounded to 1,000 preference events and fails
closed beyond that limit.

The release permits customer-service replies only. It does not grant marketing
consent, change canonical Contact consent, clear DNC or extend the reply window.
Linked Contact restrictions block review and replies. Visitor identities are
never automatically matched to Contacts.

## Acceptance

Migrations `0105` and `0106` were applied through the checksum-ledger runner;
previous migrations skipped unchanged. WAMP metadata and frontend assets refreshed.

```powershell
powershell -ExecutionPolicy Bypass -File scripts/dev/verify-messaging-providers.ps1 -PhpPath C:\wamp64\bin\php\php8.2.29\php.exe -Database
```

Local evidence on 20 October 2026: 43 Messenger protocol checks and 51 rollback-only
Messenger tenant checks; 518 checks in the combined messaging suite. Authenticated
desktop/mobile tests cover private-image pixel rendering, read status, administrator
review, disabled real sending and WCAG AA. An initial desktop contrast failure was
fixed before the passing rerun. Screenshot inspection also identified a mobile
history/review overlap; its height rule was fixed and an explicit non-overlap
assertion passed on both viewports. Tests cover native file ACL, foreign-tenant denial,
DNC, review evidence/permissions, replay, new opt-outs, nonce recovery and media
type/size/DNS boundaries.

Public Meta callbacks, actual multipart media delivery, production CDN downloads
and live R2 acceptance still require real credentials, approved permissions and
public HTTPS. No production-delivery claim is made. Changes remain local and
uncommitted; no push was requested.

### Private Received Files In The Tenant Library

Received conversation attachments now appear in the existing file and image
pickers only after native attachment read permission succeeds. They retain their
conversation parent, message-field ACL and private storage flag; listing them does
not publish them or promote them into globally reusable assets. Authorized staff
may reuse a received file in a Messenger reply, subject to the existing actual-byte
type inspection and 8 MB channel limit. Native authenticated attachment downloads
retain the same conversation permission boundary.

Focused verification: 56 rollback-only Messenger tenant checks passed, including
library visibility, hidden-message denial and authorized/denied reuse. No database
migration or storage configuration change was required for this library integration.
SMS/WhatsApp received-media fetching, private library admission and authorized
downloads are now implemented and locally tested. See [incoming media acceptance](phase-8-incoming-media.md).
Real provider and Cloudflare storage acceptance remain separate external checks.
