# Phase 5 Event Ingestion Contract

Nexa records behavioral events through one tenant and service scoped boundary. Native EspoCRM activities remain the source records for calls, meetings, tasks, notes and operational email; the event platform projects those records and website behavior into one canonical customer timeline.

## Standard events

The first version accepts page, landing-page, link, form, asset, video, webinar, purchase and email-reply events. Tenant integrations may publish namespaced `custom.*` events. Arbitrary unnamespaced event names are rejected so later automation and reporting do not depend on accidental spellings.

Each event carries a version, source, idempotency key, correlation identifier, occurrence time, category, consent category and bounded JSON properties. URLs accept only HTTP and HTTPS and may not contain credentials.

## Consent

Optional analytics, preference or advertising events require a matching `granted` value in the consent evidence. Necessary events such as a requested form submission or purchase may be recorded without optional tracking consent. The stored consent snapshot explains why the event was accepted at that time.

## Identity resolution

Anonymous browser and session identifiers are tenant and service scoped before hashing; raw identifiers are never persisted. Supplying a Contact identifier is not sufficient to link an anonymous visitor. The caller must also provide verified evidence from an authenticated session, verified email, form submission or OAuth subject.

Visitor identities created by the earlier foundation are upgraded from the legacy hash to the tenant and service scoped hash when they are next observed, preserving their existing history.

The evidence reference is hashed before storage. An existing visitor link cannot be silently changed to another Contact. Cross-tenant Contact and Account identifiers are rejected by the central ownership check.

When trusted evidence links a visitor to a Contact, Nexa atomically assigns all earlier events for that tenant, service and visitor to the Contact. The Contact's current Account is inherited when available, and matching rows are projected into the canonical customer timeline without duplication. Future events from the same visitor inherit the verified Contact automatically; the same raw visitor key in another tenant or service remains unrelated.

Website-category events reconcile the Contact's latest website visit. The Contact Activity tab reads the canonical timeline through the native record ACL boundary and displays page, landing page, link, form, content, webinar, purchase, email-reply and custom behavior in chronological order.

## Idempotency and delivery

The tuple of tenant, service, source and idempotency key identifies one event. Retries return the original event identifier. Event storage, identity resolution, historical backfill, customer-timeline projection and transactional-outbox publication occur in one database transaction. Timeline records require both tenant and service ownership.

The outbox remains the replay boundary for downstream scoring, automation, tracked email and attribution consumers. Those modules must not write directly to behavioral-event tables.

Tenant administrators can request a replay with an explicit reason and caller-supplied replay key. The request is tenant and service scoped, idempotent, recorded separately from the immutable source event, published through the transactional outbox and written to the security audit ledger. Replaying never inserts a second canonical event or customer-timeline row.

Consumers process `behavior.event.replay.requested` messages using the replay request identifier as their delivery key. Processing status and any bounded failure message remain on the replay request, preserving the original event payload and occurrence time.

## Public website collection

Every tenant website is registered as a service-owned tracking source. A source has a rotatable 48-character public key, an active or paused state, a consent integration mode and up to 20 exact approved origins. Production origins must use HTTPS; HTTP is accepted only for localhost development. Wildcard origins are not supported.

The collector echoes an approved request origin and never returns `Access-Control-Allow-Origin: *`. Browser credentials are omitted. Requests are limited per source and caller fingerprint, and the complete decoded payload is limited to 96 KiB before canonical validation.

Public source keys are configuration identifiers, not authentication credentials. A browser request cannot provide a Contact ID, Account ID or identity evidence. It records an anonymous visitor only; later identification must use the trusted resolution evidence described above.

Three consent modes are supported:

- `managed` validates optional events against the latest published Nexa cookie receipt for the visitor.
- `external` requires the external consent categories, provider and policy version with every optional event.
- `necessary_only` accepts only event types classified as necessary.

Global Privacy Control always suppresses advertising events. The browser runtime waits for the Nexa consent client in managed mode, exposes `window.NexaTracking.track` for deliberate custom events and assigns stable browser and tab-session identifiers without sending cookies or CRM credentials.

Rotating a source key immediately disables the old embed code. Pausing a source rejects collection without deleting its historical events.

## Retention and legal holds

Tenant administrators govern behavior data from the existing Tracking & Events workspace. Identified-customer events default to 730 days, anonymous events default to 90 days and completed replay requests default to 90 days. Administrators may select an identified window from 90 days to 7 years, an anonymous window from 30 days to 2 years and a replay window from 30 days to 1 year. Anonymous retention cannot exceed identified retention.

A legal hold requires a reason and blocks both scheduled and manual deletion for the tenant and service. Policy changes and completed manual or effective scheduled purge runs are written to the existing hash-linked security audit ledger.

The native EspoCRM scheduler runs one tenant-context-aware retention job daily. Each run removes at most 5,000 eligible events and at most 5,000 terminal replay requests or orphan visitor identities. Events with queued or processing replay requests, or unpublished outbox messages, are not eligible. Removing an event also removes its canonical behavior timeline projection and published event outbox payload in the same transaction. No job performs an unscoped cross-tenant sweep.

Retention indexes cover tenant, service, identity state and occurrence time. The administration workspace reports identified, anonymous, total, oldest and currently eligible event counts without introducing a second event store.
