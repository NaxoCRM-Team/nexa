# Phase 6 Native Email Capability Audit

Nexa retains EspoCRM's native `EmailTemplate`, `MassEmail`, `EmailQueueItem`, `CampaignLogRecord`, `CampaignTrackingUrl`, inbound sender configuration, scheduler and ACL behavior. These records already carry mandatory tenant and service ownership in the shared schema and remain the authoritative delivery model.

M10 adds governance that the native model does not provide: content versions, review and approval state, consent-purpose binding, delivery modes, sending-domain ownership, provider reconciliation, plan limits and canonical Phase 5 delivery events. The first slice stores those additions in `nexa_marketing_email_*` tables linked one-to-one with native Mass Email records.

The Nexa Marketing Email workspace replaces the old user experience but does not introduce another template, queue or campaign-log system. Draft creation writes through EspoCRM record services. Scheduling and sending remain disabled until the provider, domain, suppression and callback slice is accepted.

Postal is the initial marketing-delivery adapter, not a domain model. Provider identifiers and payloads stay behind an adapter boundary so tenants and future deployments are not coupled to Postal.

## Delivery readiness boundary

Migration `0063` adds tenant/service-owned provider configuration, sending-domain readiness, suppression and immutable delivery-event storage. Provider credentials use the existing encrypted managed-secret store and are never returned to the browser. A tenant is delivery-ready only after a credential exists and at least one exact sending domain passes ownership, SPF, DKIM, DMARC and custom return-path checks.

Postal remains responsible for accepting and delivering messages, retrying temporary failures and monitoring its configured domains. Nexa owns campaign consent, tenant limits, scheduling authorization, callback idempotency, canonical customer events and suppression decisions. Postal documents that its current HTTP API is intentionally limited to message operations, while sending domains and their DNS records are administered in Postal and checked by its workers: https://docs.postalserver.io/developer/api/ and https://docs.postalserver.io/features/sending-domains/.

Migration `0064` adds the native-queue-to-provider message map and monthly usage ledger. The public callback uses a 48-character one-time token whose SHA-256 hash is stored per tenant and service; administrators can rotate the URL, and repeated provider events are idempotent. Hard bounces and complaints enter the tenant suppression list automatically.

`MarketingDeliveryProvider` is the transport boundary. `PostalDeliveryProvider` retrieves the tenant's encrypted API key only at runtime and submits through Postal's HTTPS message API. It does not select recipients, bypass consent, construct a second campaign queue or own scheduling. The governed dispatcher must continue to select and render native `EmailQueueItem` records before invoking this adapter.
