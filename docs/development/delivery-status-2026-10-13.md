# Delivery Reconciliation - 13 October 2026

Current calendar iteration: Sprint 07, 12-25 October 2026. Phases and modules are product scope, not sprint numbers.

## Phase 6

Local automation, governed email, transactional/programmable delivery, uncertainty recovery, email experiments and installation acceptance are complete. The authoritative evidence is the [closure checklist](phase-6-closure-plan.md) and its linked runbooks. Native EspoCRM models, scheduler and ACL remain authoritative.

Issues #151, #153 and #154 cover completed local delivery. Issue #152 and umbrella #150 remain open for public transport acceptance. The board must show these public prerequisites as Blocked, not Done.

Still required before full production acceptance:

- Public sending domain, verified sender and SPF/DKIM/DMARC/TLS.
- Publicly reachable signed Postal callbacks and an owner-approved HTTPS automation receiver.
- External-inbox delivery and receiver-side signature evidence.
- Public bounce, complaint, unsubscribe and uncertain-submission recovery without duplicates.

No public endpoint or sending-domain inputs have been supplied. Local Docker capture is not a substitute for these checks.

## Phase 7

The scoring foundation is implemented and targeted tests pass, but Phase 7 remains In Progress. See [scoring scope and evidence](phase-7-scoring-foundation.md).

Remaining delivery includes richer custom-property scoring, scheduled recalculation and decay, score ownership and timeline integration, lifecycle automation, ABM, personalization and combined Phase 7 acceptance. These are not claimed complete by the Phase 6 release.

Unrelated older open issues remain open until their own acceptance is audited; this reconciliation does not mass-close the backlog.
