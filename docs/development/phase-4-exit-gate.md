# Phase 4 Exit Gate

Phase 4 delivers the tenant-safe consent, content, audience and campaign foundation across modules M08 and M09. Nexa retains native EspoCRM Contact, Target List, Campaign and attachment foundations where suitable, and adds governed capabilities only where the native model does not satisfy the unified product specification.

## Delivered By This Workstream

- Purpose-based Contact consent, legal basis, evidence, policy versions, expiry, channel decisions and append-only audit history.
- Cookie categories, regional display rules, Global Privacy Control, banner publishing, integration modes and idempotent visitor receipts.
- Versioned forms, conditional fields, public submissions, lead creation, submission governance and tenant-safe runtime events.
- Tenant asset governance over native files, including versions, access scope, download controls and event history.
- Versioned landing pages, professional templates, governed forms/assets, public publishing, SEO controls and tracked interactions.
- Marketing-contact eligibility, static and dynamic segments, exclusions, previews, recalculation, exports and membership history.
- Anonymous visitor identity and canonical behaviour-event ingestion for later scoring, automation and attribution modules.
- Persistent public-endpoint rate limiting, signed landing destinations, output escaping, CSP and browser security headers.

Campaign definitions and enrollment are owned by the parallel campaign workstream. Marketing email belongs to M10 and is not part of this gate.

## Automated Evidence

Run the workstream gate from the repository root:

```powershell
powershell -ExecutionPolicy Bypass -File scripts/dev/verify-phase-4.ps1 `
  -PhpPath C:\wamp64\bin\php\php8.2.29\php.exe
```

The gate performs:

1. Complete repository, architecture and Phase 4 contract verification.
2. Clean installation from the pinned EspoCRM schema through migration `0054`.
3. Incremental upgrade from migration `0042` through `0054`, preserving an existing tenant Contact.
4. Migration checksum immutability and idempotent replay of development seeds.
5. Two-tenant consent, cookies, forms, assets, landing pages, segments, behaviour events and public-rate-limit tests.
6. Authenticated desktop and mobile browser checks for every delivered Phase 4 workspace.

Temporary databases are restricted to `nexa_phase4_clean_test` and `nexa_phase4_upgrade_test` and are removed after execution.

## Security Boundary

- Every authenticated operation requires tenant context, service entitlement and appropriate ACL access.
- Public requests resolve tenant/service ownership from opaque published identifiers rather than request-supplied tenant IDs.
- Public form submissions and visitor receipts are rate limited by a privacy-preserving request fingerprint.
- Landing-page click destinations require a tenant-bound HMAC signature and reject unsafe or protocol-relative redirects.
- Public pages and files set restrictive content, framing, referrer and MIME-sniffing policies.
- Tenant isolation and rate-limit buckets are verified with two synthetic tenants.

## Deferred Production Readiness

- Google mailbox production verification remains tracked in GitHub issue `#132` and is not a Phase 4 functional dependency.
- Campaign integration remains the final Phase 4 dependency. The phase umbrella stays open until the campaign branch is merged and the combined consent-to-segment-to-campaign flow passes this gate.

## Recorded Result

The Nexa-owned Phase 4 workstream passed locally on 29 September 2026 against WAMP, PHP 8.2.29 and MariaDB 10.11. Repository verification, clean installation, incremental migration replay, tenant/security runtime suites, and authenticated desktop/mobile workspace checks passed. Final whole-phase acceptance is conditional on merging and testing the parallel campaign workstream.
