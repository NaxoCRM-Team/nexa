# SMS And WhatsApp Incoming Attachments

Implemented locally on 21 October 2026. Nexa remains on WAMP. The implementation
reuses signed Twilio receipts, native conversations, native Attachments, native
ACL and the existing tenant file library; it does not create another file store.

## Flow

1. Existing signature/account/sender validation admits a durable encrypted receipt.
2. The existing conversation projector indexes each attachment once by receipt
   and slot. Historical projected receipts are indexed in bounded background work.
3. `RecoverMessagingConversations` also fetches at most two eligible files per
   tenant/service run. Native cron must be running; its existing minute schedule
   is reused. Disabled connections and deleted conversations are filtered before
   the batch limit, so they cannot occupy every slot.
4. Provider bytes are checked before allocating a native file. A hidden staging
   Attachment records the configured storage backend, including Cloudflare R2.
   Provider fetch and storage IO are outside the worker's admission transactions.
5. After storage succeeds, the worker rechecks its claim, active workspace,
   original connection identity/version and conversation. Only then does it
   publish the private native Attachment under the conversation's `messages` field.

Both inboxes display processing/unavailable states and authenticated attachment
controls. Clicking a ready image shows a preview; other files download. Completed
files appear in the existing tenant file/image pickers only when the original
conversation and message-field permissions allow access. Downloads use the
authenticated messaging API, not an exposed provider URL or public bucket link.
Contact history links back to its conversation for attachment access.

## Security And Limits

- Incoming media is limited to 8 MiB per file and ten signed slots per receipt.
  At most 1,000 pending/downloading items are admitted per tenant/service; overflow
  is explicitly unavailable rather than silently promoted to a downloadable file.
- Allowed content reuses the existing Messenger inspection rules: PNG/JPEG/GIF/
  WebP, approved audio/video MIME types, PDF, plain text and ZIP. Content bytes must
  match the declared MIME type. SVG, HTML, executables, unsupported formats and
  excessive image dimensions are rejected. ZIPs are not extracted. This is type/
  size validation, not an antivirus or malware-disarm service.
- URLs must match the exact signed Account, Message and Media resource identity
  on approved Twilio API hosts. HTTPS/TLS verification, public IPv4-only DNS
  validation, pinned resolution, no proxy, bounded headers/body and timeouts apply.
- One controlled redirect to `mms.twiliocdn.com` is supported, without forwarding
  credentials. Further redirects, arbitrary hosts and legacy public S3 redirects
  are rejected. No provider URL, authentication token or signed CDN URL is exposed
  by the thread, library or attachment response.
- Native record ownership and message-field ACL govern downloads. A file cannot
  be requested under another conversation or tenant. Receiving an attachment does
  not grant permission to send messages, release suppression or override DNC.
- A two-minute lease with a per-attempt token prevents stale workers from
  publishing over a newer result. Retries use five-minute backoff and stop after
  three attempts; failed items remain visibly unavailable. No automatic outbound
  messages or provider deletions are performed.
- Every upload attempt owns a separate hidden native staging file. Failed/losing
  attempts remain tracked. After one day, the worker retries cleanup of up to two
  staging objects per run, deleting metadata only after object cleanup succeeds
  (or the backend confirms the object is absent). Native generic cleanup excludes
  only these reserved conversation staging files so it cannot orphan their bytes.
  Published files retain ordinary native attachment behavior.

Provider behavior was checked against [Twilio's Media resource documentation](https://www.twilio.com/docs/messaging/api/media-resource)
and its [authenticated-media guidance](https://help.twilio.com/articles/223183748).
Live account authentication, CDN behavior and real Cloudflare operations still
require deployment acceptance; local fake transport is not proof of those paths.

## Migration And Tests

Migration `0109_incoming_messaging_media.sql` adds scoped queue metadata and
historical-index state. It is applied locally through the checksum-ledger runner;
0001-0108 were skipped unchanged. Both ownership manifests include the new table.
No provider credentials, storage settings or live sending flags were changed.

- 37 fake-network fetch/content checks passed, including exact 8 MiB acceptance,
  oversize rejection, resource substitution, private/mixed DNS, MIME spoofing and
  credential stripping on redirects.
- 36 rollback-only tenant/storage checks passed: SMS/WhatsApp admission, replay,
  native own/hidden-field permissions, library visibility, configured storage
  selection, backoff, interrupted/stale-worker races, historical indexing,
  connection revocation, starvation prevention and staged-object cleanup recovery.
- The full messaging runner passed all 635 checks, including the 73 new incoming
  attachment checks. Existing 562 messaging/routing checks passed after receipt integration; 37 chat
  privacy checks and 12 same-Contact cross-channel checks also passed.
- Authenticated browser acceptance passed desktop, tablet, tablet landscape,
  mobile and mobile landscape. Two independent CLI workers fetched exactly two
  files without duplicate downloads. Tests verified decoded image pixels, actual
  file downloads, foreign-tenant rejection, library visibility and WCAG A/AA.
  Desktop/mobile screenshots were inspected. Browser files were synthetic and
  explicitly stored locally, then cleaned up; no real R2 deletion is claimed.

Run the existing `verify-messaging-providers.ps1 -Database` for native checks, or
`-Live` for authenticated provider UI coverage. The combined `verify-phase8.ps1`
now includes this spec (60 browser scenarios across five viewports). That expanded
60-scenario gate is being rerun as one package; a clean combined pass has not yet
been recorded. The earlier 55-scenario evidence and the focused five-viewport
acceptance remain separate results until that gate passes.

Release status is tracked in [the dated delivery note](delivery-status-2026-10-21.md).
Real Twilio/WhatsApp ingress, authenticated provider downloads, public callbacks
and Cloudflare R2 acceptance remain pending under GitHub #163.
