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

## External Delivery

The local fixture deliberately marks a `.test` sender domain verified inside Postal and uses a Mailpit relay. Neither is a production configuration. Real delivery requires a public Postal deployment, TLS, sending-domain DNS verification, SPF/DKIM/DMARC, appropriate reverse DNS and outbound SMTP connectivity, plus a reachable authenticated Nexa delivery-event endpoint. Provider throughput and deliverability must be tested before enabling customer campaigns.

Postal is transport, not the billing authority. Nexa continues to enforce tenant/service scope, consent, suppression, queue ownership and the calendar-month 20-times marketing-contact-tier allowance. Provider caps may further restrict sends; they do not increase the Nexa allowance.

Official references: [Postal containers](https://docs.postalserver.io/other/containers/), [prerequisites](https://docs.postalserver.io/getting-started/prerequisites/), and [Postal v3 architecture](https://docs.postalserver.io/getting-started/upgrade-to-v3/). The pinned Postal v3 stack does not require RabbitMQ.

## Verification Evidence

On 2026-10-05, the local Docker stack initialized successfully and `PostalDockerTransportTest.php` passed: Nexa submitted through verified HTTPS, received a Postal message ID, and the Postal worker delivered the message by SMTP to Mailpit. No external email was sent.

`PostalGovernedDispatchTest.php` additionally covers the complete local campaign-to-SMTP path described above. Queue completion is not proof of inbox delivery.

`PostalWebhookAcceptanceTest.php` also waits for Postal's actual `MessageSent` HTTP callback to an unexposed, token-protected capture receiver on the isolated Docker network. It then submits that exact captured envelope to Nexa's authenticated callback service in the same rollback-only fixture, verifying delivered status, retry idempotency, one Contact timeline event, one downstream event and invalid-token rejection. Callback captures are ignored JSONL files under `.local/postal/config`. This tests the real Postal wire payload and Nexa reconciliation, not public ingress: the public WAMP API endpoint still requires a separate deployed acceptance test because another HTTP connection cannot see the test's uncommitted CRM fixtures. External deliverability also remains pending.
