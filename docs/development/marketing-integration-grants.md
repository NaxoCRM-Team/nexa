# Marketing Integration Grant Foundation

Migration `0069_marketing_integration_send_grants.sql` stores explicit grants for an active native API user and one approved source version in the current tenant/service. WAMP has received this additive migration. The reusable-send endpoint now accepts granted API users with native read permissions; grants never confer native editing or approval permissions.

`MarketingIntegrationAccess` supports administrator-only grant/revoke operations. Grants expire after 1 to 720 hours. Revocation is immediate; renewing a grant clears its revoked state. A source revision does not inherit an earlier version's authorization. Runtime checks reject inactive/deleted/non-API users, foreign tenant/service contexts, expired/revoked grants and sources no longer approved at that version. Native MassEmail, EmailTemplate and Contact read permissions remain required.

Issuing or renewing a grant locks the approved source inside the same transaction as the grant and audit event. It verifies native content and sender/delivery settings against the immutable approval snapshot and rechecks the persisted API user's active state under a row lock. Revocation follows the same source-first locking order. Rejected renewals preserve the existing grant and append no successful grant event. The rollback-only fixture covers changed subjects, changed sender addresses and API users disabled after loading.

The rollback-only reusable-send fixture verifies explicit grants, version separation, tenant rejection, duration limits, forbidden self-granting, revocation and expiry using a persisted synthetic native API user. It also exercises the real reusable-send action with native read-only role ACL: queued identity, owned replay, safely resolved parameters, inherited approval provenance, native attachment bytes, ordinary write/approval denial and worker suppression. It does not exercise HTTP authentication with a real API key or claim external-inbox delivery acceptance.

## Administrator API And Audit

The authenticated administrator endpoint is `/api/v1/Nexa/marketing-emails/{sourceProfileId}/integration-grants`. GET returns the latest 100 source-specific grants. POST accepts exactly:

```json
{"action":"grant","userId":"NATIVE_API_USER_ID","sourceVersion":1,"hours":24}
```

For revocation, use `action: "revoke"` with userId and sourceVersion, omitting hours. Native source edit ACL and tenant/service scope remain required. Unknown fields, permission overrides and incorrect JSON types are rejected. Grant/revoke mutations and their existing marketing-email audit events share a transaction, or a savepoint inside an outer transaction. Audit evidence retains the administrator, target API user, source version, timestamp and granted duration. Repeated revocation is a no-op, not another deletion event. Tests exercise the real action and inspect actor/payload audit evidence.

## Workspace Controls

### Request Ownership

The reusable request ledger passes through `requireRequestOwner` before returning an existing dispatch. Tenant/service must match. Integration callers must own the original request and still have an active grant for its source/version; a shared template grant does not permit reading or replaying another caller's request key. Tenant administrators retain scoped source-edit authority for troubleshooting. Tests verify a granted caller's own request, wrong caller, wrong service and immediate replay denial after revocation.

Tenant administrators can open Integration access using the key icon on an email row in Marketing Email. Select a user with the native picker, choose 1 to 720 expiry hours and grant the current approved version. The server accepts only active API users in that tenant/service; choosing another user type produces an inline error. The dialog lists the latest 100 grants and offers confirmed revocation. Unapproved emails permit listing/revocation but not issuing a new grant. Escape/Close restores focus, refreshes reject late responses and keyboard focus remains usable after a revoked row loses its button. Five-viewport isolated browser tests cover payloads, selection, errors, revocation and cancellation; they do not claim authenticated native-picker acceptance.

## Restricted Approved Copies

The API user supplies only the source version, Contact, request key and declared plain-text parameters. `approvedIntegrationCopy` derives sender, reply-to, campaign, consent purpose and content from the scoped immutable approved source; the caller cannot override them. It creates native EmailTemplate, MassEmail and attachment aliases inside the request transaction. The copy retains the source administrator's approval identity/time and records both the API actor and explicit inherited-approval provenance. Saved and approved asset manifests support the unchanged final worker checks. Attachment aliases retain the original storage backend, including local files created before a switch to Cloudflare R2.

The private integration queue path requires request ownership and a still-active version grant. The public single-send, workspace create/edit and approval paths remain administrator-only. Source locks serialize grant revocation and revision with a send request. Consent, opt-out, suppression, recipient ACL, provider readiness, capacity and the per-source request rate limit remain enforced; delivery is asynchronous through the native queue. Revocation blocks future requests and owned replay, but does not cancel an already accepted queue item. Existing pause/review controls apply to accepted work, and the worker rechecks recipient eligibility before submission.

## API-Key Tenant Routing

On a shared installation such as `http://localhost/nexa/api/v1/`, send the native `X-Api-Key` header. The request bootstrap and tenant middleware resolve ownership from that persisted key rather than the host's default tenant. Resolution requires an exact case-sensitive match, an active undeleted API user using the ApiKey authentication method, an active tenant and the active CRM service. Missing matches and duplicate matching credentials fail closed. Caller-supplied tenant IDs are not trusted. Native API-key login still authenticates the request after ownership discovery; native role ACL and marketing grants still authorize its actions. API keys must never be included in query strings or logs, and production requests must use HTTPS.

`TenantApiKeyAuthenticationTest.php` tests native PSR tenant middleware and native key login with rollback-only credentials for two synthetic tenants on the same localhost URL. It rejects invalid, case-changed, repeated-header, inactive, deleted and wrong-method credentials, checks duplicate-key ambiguity and verifies context restoration. The reusable-send fixture also passes its authenticated request through that middleware/login/action chain before creating and replaying a native queue item. No test keys or config secrets are published or persisted. This is in-process acceptance, not a live authenticated HTTP socket test of the entire authentication-hook pipeline.

## Live WAMP HTTP Acceptance

`TenantApiKeyHttpTest.php` is an opt-in loopback-only test through Apache and the full native authentication-hook pipeline. It creates uniquely named committed API-user/role fixtures for the two demo tenants because Apache cannot see rollback-only credentials. Both tenants use the same site URL and native `X-Api-Key` authentication. App/user returns HTTP 200 with the exact non-admin API actor and no browser login token; case-changed and disabled keys return 401. Marketing creation, approval, grant administration and native Contact creation return 403. Missing-source reusable requests fail without creating any mail queue.

The live test exposed an empty NexaSegments scope that caused permission-map generation to return HTTP 500. Its metadata now follows the existing non-entity workspace scope pattern. This is a metadata correction, not a new entity or schema migration.

Run explicitly on WAMP:

```powershell
Set-Location C:\wamp64\www\nexa
& 'C:\wamp64\bin\php\php8.2.29\php.exe' tests/tenant/TenantApiKeyHttpTest.php --allow-local-fixtures
```

The test reads siteUrl but accepts only localhost/127.0.0.1, disables redirects/proxies, never prints keys, creates no delivery queue and does not modify customer records or tenant policies. Cleanup first deactivates all fixture keys, then removes only that run's identities, roles, role links and generated user preferences/data. Append-only authentication audit remains. Fixture cleanup is verified even after failures. The ordinary verification script checks existence/syntax but does not automatically run this committed-fixture test. Local live authentication and permission acceptance passed on 8 October 2026; this does not prove HTTP queued-send acceptance, public deployment or external Postal delivery. No API user is temporarily marked as admin, and workspace write permissions are unchanged.
