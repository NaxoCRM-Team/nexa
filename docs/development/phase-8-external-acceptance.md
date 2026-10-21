# Phase 8 External Acceptance

Local tests cannot establish these deployment properties. Keep this gate open
until evidence identifies the deployment, release, tenant scope, test date,
operator and result. Never attach tokens, customer message bodies or credentials.

## Required Checks

- [ ] Real SMS sending/receiving and exact-attempt delivery callbacks; demonstrate
  timeout/uncertain-send recovery without a duplicate send.
- [ ] Real WhatsApp approved sender/templates, session enforcement, incoming
  messages/attachments, replies and delivery/read callbacks where supported.
- [ ] Real Messenger approved connection, incoming messages/media, replies and
  delivery/read callbacks where supported.
- [ ] Public HTTPS callbacks: provider verification and valid signatures accepted;
  invalid/replayed/cross-connection requests rejected without exposing secrets.
- [ ] Actual Cloudflare private file creation, authorized downloads, cross-tenant
  denial, deletion, storage-outage retries and interrupted staging cleanup.
- [ ] Widget on an approved tenant-controlled production HTTPS origin: allowed and
  foreign origins, identity proof, consent, handoff and visitor revocation.
- [ ] External Gmail/Outlook receive/reply/threading and expired-credential recovery
  using approved test mailboxes. Existing local mailbox acceptance is not proof.
- [ ] Representative production-scale sustained workload with documented rates,
  tenant rotation, queue-age/error thresholds and provider throttling/recovery.
- [ ] Encrypted off-host backup, file restoration and infrastructure cutover drill;
  measured recovery point/time against approved targets. See
  [recovery procedure and local drill](../operations/recovery.md).

Provider credentials, approved accounts, reachable HTTPS infrastructure, private
object storage and approved external test recipients must be supplied through the
deployment's secret/configuration mechanism, not committed or pasted into issues.
Do not enable dispatch or make real sends until the operator approves the sender,
recipients and expected charges.

## Closure Evidence

For each check, record pass/fail, redacted provider correlation IDs where needed,
observed retry/replay behavior and the evidence location. Read receipts are not
available for every channel/account configuration; document provider limitations
explicitly rather than inventing a read state.

This gate does not claim that additional local feature implementation is missing.
Any failure found during live acceptance becomes a concrete defect with its own
regression test. Phase 8 must not be described as production-accepted solely
because local synthetic tests pass.
