# Delivery Status: 21 October 2026

## Current Work

Phase 8 covers M14 Conversations/Bots and M15 Messaging Channels. The calendar
iteration is Sprint 07 (12-25 October); phase numbers are not sprint numbers.

The accumulated local implementation is on `phase8/conversations-acceptance-20261021`.
The release is not yet merged. This note will be reconciled against the final
acceptance result and GitHub release state before delivery.

## Fixed Local Checklist

- [x] Native Email/InboundEmail conversation workspace and local configured
  SMTP/IMAP acceptance, without replacing existing mailbox integrations.
- [x] Website chat, verified visitor identity, guided bot flows and staff handoff.
- [x] SMS, WhatsApp and Messenger adapters, composers, consent/DNC, delivery-state
  processing, bounded retries, uncertain-send recovery and quotas.
- [x] Incoming attachment validation, private native tenant-library admission,
  authorized previews/downloads and interrupted-upload cleanup.
- [x] Four-channel customer timeline and governed automation source integration.
- [x] Native Team/User routing, availability, capacity and tenant-fair processing.
- [x] Chat retention, soft deletion/restoration, legal hold and erasure recovery.
- [x] Synthetic database restore drill through migration 0109, preserving tenant
  records and ledger state, followed by unchanged migration replay.
- [x] Fail-closed migration inspection and three fake-client response regressions.
- [ ] One clean updated combined acceptance run, including all 60 browser cases.
- [ ] Commit, push, review and reconcile GitHub delivery status.

The native gate passed 2,152 checks; three additional migration-response checks
also passed. The first updated browser run passed 57/60; the three previously
failing cases passed a targeted rerun after readiness/API-response adjustments.
That is not recorded as a clean uninterrupted combined pass. The next full run
and release verification are in progress. See [combined evidence](phase-8-combined-acceptance.md).

## External Gate

[Issue #163](https://github.com/NaxoCRM-Team/nexa/issues/163) is on the organization
board as **Blocked**, M15, R4 Channel Suite, without a future sprint commitment.
It covers real providers, public HTTPS, real Cloudflare operations, production
website/mailbox acceptance, sustained production load and off-host disaster recovery.
See the [fixed external checklist](phase-8-external-acceptance.md).

The WAMP log also reports rejected Google mailbox token refreshes. Reconnection
and live receive/reply acceptance require the mailbox owner's authorization;
credentials were not reset or disabled by this work.

Phase 8 umbrella #162 must remain open until its remaining gate is accepted.
Native shared-inbox issue #131 can close after its local implementation is merged.
Phase 7 public-website gate #159 and Postal public-delivery gate #152 remain
separate; this Phase 8 work does not close them.
