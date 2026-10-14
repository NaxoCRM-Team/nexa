# Transactional Email And Postal Recovery Acceptance

Date: 12 October 2026. Local WAMP acceptance, not public delivery certification.

## Transactional Boundary

Native EmailTemplate, MassEmail and EmailQueueItem remain authoritative. Transactional drafts have no campaign or audience and use the tenant-owned system Customer service purpose. Each request specifies exactly one scoped Contact. Approval, native record/field permissions, verified sender, suppression and final-worker checks remain enforced.

Eligible evidence is either current granted consent with FreelyGivenConsent, or an administrator-recorded not_required decision with PerformanceOfContract and a nonempty evidence note. The source event must match the current Contact/purpose/channel projection, current policy version and effective/expiry dates. Consent remains Contact/channel scoped, not an assertion that marketing enrollment is required. Invalid/opted-out primary addresses and applicable do-not-contact or suppression states block delivery.

The email.transactional entitlement is an explicit monthly message limit. It does not inherit the 20-times marketing-contact allowance. Missing grants give zero capacity; migration 0075 registers the service but grants nothing. Migration 0074 supplies separate reservation counters, while the shared provider ceiling includes both buckets. No existing configuration or password is changed.

## Uncertain Postal Submissions

Migration 0076 stores Postal's integer record identifier and bounded inspection metadata. Inspection uses the configured provider's read-only messages/message API. Acceptance requires an exact queue-header, sender and recipient match and a valid RFC message identifier. Proof records contain an evidence hash, not message bodies or credentials.

Known held records are inspected in bounded batches with a five-minute retry interval. Unknown record identifiers retain the existing administrator review path. Missing, invalid or unavailable evidence never releases a reservation or triggers a blind resend. Accepted evidence uses the existing locked reconciliation and immutable audit path.

## Recorded Tests

- TenantTransactionalEmailPolicyTest: current evidence, revocation, expiry, scope, contract authorization, opt-out, suppression and do-not-contact checks passed.
- PostalInspectionEvidenceTest: exact identity and mismatched/duplicate/missing header rejection passed.
- verify-automation-acceptance.ps1 -Suite Programmable -LocalPostalCapture -Projects desktop,mobile: six scenarios passed, including marketing and non-marketing, unenrolled transactional Contacts, real API-key grants, native workers, exact local Postal capture and distinct/replayed send keys.
- The same capture scenarios simulated a lost native acknowledgement after actual Postal acceptance. Read-only inspection reconciled the held queue without another provider attempt or SMTP message.
- marketing-single-send.spec.js: desktop/mobile authoring and confirmation passed, including transactional purpose display and campaign/date removal without hiding sender or content fields.
- The combined workflow callback/branch evidence is recorded separately in phase-6-live-closure-acceptance.md.

Public domain/DNS, external inbox delivery and public signed callback transport remain external acceptance requirements. Local TLS and Mailpit capture do not establish those properties. These changes are uncommitted and have not been pushed.
