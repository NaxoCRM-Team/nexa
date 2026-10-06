# Governed Marketing Single-Send API

This authenticated API submits one approved marketing email to one eligible Contact through the existing native `MassEmail`, `EmailTemplate` and `EmailQueueItem` dispatcher. It is not the staff test-send endpoint, an arbitrary-address SMTP relay, or a transactional consent bypass.

## Prepare the Email

### In the Workspace

1. Open **Marketing Email** and choose **Create email**.
2. Set **Delivery** to **One Contact**. Choose the normal campaign audience, sender and content, then save the draft. The proposed campaign send-time input is hidden for this mode.
3. Submit for review and approve the saved email.
4. Use the paper-plane action **Send to one Contact** on that email's table row. The campaign-wide Schedule action is not displayed for this mode.
5. Choose a Contact using the native tenant/permission-scoped Contact selector. Review the recipient, email name and sender in the confirmation, then choose **Queue email**.

Selecting a Contact or cancelling the confirmation does not queue anything. The Contact selector lists readable tenant Contacts; actual campaign enrollment and delivery eligibility are enforced by the server when queuing. An ineligible Contact produces an inline error instead of a provider send. Buttons are disabled during submission, repeated clicks are ignored, and a safe retry uses the same profile/Contact identity. Navigating away invalidates late UI responses but cannot undo a request already accepted by the backend.

The response confirms queuing or the status of an existing request, not successful delivery. Use **Delivery results** to check processing outcomes. Editing an existing draft retains its delivery mode; switching delivery mode while editing creates a new content version and resets approval through the existing save path. Invalid API mode values are rejected rather than silently becoming campaign-wide sends.

### Through the API

Create an email through `POST /api/v1/Nexa/marketing-emails` using the existing draft contract and `deliveryMode: "single_send"`. Keep the normal campaign, purpose, sender, content and approval process. Submit and approve it through the existing transition API. A tenant administrator with the required native MassEmail and Contact permissions must make the request.

The Contact must belong to the current tenant/service and be enrolled in the active campaign, have valid unexpired purpose-specific email consent and Marketing status, and pass native opt-out, do-not-contact and suppression checks. The sender domain and provider must be ready. Approved image and attachment evidence and the effective monthly allowance are checked before queuing.

## Request

```http
POST /api/v1/Nexa/marketing-emails/{profileId}/single-send
Content-Type: application/json

{"contactId":"CONTACT_RECORD_ID"}
```

Use Nexa's existing authenticated API and tenant/service resolution; no anonymous endpoint or new authentication system is introduced. The body accepts only `contactId`. Sender, recipient address, content and personalization overrides are rejected.

The response includes `id`, `queueItemId`, the native queue `status` and `replayed`. `Pending` means queued, not delivered. The existing scheduled worker processes it, rechecking eligibility, approved assets and delivery capacity before calling Postal. Existing delivery results, pause/resume and uncertain-submission review remain applicable.

## One-Shot Identity and Retries

The approved **profile ID is the idempotency identity**. The endpoint locks that profile, performs a conditional approved-to-sending state claim, inserts one native queue item and appends the actor/version/Contact audit event in the same transaction. A repeat request for the same Contact returns the existing queue identity/status, including failed or completed deliveries. Deleted queue rows are also considered so soft deletion cannot reopen the send.

Another Contact cannot reuse this identity. A new intentional send needs a new separately approved profile. Failed or uncertain sends are not automatically recreated. This version does not provide a repeatedly callable reusable-template API with independent external idempotency keys, programmable input parameters, or transactional-mode consent rules; those remain separate Phase 6 work.

Campaign-wide scheduling rejects `single_send` profiles. Editing a draft in the workspace preserves its API-created delivery mode. The supplied start time is not a scheduling delay for this endpoint: the explicit request queues the Contact for the next worker run.

## Verification

```powershell
& 'C:\wamp64\bin\php\php8.2.29\php.exe' tests/tenant/TenantMarketingSingleSendTest.php
```

The rollback-only test exercises the real API action, permissions, tenant/service boundaries, recipient override rejection, unapproved and do-not-contact rejection, stable replay identity, one native queue item, and a second database connection blocked by the profile lock. It sets do-not-contact before executing the real worker, proving eligibility is rechecked without submitting any provider message or reserving allowance. It does not prove public-domain delivery to an external mailbox.

`tests/browser/marketing-single-send.spec.js` uses the real workspace view with native-selector and API host doubles across five viewports. It covers draft mode persistence, conditional send-time visibility, mode-specific actions, explicit confirmation, escaped recipient display, keyboard/focus behavior, inline consent errors, duplicate-click blocking, stable retry payloads, navigation response invalidation and permission-aware controls. It does not queue real Contacts or claim end-to-end Postal acceptance.

No migration is needed: the existing profile mode, lifecycle, native queue and event tables provide the one-shot identity and audit history.
