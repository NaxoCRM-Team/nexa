# Phase 8 Combined Acceptance

This gate verifies the local WAMP implementation. It is not a substitute for
production provider approval, public callbacks or an external delivery test.
The executable runner fails on the first unsuccessful package; it never marks
an unexecuted package as passed.

Status on 21 October 2026: the updated local exit gate passed in one uninterrupted
invocation: 2,152 backend checks, three migration-response checks, all 60 browser
scenarios, repository verification and whitespace checks. Historical partial runs
below are not used as the final pass. Phase 8 production acceptance remains open
under [#163](https://github.com/NaxoCRM-Team/nexa/issues/163).

## Run

```powershell
Set-Location C:\wamp64\www\nexa
powershell -ExecutionPolicy Bypass -File scripts/dev/verify-phase8.ps1 `
  -PhpPath C:\wamp64\bin\php\php8.2.29\php.exe -Live
```

Omit `-Live` for native/database/static verification only. `-Project desktop`
selects one viewport for diagnosis; the default checks all five configured
viewports. Use a single runner at a time: native rollback-only tests run before
committed browser fixtures. The runner restores its environment variables.
It does not enable real dispatch, change credentials or call real providers.

## Coverage

| Package | Acceptance boundary |
| --- | --- |
| Native email | Existing Email/InboundEmail threading, private branch exclusion, stale writes, unread state, receipt replay and background recovery |
| Chat/bots/privacy | Signed visitor capability, identity proof, native ACL, bot choices/handoff, retention, restoration, legal hold and staged file erasure |
| Messaging | Signed ingress, encrypted bodies, preparation/dispatch consent and DNC, cancellation, bounded retries, uncertain submission, exact-attempt callbacks, status regression and isolation |
| Media | SMS/WhatsApp and Messenger validated image/file admission, library visibility, parent/field ACL, authorized downloads and reuse; fake fetchers/local test storage. See [incoming media acceptance](phase-8-incoming-media.md). |
| Native fairness | Real QueueProcessor selection, tenant rotation, FIFO, failed-job retry and scope restoration |
| Capacity/load | 10 widgets, 4,000-character message, 1,000 messages/chat, 10,000 messages/hour, 100-message history pages and 100-row inbox cursor |
| Routing | Four-channel backlog, 20-attempt run limit, 50-active-record agent capacity, repeated native jobs and three independent tenants |
| Cross-channel Contact | Signed SMS/WhatsApp/Messenger plus verified website chat, one Contact foundation, original history, automation-event discovery, permissions and unlink/revocation |
| Browser | Authenticated real APIs, concurrent routing workers, composers/drafts/fake dispatch, responsive layouts and WCAG A/AA checks on included surfaces |

Capacity fixtures use bulk SQL only to build volume. Assertions exercise the
actual stores, services and native queue processor. All newly added native test
data belongs to rollback-only fixtures. Timing and memory figures are measurements
of this local machine, not a production throughput SLA. Twelve two-slot routing
rounds are a bounded sustained workload, not a multi-hour soak test or a proof of
unlimited tenant/conversation capacity.

## External And Unsupported Paths

- Actual SMS, WhatsApp and Messenger activation, delivery/read callbacks and
  provider recovery require approved credentials and a reachable HTTPS endpoint.
- Live Cloudflare R2 file deletion and production CDN downloads have not been
  established by fake-storage/local-file tests.
- SMS/WhatsApp incoming media fetching/storage was implemented after the initial
  combined run. See [incoming-media implementation and acceptance](phase-8-incoming-media.md).
  Its five-viewport focused acceptance passed; real provider/CDN and Cloudflare
  operations remain external checks, not established by local fake transports.
- Local SMTP/IMAP acceptance has a separate Docker sandbox runner,
  `verify-conversation-mailbox.ps1`; the combined gate preserves native email
  behavior but does not claim external Gmail/Outlook delivery acceptance.
- Website production-origin checks, external copies/backups and infrastructure
  disaster recovery are not validated by local browser fixtures.

These boundaries must remain visible when deciding whether to close Phase 8.
Passing local combined acceptance alone must not silently close external or
unsupported paths.

The fixed deployment checklist is [external acceptance](phase-8-external-acceptance.md),
tracked in [GitHub #163](https://github.com/NaxoCRM-Team/nexa/issues/163).
The [recovery runbook](../operations/recovery.md) provides a synthetic local
database restore drill, separate from real infrastructure recovery acceptance.

## Final Local Gate: 21 October 2026

- `verify-phase8.ps1 -PhpPath C:\wamp64\bin\php\php8.2.29\php.exe -Live`
  completed successfully with all five default viewport configurations.
- Backend checks: 79 native email, 153 chat/bot/privacy, 635 messaging/media/routing,
  201 native queue fairness, 1,072 capacity/load and 12 same-Contact cross-channel
  checks (2,152 total), plus three fail-closed migration-client response checks.
- All 60 authenticated browser scenarios passed in 26.3 minutes in this invocation.
  The full browser package took 1,597.61 seconds including runner startup/teardown.
  Repository verification then passed in 75.5 seconds; whitespace passed too.
- Capacity diagnostics: 10,001 message rows, 320 routed conversations, three tenants,
  12 native worker rounds, 24.094 seconds and 20 MiB incremental peak allocation.
  These are bounded local observations, not production throughput guarantees.
- Readiness checks now wait for authenticated shell/API state. Visitor reload
  verifies an HTTP 200 history response with both messages before checking the DOM;
  background cursor polls cannot satisfy that assertion. Contact linking verifies
  its actual successful response. No permission or isolation assertion was removed.
- Synthetic routing and media recovery are driven explicitly by fixture workers,
  not live cron. No active acceptance tenants created on 21 October remained after
  cleanup. Eight older active fixtures dated 14-17 October were left unchanged;
  immutable audits and suspended fixture tenants are retained by design.
- Desktop SMS and mobile WhatsApp screenshots were inspected. Image previews,
  decoded pixels, downloads, authorized library visibility and foreign-tenant
  denial passed in the incoming-media scenarios.
- GitHub CI for executable revision `b70a2a53` passed source validation, the MariaDB
  schema/upgrade job and shell accessibility/visual validation: 450 frontend cases
  passed and 280 live-only cases were skipped there. Those skips are not presented
  as passes; the 60 WAMP cases above are separate live-local acceptance.
- CI's disposable loopback database client now explicitly disables TLS for that
  isolated non-TLS service. Production/provider TLS settings were not changed.
- The [synthetic restore drill](../operations/recovery.md) also passed through
  migration 0109: 394 snapshot checks and unchanged replay of all 109 migrations.
- Delivery is tracked by [PR #164](https://github.com/NaxoCRM-Team/nexa/pull/164)
  and [local acceptance #165](https://github.com/NaxoCRM-Team/nexa/issues/165).
  The external gate remains [#163](https://github.com/NaxoCRM-Team/nexa/issues/163).

## Earlier Checkpoints: 21 October 2026

- Initial focused capacity/load suite: 1,072 checks passed. It created 10,001
  message rows across independent tenants and routed a 320-conversation backlog.
  Three tenants each received eight native turns over 12 two-slot rounds; no job
  assigned over 20 records and no agent exceeded 50 active records.
- Initial timings: capacity 3.850 seconds; routing/load 21.917 seconds; slowest
  routing job 1.089 seconds; total 26.002 seconds; incremental peak allocation
  20 MiB. These are diagnostic local observations, not guaranteed performance.
- Focused same-Contact four-channel test: 12 checks passed using signed local
  receipts and the real SMS/WhatsApp projection worker. It verified channel
  deduplication, governed source discovery, Contact-only role exclusion,
  cross-tenant rejection and immediate removal of unlinked/revoked projections.
- Combined native run passed 2,079 checks: 79 shared-email, 153 chat/bot/privacy,
  562 messaging/routing, 201 native queue fairness, 1,072 capacity/load and 12
  same-Contact cross-channel checks. The native fairness suite exercised five
  tenants, two slots and 30 selection rounds. The combined capacity run measured
  14.125 seconds total and 20 MiB incremental peak allocation.
- The first five-viewport browser run passed 50 of 55 scenarios in 33.1 minutes.
  Its five failures were two shared-inbox five-second readiness assertions, the
  SMS Contact-link race below, a background-tab privacy navigation timeout and
  a widget-save network error. WAMP services remained running; the network error
  was not reproduced and is not described as a fixed server defect.
- After the targeted fixes, `npx playwright test --last-failed --workers=1`
  passed all five previously failing scenarios in 3.3 minutes. Thus all 55 unique
  scenarios passed across the run and reruns, not in a single clean run. Three
  viewport families were also exercised after the SMS fix during the main run.
- Fixed a real SMS UI race: redrawing the linked-Contact controls briefly enabled
  Unlink while the post-link outbox refresh still held the saving guard. A quick
  click was silently ignored. Redrawn controls now remain disabled until saving
  finishes; the browser regression requires an actual successful unlink API
  response before verifying that the Contact link disappears.
- Shared-inbox assertions now allow 15 seconds for asynchronous readiness. The
  privacy test explicitly foregrounds the staff tab after verifying visitor-side
  revocation, with the same readiness allowance. No permission, record-count or
  privacy assertions were removed or weakened.
- Repository CI/static verification, JavaScript syntax and whitespace checks
  passed. Desktop WhatsApp and mobile SMS screenshots were inspected. The final
  cross-channel test also passed after moving it to freshly generated tenant IDs.
- No active committed acceptance tenants from this run remain. No native load
  or cross-channel fixture tenants persisted after rollback. The frontend app
  timestamp was refreshed. No migration or server restart is needed for this
  package. No real provider sends or Cloudflare deletions were made.
- At this checkpoint, work remained local and no git publication or project-board
  closure was performed. Current release status is in [the dated delivery note](delivery-status-2026-10-21.md).
