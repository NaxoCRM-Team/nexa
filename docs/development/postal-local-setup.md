# Local Postal Delivery

This separate Docker stack adds Postal to the WAMP development environment. It does not replace WAMP, modify the CRM database server, or send external email. Postal delivers exclusively to Mailpit on an isolated network.

## Start

From the repository root with Docker Desktop running:

```powershell
powershell -ExecutionPolicy Bypass -File scripts/dev/setup-postal-local.ps1
$php = 'C:\wamp64\bin\php\php8.2.29\php.exe'
& $php scripts/dev/configure-postal-local-ca.php
& $php tests/integration/PostalDockerTransportTest.php
& $php tests/integration/PostalGovernedDispatchTest.php
& $php tests/integration/PostalWebhookAcceptanceTest.php
& $php tests/integration/PostalPlainTextAcceptanceTest.php
& $php tests/integration/PostalCampaignAttachmentAcceptanceTest.php
& $php tests/integration/PostalCampaignAttachmentFailureTest.php
```

The setup is repeatable: existing credentials are preserved and Postal runs missing schema migrations when its database already exists.

- Postal administration: https://localhost:8443
- Captured messages: http://localhost:8026
- Administrator email: `admin@postal.local.test`
- Administrator password: `POSTAL_LOCAL_ADMIN_PASSWORD` in `.local/postal/.env`.
- Local API credentials: `.local/postal/config/local-credentials.json`.

Generated passwords, API credentials, signing key and certificates are ignored by Git. Never commit or reuse them in production. The browser may warn about the local development certificate. The PHP configuration command trusts its CA only for loopback Postal requests; certificate verification remains enabled.

The opt-in transport test temporarily configures a tenant/service provider inside a database transaction, sends through the real Nexa HTTPS adapter, verifies SMTP delivery in Mailpit, then rolls back the provider changes. It does not prove full campaign dispatch, callback delivery, quota enforcement or external inbox placement. Existing production domain-verification requirements are not bypassed.

The separate governed-dispatch test extends the existing two-tenant fixture. It creates a consented Contact, audience and campaign, approves and schedules a native MassEmail, then runs the real dispatcher against local Postal. It verifies Contact personalization, unsubscribe content, provider submission, SMTP capture, exactly one monthly allowance reservation, repeated-worker idempotency and foreign-tenant control rejection. All CRM changes are rolled back; captured test messages remain in Mailpit. Domain verification flags are explicit test fixtures, not a production bypass. Run against the development demo database only.

## Manage

```powershell
docker compose --env-file .local/postal/.env -f compose.postal.yaml ps
docker compose --env-file .local/postal/.env -f compose.postal.yaml logs --tail 100 postal-worker postal-web
docker compose --env-file .local/postal/.env -f compose.postal.yaml stop
docker compose --env-file .local/postal/.env -f compose.postal.yaml start
```

Do not delete Docker volumes to restart the stack: they contain its configuration databases and captured messages. Only HTTPS administration and Mailpit viewing ports are exposed, on loopback. Database and SMTP ports are not exposed. The isolated development database uses a generated root credential; a public deployment must use appropriately restricted database credentials.

## Administrator Test Sends

In the Marketing Email draft editor, use Add file to select an existing tenant file or upload a new supported file. Uploads remain in the tenant library (Cloudflare R2 when configured); saving copies the native attachment relationship into the draft and approval validates its file manifest. Supported marketing types are PDF, TXT, CSV, JPG, PNG, GIF and WebP, limited to 5 MiB per file, 10 MiB combined and 10 attachments. Uploading alone never sends mail.

In Marketing Email, approve a saved email and use the flask icon (Send test to my email). Choose a Contact only for personalization; the recipient is the authenticated tenant administrator's own email address, selected on the server. A test uses one monthly send, reuses native `EmailQueueItem.isTest`, and does not change campaign approval or appear in customer delivery totals. Tests are limited to one per minute for each administrator/email pair. Selected attachments must match the approved file manifest before delivery.

Run `php tests/integration/PostalTestSendAcceptanceTest.php` with the local Postal stack running to verify real HTTPS-to-SMTP test delivery, personalized HTML/text, allowance accounting, cooldown and tenant isolation. CRM fixture records are rolled back. An uncertain response holds the submission and pauses the email for evidence review; it is never automatically retried. Local Mailpit delivery is not proof of external inbox delivery.

## External Delivery Requirements

The campaign attachment acceptance commands above verify the complete local scheduled-send path, approved file bytes, consented recipient, pause/resume, single allowance charge and repeat-worker safety. The failure fixture changes an attachment after scheduling and verifies that no provider submission or allowance charge occurs. These require the local capture-only Docker stack; they do not enable external mail delivery.

`php tests/integration/PostalAttachmentAcceptanceTest.php` tests the attachment transport foundation against the local capture stack. It sends a small TXT MIME attachment, then checks its filename, content type and received bytes using Mailpit's message API and raw MIME source. The editor allows existing tenant-file selection and approval snapshots; `php tests/tenant/TenantMarketingAttachmentTest.php` verifies their tenant scope and restoration. After explicit user approval, campaign and administrator-test dispatch now include approved attachments. Run `php tests/integration/PostalGovernedAttachmentAcceptanceTest.php` to verify the governed administrator path with a synthetic attachment. These local capture tests send no external email and do not establish real-customer deliverability.

`PostalPlainTextAcceptanceTest.php` additionally verifies the native plain-text template type, literal text preservation, escaped HTML fallback and the unsubscribe URL. HTML campaign tests verify both MIME alternatives using actual captured SMTP content.

The local fixture deliberately marks a `.test` sender domain verified inside Postal and uses a Mailpit relay. Neither is a production configuration. Real delivery requires a public Postal deployment, TLS, sending-domain DNS verification, SPF/DKIM/DMARC, appropriate reverse DNS and outbound SMTP connectivity, plus a reachable authenticated Nexa delivery-event endpoint. Provider throughput and deliverability must be tested before enabling customer campaigns.

Postal is transport, not the billing authority. Nexa continues to enforce tenant/service scope, consent, suppression, queue ownership and the calendar-month 20-times marketing-contact-tier allowance. Provider caps may further restrict sends; they do not increase the Nexa allowance.

Official references: [Postal containers](https://docs.postalserver.io/other/containers/), [prerequisites](https://docs.postalserver.io/getting-started/prerequisites/), and [Postal v3 architecture](https://docs.postalserver.io/getting-started/upgrade-to-v3/). The pinned Postal v3 stack does not require RabbitMQ.

## Verification Evidence

On 2026-10-05, the local Docker stack initialized successfully and `PostalDockerTransportTest.php` passed: Nexa submitted through verified HTTPS, received a Postal message ID, and the Postal worker delivered the message by SMTP to Mailpit. No external email was sent.

`PostalGovernedDispatchTest.php` additionally covers the complete local campaign-to-SMTP path described above. Queue completion is not proof of inbox delivery.

`PostalWebhookAcceptanceTest.php` also waits for Postal's actual `MessageSent` HTTP callback to an unexposed, token-protected capture receiver on the isolated Docker network. It then submits that exact captured envelope to Nexa's authenticated callback service in the same rollback-only fixture, verifying delivered status, retry idempotency, one Contact timeline event, one downstream event and invalid-token rejection. Callback captures are ignored JSONL files under `.local/postal/config`. This tests the real Postal wire payload and Nexa reconciliation, not public ingress: the public WAMP API endpoint still requires a separate deployed acceptance test because another HTTP connection cannot see the test's uncommitted CRM fixtures. External deliverability also remains pending.
