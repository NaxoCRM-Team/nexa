# Workflow Runtime

This increment delivers tenant/service-scoped storage and a background runtime. It does not yet deliver the visual canvas, automatic trigger subscriptions or action adapters. Unsupported triggers and action steps can be saved in drafts but cannot be published or executed.

## Storage And Identity

Migration `0070_add_workflow_runtime.sql` adds workflow drafts, immutable published versions, durable Contact enrollments and append-only audit events. It leaves native CRM tables unchanged.

Only an active, persisted administrator in the current tenant and service can configure, publish, manually enroll or cancel. The publishing administrator is the execution identity for that version. The worker runs in the native scheduled job's tenant context and rechecks the persisted identity, Contact record access, field access, soft-delete state and service availability. It never changes to a global unrestricted CRM identity.

Each enrollment pins a published version. Saving and publishing another draft does not redirect an existing enrollment to new rules. Version fingerprints are checked before enrollment, resume and execution; corrupted versions fail closed. Draft revision checks reject lost updates.

Request keys are case-sensitive, 8 to 128 characters, and use letters, digits, `.`, `_`, `:` or `-`. Their hashes are unique within a tenant/service/workflow. Replaying a key returns the original enrollment and version, including while paused; a different Contact cannot reuse it. Contact access is still required for replay. `never` prevents subsequent enrollments for that Contact. `after_completion` allows a new key only when every earlier enrollment completed successfully; active, failed and cancelled enrollments do not qualify.

## Executable Steps

- Manual enrollment.
- End/completion.
- Yes/no conditions on allowlisted native Contact properties, with current native field ACL.
- Elapsed delays of one second to one year.
- Condition waits with bounded timeout and checks no more frequently than once per minute.
- IANA-time-zone working hours with selected weekdays and same-day opening/closing times.

Native integer comparisons use exact decimal comparison, not floating-point conversion. Text and enum values are type-checked; enum comparisons require a configured option. Missing values do not satisfy equality, inequality or numeric comparisons; use the explicit presence conditions instead. Tenant custom properties and behavioral conditions are not yet supported by this runtime.

Delay deadlines are measured from entry into the step. Due times and audit timestamps are stored as UTC. Expired condition waits take their timeout branch: a current match cannot prove that a match occurred before the deadline. Business-hour steps remain on that step while waiting and recheck the interval at wake-up, so a late worker cannot bypass a closed interval.

`ProcessWorkflows` uses the existing native scheduled-job infrastructure. Each tenant job processes at most 25 due enrollments and one step per enrollment per batch. Locks use workflow-then-enrollment order, shared by cancellation and publication. Transition and audit writes commit together. There are no external effects in this increment, so no provider retry or uncertain-submission recovery is implied. Future action adapters will require stable action identity, leases and appropriate recovery boundaries.

Pause holds existing enrollments without deleting their state; resume continues their original versions. Archive cancels unfinished enrollments and cannot be undone. Individual cancellation is idempotent. Deleted Contacts and inactive/deleted/demoted execution administrators fail closed. Disabled service scopes hold processing rather than silently completing records.

## API

Paths are relative to the application's configured API base, not a hardcoded hostname or folder.

| Method | Path | Input |
| --- | --- | --- |
| GET | `Nexa/workflows` | None; up to 200 workflows and the actual supported capabilities |
| POST | `Nexa/workflows` | `name`, `definition` |
| GET | `Nexa/workflows/:id` | None |
| PUT | `Nexa/workflows/:id` | `name`, `definition`, integer `expectedRevision` |
| POST | `Nexa/workflows/:id/publish` | Integer `expectedRevision` |
| POST | `Nexa/workflows/:id/control` | `status`: `paused`, `published` (resume) or `archived` |
| POST | `Nexa/workflows/:id/enroll` | `contactId`, `requestKey` |
| POST | `Nexa/workflows/:id/runs/:runId/cancel` | Empty object |
| GET | `Nexa/workflows/:id/history` | None; latest 100 enrollments and 200 audit events |

Read responses are private and no-store. Ownership and execution-user overrides are rejected. There is no API for invoking the system worker, editing published versions, deleting audit evidence or retrying failed actions.

Example definition:

```json
{
  "schemaVersion": 1,
  "trigger": {"event": "manual", "reenrollment": "never"},
  "entry": "qualify",
  "nodes": [
    {"id": "qualify", "type": "branch", "condition": {"field": "leadScore", "operator": "greater_than", "value": 50}, "yes": "wait", "no": "end"},
    {"id": "wait", "type": "delay", "seconds": 3600, "next": "end"},
    {"id": "end", "type": "end"}
  ]
}
```

## Verification

`tests/tenant/TenantWorkflowRuntimeTest.php` passes 71 rollback-only database/API-wrapper checks. Coverage includes revision conflicts, immutable versions, original-version execution, key replay, reenrollment, waits, timing, pause/resume, draft/published archive, cancellation, truthful capability catalogues, API input validation, private responses, deleted Contacts, inactive execution administrators, version tampering and service/tenant isolation, including global service disable. A second PDO connection verifies a lock conflict; this is not a full concurrent-worker acceptance test.

The existing 58 graph checks and 22 clock checks remain. Test fixtures use isolated identities and `.test` email addresses, roll back their data and never submit mail. API-wrapper tests exercise the native request parser and action classes, not a live authenticated browser session.

On 9 October 2026, repository verification passed with the runtime registered in the verification runner. The final global-service guard then passed the focused 71-check rerun. Migration `0070` was applied locally and skipped safely on an incremental rerun. Native rebuild provisioned the workflow job per tenant, and rollback-only testing left no saved workflow fixtures.

Visual editing, automatic enrollment, action execution/retries, plan quotas, workflow-wide fairness, richer conditions and combined end-to-end acceptance remain pending. The batch bound is a worker safety limit, not a billing entitlement or Phase 6 exit-gate certificate.
