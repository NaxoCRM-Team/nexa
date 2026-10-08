# Reusable Approved Marketing Sends

This API creates an independent native email/template/queue copy from the current approved source. The source remains approved and is not itself queued. It is not an arbitrary-address SMTP relay or a transactional-consent bypass.

```http
POST /api/v1/Nexa/marketing-emails/{sourceProfileId}/reusable-send
Content-Type: application/json

{"contactId":"CONTACT_ID","sourceVersion":1,"requestKey":"integration-event-000001"}
```

Use the existing authenticated Nexa API and tenant/service resolution. An authorized tenant administrator, or an active native API user with an explicit unexpired grant for this source version and native record/field read permissions, may request a send. Native Contact personalization supplies recipient data. Sender, recipient-address and content overrides are rejected. Declared plain-text parameters are supported below. See [integration grants and restricted copies](marketing-integration-grants.md).

## Approved Text Parameters

In the marketing email editor, choose **Insert parameter**, enter a lowercase name such as `appointment_reference`, select its maximum character count and choose Email body, Subject or Preheader. The dialog appends the declaration to the selected field; Cancel and Escape leave the draft unchanged. It checks conflicting limits, the 20-name cap and field lengths before insertion. You may reposition the declaration using the existing content editor. The equivalent HTML-source declaration is `[[nexa:appointment_reference:40]]`.

The name and maximum character count become part of the immutable approved source content. Save, submit and approve that source through the existing workspace. All declarations for the same name must use the same limit. Save and approval validate placement on the server. Attributes, links, markup, comments, styles, scripts and native helper/token arguments cannot contain declarations.

```json
{"contactId":"CONTACT_ID","sourceVersion":1,"requestKey":"appointment-event-000001","parameters":{"appointment_reference":"APPT-42"}}
```

Supply exactly the declared names as a JSON object; missing/unknown names, arrays, null, template expressions, HTML and control characters are rejected. Body values are HTML-escaped. Native `{Contact.firstName}` personalization is retained. Resolved subjects/preheaders must fit 255 characters. The native child version stores the resolved content before queuing, so later delivery does not depend on mutable caller data. The scoped request ledger binds the schema/value fingerprint: reordered keys replay the original send, changed values cannot reuse its key. Audit events retain the fingerprint, not a second copy of supplied values. Resolved values remain in native email content and are subject to its existing access/retention policies.

Campaign scheduling, ordinary single-send and the final delivery path reject unresolved declarations. Use this API to resolve them. The editor inserts declarations visually; delivery values still arrive through the authenticated API. **Preview as Contact** opens a sample-value form when declarations are present. The sandbox renders resolved subject/body/preheader with native Contact personalization; cancellation changes no content and sends nothing. Granted API users can send approved resolved copies but cannot edit or approve sources. This is not a transactional-consent bypass. See [parameter validation limits](marketing-parameter-contract.md).

The source must be the current approved version, with unchanged versioned subject/body/preheader and approved image/attachment evidence. Its campaign must be active; the Contact must be enrolled and eligible for that purpose. Provider/domain readiness, suppression, native opt-out, do-not-contact, plan entitlement and effective monthly allowance use the existing delivery safeguards. The worker rechecks eligibility and capacity before provider submission.

Approval, scheduling and final dispatch also verify the immutable content hash and compare persisted native subject/body/preheader against that version. Cached ORM content alone is not trusted. Changes made outside the governed editor require a new save and approval; missing or corrupt version evidence blocks delivery. Existing queued-request replays preserve their original identity and cannot recreate a failed send.

Migration `0068_marketing_delivery_policy_snapshot.sql` also binds sender, reply-to, campaign, consent purpose and delivery mode to the saved version. Historical versions lack this evidence and cannot authorize fresh sends until saved and approved again. Do not reset old queues or clear uncertain submissions to bypass this requirement. For noneditable scheduled/paused records, review their existing delivery history before intentionally creating another draft. Request-key retries still return their original dispatch identity; they never silently resend.

Each request key is 8–128 ASCII letters/digits or `. _ : -`. The durable SHA-256 key is unique within the tenant/service, not merely the source. A repeat with identical source/version/Contact returns the same native queue identity and current status; changing those inputs with that key is rejected. A different key authorizes a separate intentional send. Keys do not expire automatically, including after failures. Missing or purged dispatch records are errors, never permission to recreate a send.

The request ledger, cloned native records, approval evidence, queue and source-version audit event share one transaction. Administrator copies use native review/approval transitions; API copies inherit exact source approval with explicit provenance rather than impersonating an administrator. A savepoint also preserves rollback safety inside an outer transaction. Database uniqueness and row locks serialize collisions. At most 60 new successful requests per source per minute are accepted; retries are exempt. Accepted means queued, not delivered. Existing delivery results, pause/review/recovery paths remain applicable.

Apply additive migrations `0066_marketing_reusable_send_requests.sql`, `0067_marketing_send_parameter_identity.sql`, `0068_marketing_delivery_policy_snapshot.sql` and `0069_marketing_integration_send_grants.sql` with the shared migration runner before using the endpoint with API-user grants. WAMP has received all four. The restricted-copy path needs no additional schema migration. The nullable fingerprint preserves retries for historical requests that allowed no parameters. Existing customer records remain untouched; historical email versions retain NULL delivery-policy evidence and require a new saved/approved version for fresh sends.

`TenantMarketingReusableSendTest.php` uses rollback-only synthetic fixtures, exercises the real API, rejects overrides/permissions/version/key conflicts, verifies native copy/replay identity and source preservation, and checks a competing database connection waits on the unique key. It sets do-not-contact before running the actual worker, so no provider delivery is submitted. This is not external-mailbox acceptance or completion of programmable email, transactional mode or Phase 6.
