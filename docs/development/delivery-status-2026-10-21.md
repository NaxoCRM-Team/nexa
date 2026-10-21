# Delivery Status: 21 October 2026

## Current Work

Phase 8 covers M14 Conversations/Bots and M15 Messaging Channels. The calendar
iteration is Sprint 07 (12-25 October); phase numbers are not sprint numbers.

The implementation and final local acceptance are delivered through
[PR #164](https://github.com/NaxoCRM-Team/nexa/pull/164). Its GitHub state is the
authoritative publication/review record. Local acceptance is tracked separately
in [#165](https://github.com/NaxoCRM-Team/nexa/issues/165); live acceptance is #163.

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
- [x] One clean updated combined acceptance run, including all 60 browser cases.
- [x] Commit/push the implementation, provide the delivery PR and separate local
  acceptance/publication from blocked deployment work on the GitHub board.

The final gate passed 2,152 backend checks, three migration-response checks and
all 60 browser scenarios (26.3 minutes), followed by repository and whitespace
verification, in one invocation. Earlier failing/targeted runs are not counted
as this final result. GitHub source, schema/upgrade and visual CI passed for
executable revision `b70a2a53` (450 frontend cases passed; 280 live-only skips).
See [combined evidence](phase-8-combined-acceptance.md).

## External Gate

[Issue #163](https://github.com/NaxoCRM-Team/nexa/issues/163) is on the organization
board as **Blocked**, M15, R4 Channel Suite, without a future sprint commitment.
It covers real providers, public HTTPS, real Cloudflare operations, production
website/mailbox acceptance, sustained production load and off-host disaster recovery.
See the [fixed external checklist](phase-8-external-acceptance.md).

The WAMP log also reports rejected Google mailbox token refreshes. Reconnection
and live receive/reply acceptance require the mailbox owner's authorization;
credentials were not reset or disabled by this work.

Phase 8 umbrella #162 must remain open until external gate #163 is accepted.
Native shared-inbox #131 and local acceptance #165 close through PR #164; they are
not evidence that real-provider or production-infrastructure acceptance is done.
Phase 7 public-website gate #159 and Postal public-delivery gate #152 remain
separate; this Phase 8 work does not close them.
