# Phase 6 Native Email Capability Audit

Nexa retains EspoCRM's native `EmailTemplate`, `MassEmail`, `EmailQueueItem`, `CampaignLogRecord`, `CampaignTrackingUrl`, inbound sender configuration, scheduler and ACL behavior. These records already carry mandatory tenant and service ownership in the shared schema and remain the authoritative delivery model.

M10 adds governance that the native model does not provide: content versions, review and approval state, consent-purpose binding, delivery modes, sending-domain ownership, provider reconciliation, plan limits and canonical Phase 5 delivery events. The first slice stores those additions in `nexa_marketing_email_*` tables linked one-to-one with native Mass Email records.

The Nexa Marketing Email workspace replaces the old user experience but does not introduce another template, queue or campaign-log system. Draft creation writes through EspoCRM record services. Scheduling and sending remain disabled until the provider, domain, suppression and callback slice is accepted.

Postal is the initial marketing-delivery adapter, not a domain model. Provider identifiers and payloads stay behind an adapter boundary so tenants and future deployments are not coupled to Postal.
