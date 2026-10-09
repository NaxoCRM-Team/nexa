# Phase 6 Delivery Status

Audit date: 9 October 2026. Phase 6 remains open. This document is an implementation ledger, not an exit-gate certificate.

## Existing Email Delivery

The native MassEmail, EmailTemplate and EmailQueueItem models remain authoritative. The governed marketing dispatcher supports approval, scheduling, suppression, purpose-specific consent, native opt-out, tenant/service boundaries, sender verification, immutable content, published images, attachments and the effective monthly allowance of 20 times the marketing-contact tier.

Personalization supports native scalar fields, exact decimal custom properties, boolean/text/select custom properties, supported recorded website/email windows, grouped conditions and sanitized rich branches. Reusable approved-source sends support parameter contracts and scoped idempotency keys. These sends are still marketing sends, not transactional email.

See [the capability audit](../architecture/phase-6-native-email-capability-audit.md) for acceptance evidence and limitations. Do not treat local provider capture or rollback-only API tests as public delivery acceptance.

## Automation Foundation

The installed native application has no workflow engine to extend. Two new backend contracts prepare the custom orchestration module without replacing native CRM operations:

- `WorkflowDefinition` validates a bounded directed acyclic graph, enrollment policy, distinct step identifiers, reachable nodes, complete connections, branches, bounded waits, working-hour schedules and allowlisted action settings.
- `WorkflowClock` calculates persisted UTC due times for elapsed delays and local working hours, including daylight-saving transitions.

Canonical definition fingerprints ignore canvas node order and JSON key order. Publication must preserve immutable definitions and assign versions; fingerprints alone do not create versions.

Webhook steps reference a configured webhook ID, never an arbitrary URL. Email steps reference an approved source and version; they cannot override the sender, tenant or delivery policy. Referenced records still require tenant/service ownership, record and field ACL, plan and eligibility checks when publishing and executing. The graph validator does not authorize these references.

The supported contract includes trigger names and action names needed by the future engine. Their presence in the validator does **not** mean trigger subscriptions or action execution are implemented.

The [workflow runtime](workflow-runtime.md) now adds migration `0070`, immutable publication, manual Contact enrollment, durable branches/waits, cancellation, audit APIs and the native `ProcessWorkflows` job. Its runtime/API-wrapper suite passes 71 rollback-only checks. Automatic triggers and actions are explicitly rejected at publication, not silently accepted. The visual canvas and full action/trigger execution remain pending.

Focused verification:

```powershell
& $php tests/tenant/TenantAutomationDefinitionTest.php
& $php tests/tenant/TenantAutomationClockTest.php
```

These two contract tests are database-independent. They establish 58 graph-validation checks and 22 scheduling checks; the separate runtime suite covers database and API-wrapper isolation. None establishes end-to-end automation acceptance or a delivered visual canvas.

The complete local `scripts/dev/verify.ps1` run also passed on 9 October 2026, including the existing CRM, consent, campaign, email governance, personalization, reusable-send and tenant-isolation suites. That regression result does not satisfy the missing Phase 6 end-to-end or public Postal gates.

## Remaining Deliverables

| Deliverable | Status and required work |
| --- | --- |
| Transactional email | Missing. Define explicit purpose, lawful-basis and suppression policy; approved transactional templates; separate dispatch and allowance semantics; UI/API; delivery-time rechecks and acceptance tests. Do not bypass marketing consent by relabeling a send. |
| Visual automation | Backend runtime now covers scoped immutable versions, manual enrollment, branches/waits, cancellation and history. Editor/canvas, automatic trigger subscriptions, richer conditions and action leases remain pending. |
| Automation actions | Missing. Invoke existing native/Nexa services under the workflow execution identity; enforce ACL, consent, quotas, tenant fairness and stable action keys; distinguish safe retries from uncertain external submissions. |
| Email A/B testing | Missing. Persist deterministic audience assignment, approved variants, measurement windows, reconciled results and explicit winner selection. Protect privacy and account for unreliable open signals. |
| Programmable-send live acceptance | Partial. Local API authentication and rollback-only queue tests exist; a successful live HTTP queued-send test with worker isolation remains required. |
| Public Postal acceptance | External setup required. Public sending domain, DNS, reachable signed callbacks and an external test inbox are needed. Automated evidence inspection of uncertain submissions remains pending. |
| Personalization extensions | Custom dates and advanced event counts/path aggregation remain unsupported. |
| Combined exit gate | Pending. Verify customer enrollment through delivery, canonical events, branches, recovery, cancellation, permissions, service/tenant separation and duplicate prevention together. |

Keep Phase 6 and its delivery issues open until the remaining deliverables have operational evidence. Local implementation is not equivalent to production acceptance.
