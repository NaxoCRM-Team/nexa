# Phase 7 Exit Gate

Status: **In progress**. Functional acceptance is not production-scale acceptance.

## Fixed Closure Checklist

| Deliverable | State | Evidence / Remaining Work |
| --- | --- | --- |
| Scoring, grouped conditions and native currency inputs | Implemented locally | Native definition and model suites; responsive editor and authenticated WAMP tests |
| Scheduled decay, primary scores and governed adjustments | Implemented locally | Native model suite and independent-worker contention acceptance passed |
| Account-based marketing using native Accounts and Target Lists | Implemented locally | Native ABM suite, responsive and authenticated WAMP tests |
| Email conditional content and explanations | Implemented locally | Native rendering suite and responsive editor tests |
| Contextual and customer-backed website decisions | Implemented locally | Consent, proof, expiry, current permissions, isolation and authenticated WAMP tests |
| Read-only personalization decision history | Implemented locally | Scoped reader tests, retained published snapshots and browser coverage |
| Scoring history presentation | Bounded | Latest 20 evaluations and 50 adjustments; explicit overflow flags; older rows retained, not deleted |
| Combined functional acceptance | Passed locally, 15 October 2026 | Seven native suites, 95 responsive browser tests and six authenticated WAMP scenarios |
| Clean and incremental schema migration through 0084 | Passed locally, 15 October 2026 | Disposable databases; existing Contact/Account preserved; clean/incremental column definitions match; repeated Phase 7 DDL passes |
| Fresh native application installation and checksum ledger | Passed locally, 15 October 2026 | Real runner applies 84 migrations, skips all unchanged entries and rejects an altered applied copy without ledger changes; native administrator, CRM persistence, Phase 7 services and idempotent bootstrap verified |
| Independent scoring/ABM worker contention and archive safety | Passed locally, 15 October 2026 | Separate PHP processes with a start barrier; exact batch counts, no duplicate evaluations, one ABM refresh, stale-edit conflict and archive race |
| Quota and supported-capacity acceptance | Passed for documented backend bounds, 15 October 2026 | 2,043 volume checks; explicit maximum/overflow rejection and retained history verified; not a production-throughput SLA |
| Repeated native worker execution across tenants | Passed locally, 15 October 2026 | 20 concurrent busy/small tenant rounds through native JobRunner; small tenant advances each round |
| Global queue-selection fairness | Approval required | [#157](https://github.com/NaxoCRM-Team/nexa/issues/157); native platform enumeration is FIFO by job number; changing shared scheduling requires approval |
| External tenant website integration | Not deployed | [#159](https://github.com/NaxoCRM-Team/nexa/issues/159); server-side PHP example provided; owner-controlled website deployment required |

Fresh application installation and the real migration runner's checksum ledger are tracked in
[#158](https://github.com/NaxoCRM-Team/nexa/issues/158). The Phase 7 umbrella
[#156](https://github.com/NaxoCRM-Team/nexa/issues/156) remains open while its required gates remain unresolved.

Do not close the Phase 7 umbrella while required migration, concurrency and supported-capacity
evidence is missing. Do not conflate the separately blocked public Postal/HTTPS acceptance with
local Phase 7 functionality. External-site deployment must be reported separately from tests
of the local backend bridge.

## Repeatable Functional Acceptance

```powershell
powershell -ExecutionPolicy Bypass -File scripts/dev/verify-phase7.ps1 `
  -PhpPath C:\wamp64\bin\php\php8.2.29\php.exe -Live
```

The runner executes native database suites sequentially, responsive browser suites and then
desktop/mobile WAMP scenarios. It stops on the first failure. Existing acceptance fixtures
create disposable identities and keep credentials out of browser traces and screenshots.
It does not change or bypass native permission, consent or tenant/service enforcement.

To include disposable-database migration replay, supply
`-MigrationClientPath C:\wamp64\bin\mariadb\mariadb11.4.9\bin\mariadb.exe`.
The standalone test is `tests/development/Phase7MigrationReplayTest.ps1`. It generates unique
database names, creates without claiming an existing database, and drops only databases it
successfully created. It never targets the working `espocrm` database. The incremental
baseline ends at migration 0078; migrations 0079 through 0084 add the Phase 7 schema.
Direct DDL replay is distinct from a complete application installation and from checksum-ledger
skip behavior; neither of those is claimed solely from this test.

## Retention Boundary

Archiving disables a scoring model; it does not delete versions, evaluations or adjustments
and does not free a stored-model slot. The stored-model limit and active published-model
entitlement are different limits. The current reader windows are not retention policies.
No feature-specific audit purge or silent evidence deletion is permitted by this gate.

## Recorded Functional Evidence

The combined runner completed successfully on 15 October 2026. All seven native suites
passed sequentially. The 95 responsive browser tests passed in 2.7 minutes. Authenticated
desktop/mobile scenarios passed for scoring (43.3 seconds), ABM (29.9 seconds) and
personalization (29.9 seconds). Each live suite used the existing fixture cleanup path.
These results do not close the separate pending rows in the checklist above.

The separate schema replay also passed on 15 October 2026: the native base schema plus all
migrations through 0084 installed cleanly; the 0078 baseline upgraded with existing Contact
and Account rows intact; all six Phase 7 tables have required tenant/service columns; identity
proof foreign keys exist; clean and incremental column shapes match; repeating Phase 7 DDL
preserves the Contact. Both uniquely named disposable databases were removed afterward.
The working application database was not targeted. This is schema acceptance, not a fresh
interactive application-installation test or a checksum-ledger skip/rejection test.

## Fresh Installation And Ledger Acceptance

`tests/development/Phase7InstallationAcceptance.ps1` passed on 15 October 2026 using PHP 8.2.29
and MariaDB 11.4.9. Supply `-FreshInstallation` and `-MigrationClientPath` to the combined runner
to include it. The test copies repository source into a private temporary tree, excludes live
runtime configuration, creates a unique empty database and database-scoped user, then runs
the actual migration runner and native CLI installer. It never targets the working database.

- All 84 migrations apply and produce a complete ledger.
- Replaying unchanged SQL skips every entry and preserves all ledger values.
- Altering an applied SQL file in the temporary copy is rejected without changing the ledger.
- Native installation writes configuration, rebuilds, creates an administrator and sets the installed marker.
- Verification reports 265 tables, 253 tenant columns, 237 service columns and 84 migrations.
- The administrator password is verified with native hashing; Account/Contact creation and reload
  retain the expected relationship and tenant/service ownership; Phase 7 services load successfully.
- A second bootstrap recognizes the matching installed configuration and exits without reinstalling.
- The owned temporary database, scoped database users and application tree are removed afterward.

This exposed and fixed missing pre-rebuild system-user bootstrap and administrator email/ownership
handoff. The native system-user rebuild action and central tenant ORM remain responsible for
persistence. New native CLI installations require a valid `ADMIN_EMAIL` (or an email-shaped
`ADMIN_USERNAME`). Existing installed configuration is not rewritten. This is CLI application
installation acceptance, not an interactive browser installer or public website deployment test.

## Independent Worker Evidence

`verify-automation-acceptance.ps1 -Suite Phase7Workers -Projects desktop` runs the committed
fixture harness in separate PHP processes, releasing workers only after each signals readiness.
The final run passed in 10.5 seconds on 15 October 2026. The combined Phase 7 runner includes
this suite when `-Live` is supplied.

Verified against disposable, uniquely named tenants:

- Two scoring workers process exactly one ten-record batch, with one evaluation per record.
- A second contested batch advances the cursor to 20 unique records while another tenant
  processes its own ten-record batch independently.
- A scoring/archive race finishes archived and unscheduled, retains prior evaluations, and
  subsequent ticks create no further evaluations.
- Competing ABM workers perform one due refresh with one projected native Contact membership.
- Two edits with the same expected list version yield one save and one explicit version conflict.
- Competing publications at the plan boundary yield one publication and one quota rejection;
  the other tenant's published-model count is unchanged.

No production locking or scoring/ABM calculation changes were needed for these tests.
The fixtures use the real native services and existing cleanup path. This evidence is bounded:
it does not measure sustained throughput, maximum 5,000-account projections, starvation under
continuous load or general cancellation/retry behavior outside these Phase 7 worker paths.

## Capacity And Repeated Load Tests

`Phase7CapacityChecks.php` is loaded only by the opted-in CLI fixture. It creates representative
volume inside a transaction belonging to a uniquely named disposable tenant, calls native
services for acceptance, and rolls volume data back before fixture cleanup. Synthetic SQL is
used to seed volume, not as a replacement for the production Contact/Account creation path.

The volume checks cover 5,000 target Accounts (including assigned owners), 5,000 Contacts in
an account summary and projected audience, 200 stored account lists, 200 stored scoring models,
10,000 distinct behavior events, 25 retained evaluations with a 20-row view, 51 retained
adjustments with a 50-row view, and keyset continuation across 2,001 audit rows. Each relevant
first-over-limit case must reject explicitly; a rejected audience refresh must preserve the
previous 5,000 memberships. These are backend/service limits, not browser-rendering or
production-throughput SLAs. Aggregate stage timings are attached to the browser test report.

The test exposed per-record detail loads in ABM list/summary reads. These now use the native
Account/Contact collection service with strict native ACL selection, explicit tenant/service
checks and field checks, plus request-local owner reuse. No native model or ownership rule is
replaced. The 34-check ABM isolation/permission regression and repository CI verification pass.

Repeated-load verification passed separately in 1.3 minutes: a 501-Contact busy tenant and
a 21-Contact smaller tenant run native background jobs concurrently for 20 rounds. The busy
tenant produces 200 unique evaluations and retains a cursor/backlog. The smaller tenant
advances in every round and completes repeated scans. This proves bounded execution progress
when both tenant jobs are dispatched; it does not prove that the global FIFO selector will
dispatch them fairly. No shared QueueUtil production change has been applied. Such a change
affects all platform job types and needs explicit approval and wider scheduler regression tests.

Final standalone maximum-capacity verification passed: 2,043 assertions in 4.4 minutes on
15 October 2026. Stage durations were Accounts 13.917 seconds, audiences 198.734 seconds,
scoring 37.017 seconds and history 8.074 seconds. Durations include fixture insertion and
multiple native operations; they are not single-request response times. The Account inputs
include assigned owners, and ownership display was checked. All volume rows were rolled back
and the existing tenant-fixture cleanup completed. Initial runs were deliberately stopped to
replace the two identified detail-load bottlenecks; only the final standalone run is acceptance
evidence for the capacity result.
