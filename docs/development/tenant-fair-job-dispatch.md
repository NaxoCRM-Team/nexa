# Tenant-Fair Native Job Dispatch

The owner approved this cross-platform scheduler change on 15 October 2026 (#157).
It extends native `QueueUtil` selection; it does not replace `QueueProcessor`, `JobRunner`,
job records, scheduled-job exclusion, claims, retry accounting or tenant/service execution context.

## Selection Policy

- Positive bounded global portions rotate eligible tenants, preserving job-number FIFO within each tenant.
- Services belonging to the same tenant share its turn; adding services does not buy extra turns.
- Queue and group filters, pending/deleted state and due-time eligibility remain native.
- A database-backed cursor survives process restarts and serializes selectors for the same queue/group.
- Cursors for different queue/group combinations are independent, including null versus empty values.
- The short cursor transaction finishes before native job locking or execution starts.
- Failed selection rolls back its own transaction or savepoint without consuming a turn.
- Tenant-scoped reads cannot alter the platform cursor or enumerate another tenant's jobs.
- A zero limit retains native unlimited global FIFO enumeration; negative limits reject explicitly.

The cursor advances on **selection**, not successful execution. Selection does not claim or remove
a job. A processor that exits after selecting leaves jobs available for a subsequent processor;
native claim checks remain authoritative. Existing `noLock` callers retain their native semantics.
This does not introduce exactly-once execution for callers that intentionally disable native locking.

For a stable set of eligible nonempty tenants, a tenant receives a turn before another receives
its next turn. This bounds selection starvation, not wall-clock completion: job duration, worker
availability, retries and scheduled-job exclusions still affect execution latency.

## Rollout And Recovery

Migration `0085_add_job_dispatch_rotation.sql` adds a platform-only dispatch-state table.
No existing records are rewritten. Pause cron/daemon dispatch for deployment, apply the shared
migration runner, deploy the matching code, then resume dispatch. The migration must precede
workers using the new selector. Local WAMP migration was applied during acceptance.

Rolling back application code restores native FIFO; the additive cursor table may remain unused.
Do not reset pending/running jobs or delete business records to roll back this selection policy.

## Acceptance

Run `tests/tenant/TenantQueueFairnessTest.php` with local PHP. It uses rollback-only fixtures for
five tenants and two slots over 30 actual native processing rounds, FIFO/context/exclusion checks,
unlimited reads, independent group cursors, failed selection recovery and native retry accounting.

The `Global queue` and `Prefetched` tests in `tests/browser/live-phase7-workers.spec.js` use
independent PHP processes. They cover 20 rounds of competing selectors, an older 40-job busy
tenant versus a 10-job smaller tenant, real native scoring execution, failed transaction cleanup,
two processors deliberately prefetched onto the same scheduled job, and deletion between
selection and claim. Fixtures never dispatch the real main queue.

The combined Phase 7 runner includes these checks. Sanitized aggregate measurements are attached
to the browser report. These are local supported-load measurements, not production capacity SLAs.

On 15 October 2026, the 201-check rollback suite and complete five-test Phase 7 worker suite
passed; the latter took 6.3 minutes including 2,043 capacity assertions and 20 repeated worker
rounds. Broader workflow/email/CRM/Case regressions, clean/incremental/repeated migration replay,
fresh installation through 85 migrations and repository source/security verification also passed.
Git delivery and merge status are tracked in #157; see `phase-7-exit-gate.md` for evidence.
