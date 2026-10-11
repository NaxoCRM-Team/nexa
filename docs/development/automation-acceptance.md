# Automation Acceptance

Run this against the existing **local WAMP application**, after applying migrations through `0073`. No existing user's password, configuration, subscription or CRM records are changed.

```powershell
Set-Location C:\wamp64\www\nexa
powershell -ExecutionPolicy Bypass -File scripts/dev/verify-automation-acceptance.ps1 `
  -BaseUrl http://localhost/nexa `
  -PhpPath C:\wamp64\bin\php\php8.2.29\php.exe
```

The runner creates two uniquely named temporary tenants using an existing active Automation plan. Random administrator credentials exist only in process memory. Playwright logs in through the real login page and uses the real builder and authenticated native HTTP APIs. There are no API mocks or production permission bypasses. Independent PHP processes call the same workflow services used by the native background job.

Coverage:

- Builder creation, publication, saved execution history, native scoring and approved native property updates.
- Administrator-authenticated API enrollment, replay and rejected client scope overrides.
- Cross-tenant rejection for detail, history, draft update, publication, control, enrollment, event intake, cancellation and delivery review; generic property updates cannot write score or ownership identifiers.
- Two simultaneously released worker processes; exactly one committed action and no replay on restart.
- Two tenants progressing independently without sharing their records, score or API history.
- One tenant holding its quota transaction while the other tenant's worker still commits; shared plan metadata cannot become an exclusive cross-tenant bottleneck.
- Six worker-versus-cancellation races; no action commits after cancellation.
- Execution-user deactivation/downgrade, deleted Contact and changed Contact service ownership after publication.
- Two simultaneous claims for one effect; only one committed lease.
- Claim-versus-cancellation race, including an already claimed effect that cannot be recalled.
- Completion-versus-cancellation race using the same tenant-before-effect lock order.
- Expired lease held uncertain; a stale completion cannot change its outcome or resend.
- Native webhook deactivation and simultaneous claims subject to a tenant concurrency limit of one.

Lease finalization in the local test uses explicit synthetic results. It does **not** prove external delivery. The separate public-delivery test is skipped unless an approved endpoint is supplied.

## Approved HTTPS Delivery

Only use a receiver you control and explicitly approve for these test POST requests:

```powershell
powershell -ExecutionPolicy Bypass -File scripts/dev/verify-automation-acceptance.ps1 `
  -BaseUrl http://localhost/nexa `
  -PhpPath C:\wamp64\bin\php\php8.2.29\php.exe `
  -ApprovedWebhookUrl https://your-controlled-receiver.example/acceptance
```

The native transport retains public HTTPS/443, DNS/address validation, DNS pinning, TLS verification, no redirects/proxy, bounded timeout, signing and a stable effect idempotency key. The test requires an actual 2xx response and persisted success/one attempt, then proves another worker cannot resend. It does not force retry uncertain submissions. Receiver-side inspection/signature verification requires the receiver's own evidence and is not inferred from HTTP success.

Do not put signing secrets, personal data or authenticated receiver tokens into a committed URL. No public request is made by the local lease/race test; its placeholder URL is only validated and staged. The fixture creates no ScheduledJob, so existing cron schedules do not dispatch its staged effects.

## Catalogue Permission Matrix

The rollback-only `tests/tenant/TenantWorkflowRuntimeTest.php` suite now requires coverage for every advertised trigger and action. Missing catalogue rows fail the suite rather than silently reducing coverage. The current catalogue has 18 triggers and 18 actions.

For each row, native services must reject ordinary staff, a foreign tenant administrator and a mismatched service context for detail, history, update, publication, resume, enrollment, event intake, cancellation and webhook review. Only `Forbidden` or `NotFound` satisfies these assertions; a validation error is not authorization evidence. Inactive and downgraded administrators cannot publish, enroll or submit events.

All 16 automatic trigger families are polled by the native worker with inactive and downgraded execution administrators. They must record `access_denied`, enroll nobody and consume no source receipts. Native action-boundary probes reuse real immutable publications and are explicitly seeded rollback fixtures, not new browser enrollments. All 17 non-email actions must fail before an adapter attempt or external effect; Contact, Account, Opportunity, Task, Case and Notification snapshots remain unchanged. The approved email-source fixture in `TenantMarketingReusableSendTest.php` separately verifies a genuinely enrolled email action creates no queue item, attempt or send key after execution-administrator revocation.

Run these database suites sequentially, or use the repository verification runner:

```powershell
& 'C:\wamp64\bin\php\php8.2.29\php.exe' tests/tenant/TenantWorkflowRuntimeTest.php
& 'C:\wamp64\bin\php\php8.2.29\php.exe' tests/tenant/TenantMarketingReusableSendTest.php
```

This establishes catalogue-wide management and execution-identity authorization coverage alongside the existing source, related-record, field, consent and provider-configuration tests. It does not establish external HTTPS delivery, receiver-side signature validation, or acceptance for providers that are not implemented.

## Programmable Email HTTP Acceptance

The same ownership-checked committed fixture also supports the next Phase 6 email-acceptance package:

```powershell
powershell -ExecutionPolicy Bypass -File scripts/dev/verify-automation-acceptance.ps1 `
  -BaseUrl http://localhost/nexa `
  -PhpPath C:\wamp64\bin\php\php8.2.29\php.exe `
  -Suite Programmable
```

Random tenant administrators authenticate through the actual login form. Real HTTP approves the existing native-backed source and submits reusable sends. Coverage includes anonymous/foreign/downgraded callers, missing/oversized/unknown/HTML parameters, stale versions, foreign Contacts and client-supplied tenant overrides. Concurrent identical keys and later replay must share one native queue item; different intentional keys must create independent items, while changed parameter values under an existing key must fail. Persisted native template copies must contain the resolved parameters. Identical keys remain independent in the second tenant.

The actual native background worker runs in a separate PHP process after consent revocation, rejects queued delivery, creates no provider submission and cannot change the other tenant's queue. Repeated workers cannot revive the failed items. A rejected new send consumes no request key.

This no-network fixture uses synthetic DNS-readiness flags and a reserved `.test` provider/address solely to reach queue acceptance. It never establishes DNS verification or provider delivery. Its worker helper refuses to run while any fixture email consent remains granted, and requires the non-deliverable synthetic provider URL. No existing account configuration is changed.

### Actual Local Postal Capture

With the existing isolated Postal stack running, explicitly enable the positive-delivery scenario:

```powershell
powershell -ExecutionPolicy Bypass -File scripts/dev/verify-automation-acceptance.ps1 `
  -BaseUrl http://localhost/nexa `
  -PhpPath C:\wamp64\bin\php\php8.2.29\php.exe `
  -Suite Programmable -LocalPostalCapture
```

The separate capture fixture accepts only `https://localhost:8443`, sender `newsletter@nexa.local.test` and the exact synthetic fixture Contact. Existing ignored local credentials are read in-process, never printed or committed; the local CA is configured only in that worker process. It does not reconfigure Postal, modify existing tenants or disable TLS verification. Postal's existing capture-only relay delivers to local Mailpit, not an external inbox.

A real native read-only API user authenticates over HTTP using `X-Api-Key`. No grant means no send. An authenticated administrator grants the approved source version through its existing HTTP endpoint; concurrent same-key calls reuse one native queue item, while a different intentional key creates another. Read-only callers cannot edit sources or access another tenant's source. Revocation blocks new requests and replay, without silently cancelling previously accepted queues. A different tenant's worker leaves those queues untouched. The correct native worker then records two actual provider submissions and two `Sent` queues; Mailpit must contain exactly two matching messages with expected recipient, sender, resolved parameters, native Contact personalization, text alternative, unsubscribe link and queue-item MIME attribution. Another worker pass changes neither submission evidence nor queue state and creates no duplicate capture.

These results establish local E02 HTTP/API-key/worker/capture acceptance, not transactional-mode rules, public sending-domain verification, public callbacks or external-inbox delivery. Those remain E01/P and the combined Phase 6 exit gate.

## Cleanup And Evidence

Cleanup removes working records, users, credentials, service grants and subscriptions belonging to these fixtures. Native security audit entries are append-only and **are not deleted or modified**. Their minimal parent tenants are retained with `suspended` status; they cannot authenticate or execute workflows. No audit trigger is disabled and no foreign-key constraint is bypassed. Setup errors roll back. A crashed runner can leave a temporary active fixture that requires the same ownership-checked cleanup helper.

Screenshots contain synthetic workflow data only. Browser traces and automatic failure screenshots are disabled to avoid persisting login credentials. The default desktop/mobile run reports public-delivery skips separately from local passes. Run `scripts/dev/verify.ps1` and the responsive `automation-workspace.spec.js` suite as regression checks. These checks do not close transactional email, email experiments, installation acceptance or public Postal requirements in the [Phase 6 closure plan](phase-6-closure-plan.md).

Run the acceptance harness and repository database suite **sequentially**. Rollback suites hold locks for their entire fixture; running committed-fixture cleanup against them can time out even though the application assertions pass. Cleanup skips tables without fixture-owned rows and never removes another tenant's records. Separate worker processes inside the acceptance harness remain intentionally concurrent.
