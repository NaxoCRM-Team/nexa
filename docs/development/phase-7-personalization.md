# Phase 7 Personalization Decisions

M13 / F-051, 14 October 2026. This increment extends the existing marketing email conditional editor and native template renderer. It does not create another email editor or delivery engine.

## Try It

Open Marketing > Marketing Email, create or edit a draft, then open Conditional content. Enter matching and fallback content and choose one or more conditions. Click **Test for a Contact** and select an existing readable Contact. The editor shows the selected branch, each condition's outcome and the rendered content. Editing a condition clears the displayed result. Testing does not insert content into the draft or send email.

## Decision Contract

`POST /Nexa/personalization/decision` accepts the existing conditional-content definition plus `contactId`. Existing administrator, scope, field, native record and tenant/service checks apply. Existing limits remain six flat conditions with All/Any semantics, bounded custom-property values and behavioral windows, and sanitized text/rich branches. There is no public visitor identification or anonymous personalization endpoint in this increment.

Each condition is evaluated through the same native ACL-aware renderer used by preview, using internal branch markers. The actual output is tagged separately and checked against those outcomes; inconsistent evidence fails rather than recording a contradictory decision. Tags are removed before returning content. Tags are inserted after content validation so maximum-sized valid branches do not lose their allowed capacity.

Successful tests append `personalization.evaluated` evidence to the existing scoped audit store: Contact identity, actor, evaluation time, rule definitions, per-condition truth snapshots, selected branch, template fingerprint and rendered-output fingerprint. Raw Contact property values and rendered content are not copied into audit metadata. Boolean branch selection can be reproduced from the stored truth snapshots. Re-running the original historical property/behavior queries requires retained source history; these fingerprints are not a full historical customer snapshot.

The API is authenticated and returns `private, no-store`. The UI uses a sandboxed preview iframe and rejects responses for closed or changed editors. Failed decisions append no evidence. No email queue, delivery provider or allowance consumption is invoked.

## Acceptance Boundaries

The native conditional suite covers branch explanation, AND/OR outcomes, scoped audit persistence, foreign tenant/service rejection, condition limits, rich content and non-admin denial. The conditional editor browser suite covers Contact selection, evidence display, sandboxing and clearing stale results across five viewports using API doubles. Repository verification covers PHP/JS/metadata and existing product contracts.

Final local results: the complete native conditional suite passed with rollback-only fixtures; all five responsive editor cases passed; repository `-Ci` verification passed; `git diff --check` passed. WAMP cache and frontend asset timestamp were refreshed. A new authenticated browser-to-API test of the decision button has not been run; the browser checks here use doubles and the native tests exercise the real renderer/database separately.

Remaining Phase 7 scope includes trusted customer-history targeting and external-site integration, and combined clean/incremental migration, quota, permission, independent-worker concurrency, scale and authenticated end-to-end acceptance. Decision-history browsing and its audit-preservation contract are delivered below; this does not replace platform retention/erasure or storage-capacity acceptance. Do not mark Phase 7 complete based on these slices. No migration is required for these changes; they reuse the existing audit and immutable landing-page configuration schemas. Work remains local, uncommitted and unpushed.

## Contextual Landing-Page Variants

In Marketing > Landing Pages, select a Hero, Text or CTA block. Its existing block settings include **Contextual variant**. Choose Campaign source (`utm_source`), Campaign medium (`utm_medium`), Campaign name (`utm_campaign`) or Page language (`language`), enter the exact matching value and variant heading/body, then preview and publish. Default heading/body remain the fallback. Previewing a variant does not overwrite the default canvas content; variant heading/body are edited in the settings panel.

For example, a published URL ending in `?utm_source=newsletter` selects a configured newsletter variant. Without the matching parameter, the original content is rendered. Language defaults to the published page language unless a bounded explicit language parameter is provided. Matching is case-sensitive. Ten personalized blocks per page and 100-character comparisons/context values are the current bounds.

Rules and variants are stored in the existing draft configuration and validated again at publication. The renderer uses the existing scalar condition evaluator and HTML escaping. Form bindings, assets and redirect destinations cannot be changed by this variant rule. Draft edits do not affect an already published version.

Public rendering ignores Contact IDs, emails, tenant IDs and CRM property parameters. It performs no CRM identity lookup, sets no new cookie and does not infer visitor history. Global Privacy Control suppresses campaign-context variants; language remains a contextual page setting. This is not a substitute for a consented, trusted identity bridge for customer-history personalization.

Personalized page responses use `private, no-store`; public pages retain their existing CSP, signed redirect and request limiter. Each render appends one scoped audit event containing page version, block outcomes, rule fingerprints and hashed context inputs, without raw visitor query values or customer identities. The immutable published configuration plus stored branch outcomes reproduces the selected content. The history reader below exposes those explanations without modifying the source evidence.

The existing Nexa-friendly-route recovery in `public/index.php` previously reconstructed only the entry-point/key/slug query string. It now preserves bounded scalar values for the four approved context parameters; canonical path-derived page identity still overrides browser routing values. This bootstrap integration must occur before the native PSR request is built, so it cannot be implemented solely inside the custom entry point.

Verification: 24 rollback-only contextual/publishing/audit checks passed, the existing native landing-page workspace regression passed, all five responsive variant-authoring cases passed, and two real authenticated WAMP desktop/mobile authoring-to-public-delivery scenarios passed in 42.8 seconds. The real scenarios use separate unauthenticated browser contexts, check GPC/no-store behavior and reject CRM identity spoofing as a targeting input. Disposable fixture credentials stay in memory; live traces/screenshots are disabled. Portable root/subfolder regression passed after the bootstrap query preservation fix. No production-readiness claim is made for customer-history targeting or the full Phase 7 exit gate.

```powershell
powershell -ExecutionPolicy Bypass -File scripts/dev/verify-automation-acceptance.ps1 -Suite Personalization -BaseUrl http://localhost/nexa -PhpPath C:\wamp64\bin\php\php8.2.29\php.exe -Projects 'desktop,mobile'
```

## Decision History And Audit Preservation

**Automation > Personalization**, route `NexaPersonalization`, now opens a read-only decision history over the existing `nexa_audit_event` ledger. Links open the existing Email Content and Landing Pages workspaces; there is no competing content editor. The sidebar entry is limited to administrators. The API independently requires an active administrator whose tenant and service match the trusted context.

`GET /Nexa/personalization/history` supports recorded-date ranges (one year maximum per query), email/landing source filters, decision/record-ID search and bounded keyset continuation. The UI has debounced search, a tenant-scoped native Contact picker, date/source filters, automatic scrolling, movable/resizable columns, sorting of loaded rows, expandable explanations and failure retry. It labels the loaded count rather than claiming a full unfiltered total. Requests return up to 100 scanned rows; the UI stops after 2,000 scanned rows with an explicit display-limit status. Every continuation rechecks current authorization. Stale responses cannot overwrite a changed filter.

Contact records are loaded through the native per-user record service. Email evidence additionally requires the existing conditional-authoring permissions. Conditions are shown only while every referenced property remains in the current readable catalogue; archived/disabled custom properties redact the entire decision explanation and selected branch. Removed or unreadable Contact records are omitted. User names are read through native User permissions and scope, otherwise displayed as Staff member. Anonymous public rendering is not represented as a trusted customer identity.

Email branch reconstruction uses stored boolean outcomes and match mode, not a claim that current customer data matches an old snapshot. Landing explanations load the exact tenant/service/page publication version and verify each stored rule fingerprint before reconstructing its selected heading. Missing snapshots and inconsistent evidence are labelled unavailable, never silently presented as verified. The reader does not return arbitrary audit metadata, IP addresses or user agents.

The default 90 days is a **viewing window, not a retention or deletion period**. No edit/delete endpoint, purge job, duplicate history table or physical retention setting is introduced. The existing append-only audit protections and platform audit-retention authority remain intact. Platform retention, legal-hold, erasure and storage-capacity acceptance are not replaced by these focused reader tests.

The WAMP database currently defaults to local server time. New personalization writes therefore explicitly use `UTC_TIMESTAMP(6)` and mark `auditClock=UTC`; other customer audit writers retain their existing behavior. Older rows are labelled Legacy server time rather than silently converted or relabelled as UTC. Recorded-date filtering uses the stored audit timestamp. The opt-in UTC clock in the shared recorder does not rewrite any immutable event.

Final history verification: 30 rollback-only native checks passed, including archived custom-property redaction, malformed evidence, invalid cursor dates and UTC email writes. Existing customer-foundation runtime and the 24-check landing personalization regression passed. Ten browser filter/scroll/retry/accessibility cases passed across five viewports; responsive screenshots were inspected. Two authenticated WAMP desktop/mobile publication-to-anonymous-delivery-to-history scenarios passed in 25.2 seconds. Repository `-Ci` verification and `git diff --check` passed. WAMP metadata cache and asset timestamp were refreshed. These focused checks do not close the combined Phase 7 exit gate.
# Customer Website Backend Bridge (In Progress)

Migration `0084` adds expiring identity proof over the existing canonical visitor identity;
it does not create a second customer or tracking store. Authenticated workspace administrators
or authorized API users can call `POST Nexa/personalization/identify`,
`POST Nexa/personalization/resolve`, and `POST Nexa/personalization/identity/revoke`.
Credentials belong on the website backend, never in browser scripts or query strings.
The website must independently verify its customer session and map that customer to an
authorized Contact before identification. A submitted email address alone is not proof.

Proof is issuer-, tenant- and service-bound, expires after 24 hours, and can be revoked.
Resolution rechecks current Contact permissions and the latest published cookie configuration:
both preferences and advertising must be granted. Withdrawal, GPC, missing proof or an
unavailable Contact select default content. This is a conservative product policy, not a
claim that one consent configuration satisfies every jurisdiction.

Published customer rules support lifecycle stage, lead status, lead score, audience membership
and native website-visit conditions. The builder currently exposes stage, status, score and
visit controls and a native Target List audience picker. Public landing URLs never
accept customer profile values as trusted context. The backend resolver returns only published
block IDs and static heading/body text, not customer identifiers or extracted profile values.
History rechecks current customer and field permissions before exposing retained decisions.

The server-only PHP adapter example is `scripts/dev/personalization-backend-example.php`.
Instantiate it with a trusted API base URL ending in `/api/v1` and a secret API key from
server configuration. Use the same visitor UUID already recorded by Nexa cookie consent.
After independently validating the website login, resolve the Contact mapping on the server,
then call `identify`. Call `resolve` for each request, forward the browser's GPC preference,
and call `revoke` before ending that website session. Never accept a Contact ID or session
reference directly from an untrusted form. Render returned text with HTML escaping; on API
failure retain default content. Do not cache customer-specific responses publicly.

The example enforces HTTPS except for localhost development, verifies TLS, disables redirects
and uses bounded timeouts. It does not create a website login or bypass consent: the host
website owns those responsibilities. This integration is not automatically installed on tenant
websites, and does not make the anonymous published landing route customer-aware.

Verification and the complete Phase 7 combined exit gate remain required before closing the
phase. The native bridge suite covers API-user roles, issuer/expiry, current field permission
and cross-tenant cases; browser coverage includes the native audience picker.

Latest focused acceptance: customer bridge 21, landing rules 33 and history 30 rollback-only
checks passed sequentially. Five responsive builder tests passed, including numeric comparison
and audience selection. Two authenticated WAMP desktop/mobile scenarios passed in 37 seconds,
covering published contextual content plus consent, identity, resolution, GPC and revocation
through the real HTTP APIs. Fixtures were cleaned up by the existing acceptance runner.
The PHP integration example passed syntax validation; it is an adapter example, not evidence
of deployment to an external tenant website. No additional migration follows `0084` here.
