# Phase 5 Exit Gate

Phase 5 delivers the trusted customer-event platform across M11 and the shared M01 API and outbox contracts. Native EspoCRM activities remain authoritative for calls, meetings, tasks, notes and operational email; Nexa projects behavior and native records into one permission-aware timeline instead of creating a second activity system.

## Delivered By This Workstream

- A versioned behavioral-event catalogue covering page, landing-page, click, form, asset, video, webinar, purchase, email-reply and controlled `custom.*` events.
- Tenant/service-scoped idempotency, correlation, ordering and transactional outbox publication.
- Anonymous visitor and session identifiers protected by tenant/service-scoped hashing without raw browser identifiers in storage.
- Verified identity linking, conflict prevention and atomic backfill of earlier anonymous history to the correct Contact and Account.
- A canonical customer timeline that combines behavior with retained native CRM activity sources and enforces record ACLs.
- Exact-origin public tracking sources with key rotation, pause controls, consent integration modes, Global Privacy Control and bounded collection.
- Idempotent replay requests with reason, status, failure evidence and immutable source-event preservation.
- Separate identified, anonymous and replay retention windows; legal holds; storage metrics; bounded scheduled/manual purge; and hash-linked audit evidence.

## Automated Evidence

Run the Phase 5 gate from the repository root:

```powershell
powershell -ExecutionPolicy Bypass -File scripts/dev/verify-phase-5.ps1 `
  -PhpPath C:\wamp64\bin\php\php8.2.29\php.exe
```

The gate performs:

1. Complete repository, architecture and Phase 5 contract verification.
2. Clean installation from the pinned EspoCRM schema through migration `0061`.
3. An incremental upgrade from the pre-event schema through `0061`, preserving an existing Contact and backfilling its timeline service ownership.
4. Idempotent replay, verified identity backfill, public collection, retention and legal-hold runtime tests against two synthetic tenants.
5. Authenticated desktop and mobile browser checks for the Tracking & Events retention administration experience.

Temporary databases are restricted to `nexa_phase5_clean_test` and `nexa_phase5_upgrade_test` and are removed after execution.

## Security And Privacy Boundary

- Authenticated APIs derive tenant and service from the active runtime context; public collection derives ownership from an opaque tracking-source key.
- Public browser requests cannot supply Contact IDs, Account IDs or trusted identity evidence.
- Optional behavior requires matching consent, and Global Privacy Control suppresses advertising events.
- Exact origin allowlists, payload limits and per-source caller throttles protect the public collector.
- Replay and purge operations require tenant administration, remain scope-bound and write audit evidence.
- Legal holds prevent both scheduled and manual deletion for the affected tenant service.

## Downstream Boundary

Phase 5 establishes canonical events and the reliable outbox boundary. Marketing-email delivery and tracking consumers belong to M10/M12 in Phase 6; scoring and personalization belong to Phase 7; governed attribution and cross-domain analytics belong to Phase 9. Those consumers must use this event contract and must not write independent behavior stores.

## Recorded Result

The complete Phase 5 workstream passed locally on 4 October 2026 against WAMP, PHP 8.2.29 and MariaDB 11.4. Repository verification, clean installation, incremental migration replay, focused two-tenant runtime suites, and authenticated desktop/mobile Tracking & Events browser checks all passed.
