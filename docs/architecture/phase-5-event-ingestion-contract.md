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

## Idempotency and delivery

The tuple of tenant, service, source and idempotency key identifies one event. Retries return the original event identifier. Event storage, identity resolution, customer-timeline projection and transactional-outbox publication occur in one database transaction.

The outbox remains the replay boundary for downstream scoring, automation, tracked email and attribution consumers. Those modules must not write directly to behavioral-event tables.

Tenant administrators can request a replay with an explicit reason and caller-supplied replay key. The request is tenant and service scoped, idempotent, recorded separately from the immutable source event, published through the transactional outbox and written to the security audit ledger. Replaying never inserts a second canonical event or customer-timeline row.

Consumers process `behavior.event.replay.requested` messages using the replay request identifier as their delivery key. Processing status and any bounded failure message remain on the replay request, preserving the original event payload and occurrence time.
