# Phase 7 Scoring Foundation

## Grouped Conditions And Native Monetary Inputs - 14 October 2026

The existing scoring editor now authors nested AND/OR condition groups. A group awards its signed points once, in its selected Fit/Engagement category; matching individual conditions never multiply that award. Each evaluation retains the full condition tree and matched/unmatched leaf evidence. Supported limits are four group nesting levels, ten children per group, 100 total leaf conditions per model and the existing 50 scoring-rule limit. Native field ACL, tenant/service scope, custom-property schema fingerprints, publication immutability, replay and scheduled execution apply to every leaf, including unmatched OR branches. Group rules do not offer inactivity decay; the existing standalone behavior-rule decay remains unchanged.

Account `annualRevenue` and Opportunity `amount` reuse their native fields and currency companions. Every monetary condition has an explicit enabled comparison currency, independent of later base-currency changes. Inputs use the existing native `Currency`/`Converter::convertWithRates` APIs with the current tenant rate profile; no second exchange-rate provider or financial record is introduced. Evaluations retain original amount/code, normalized values, relevant rates, base currency, rate mode/provider and effective date. Native 14-place conversion precision is retained for comparisons; cross-currency scoring requires BCMath and fails clearly if unavailable. Missing/disabled currencies or unavailable rates fail closed; there is no silent one-to-one or global-rate fallback. Currency updates affect new evaluations, not saved history. Custom monetary properties remain excluded until their separate storage/currency contracts are supported.

Test in **Automation > Scoring & Lifecycle**: create an Account or Opportunity model, select **Add condition group**, choose All (AND) or Any (OR), and add conditions/subgroups. Revenue/amount conditions expose **Comparison currency**, using currencies enabled in the existing tenant Administration currency settings. Preview shows group explanations and a Currency evidence section. Published models retain their original definitions; old standalone rules and history remain compatible. Native styles no longer override hidden published-model controls.

Evidence: 65 stateless rule checks, 98 native rollback-only scoring checks (including scheduled groups and monetary field privacy), tenant currency isolation and customer foundation runtime regressions passed. 60 browser cases across five viewports passed, with responsive screenshots inspected. Two real authenticated WAMP desktop/mobile scenarios passed (40.7 seconds), including grouped Account currency authoring, native record selection, publication, conversion, persisted history and foreign-record rejection. No new migration is needed. Repository `-Ci` verification passed; full Phase 7 clean-install/concurrent-worker acceptance is still pending. Work remains local, uncommitted and unpushed.

## Fit And Engagement Breakdown - 14 October 2026

The existing model rule editor now supports Fit and Engagement categories. Evaluations and new history entries retain each category's raw and bounded totals and contribution category. Combined scoring, decay, manual adjustment and native lifecycle projection remain unchanged. Manual adjustments remain a separate overall-score component, not falsely attributed to either category. Negative raw category evidence is retained; bounded display categories need not add up to the combined score when penalties cross category boundaries.

Existing published snapshots are not rewritten: rules without a category infer Engagement for canonical behavior inputs and Fit for profile inputs. Existing historical entries retain their original result shape. Draft saves explicitly persist the selected category. This adds no database migration or parallel native entity. AND/OR and native monetary inputs were pending at this slice and are now delivered above. ABM, personalization and combined Phase 7 acceptance remain pending.

Verification: 47 stateless definition checks and 81 rollback-only native scoring checks passed. Responsive browser and repository verification results are recorded in the tracking issue after completion. Work remains local, uncommitted and unpushed.

## Governed Adjustments And Lifecycle Policies - 14 October 2026

Migration `0082` adds a tenant/service/model/record-scoped adjustment ledger. Tenant administrators with native record edit and score read permission can add a non-zero signed adjustment with a required reason and optional UTC expiry. Reversal appends a new entry; neither the original reason nor its audit history is deleted. Request keys bind normalized input and make retries idempotent. Recalculations always start from the model score and apply active adjustments once, bounded to the supported score range. Adjustments do not carry into a replacement primary model. The ledger and its reasons are administrator-only.

Published Contact and Account primary models may opt into qualification thresholds. Policies promote through native lifecycle stages, never demote records, and preserve Opportunity, Customer, Evangelist, Other and unknown stages. Preview never changes native data. Persisted promotion requires native lifecycle edit permission and uses the existing lifecycle recorder/outbox, attributed to the evaluated tenant actor. Published policies are immutable with the model snapshot.

UI: **Automation > Scoring & Lifecycle**. Configure lifecycle thresholds on a draft, save and publish it, select primary ownership, then choose a record to preview/evaluate or add/revoke an adjustment. Expiry is reflected on the next evaluation; enable automatic recalculation for unattended expiry processing. The editor currently exposes the Lead/MQL/SQL threshold sequence; the validated backend also supports Subscriber policies. Reasons are not exposed in general customer score timeline metadata.

Targeted evidence: 42 stateless definition/policy checks, 81 native rollback-only scoring checks, customer foundation runtime isolation checks and 45 browser cases across five viewports passed. Browser controls use API doubles; this slice does not claim a fresh real authenticated browser-to-worker/concurrent-race exit gate. Migration `0082` applied incrementally to WAMP, leaving existing records untouched. Phase 7 remains open; changes remain local and uncommitted.

## Primary Score Ownership And CRM History - 14 October 2026

Tenant administrators can select one published Contact model and one published Account model as the primary CRM score. Migration `0081` enforces uniqueness per tenant, service and subject with a nullable generated key. Existing models remain unselected; choosing or switching a model does not rewrite every record immediately. New persisted evaluations, including scheduled evaluations, project into the existing native `leadScore` under native record-edit and score-read permission checks. Opportunity models remain standalone evaluations.

Projection, evaluation evidence and the existing customer recorder's history share one transaction. The central ORM ownership hook rejects direct or workflow score adjustments while a primary model owns that subject, and only accepts backend projection options that reference a matching persisted evaluation. Releasing or archiving ownership keeps current scores and restores unowned native scoring behavior. Ownership changes are audited. Lead conversion retains its source score but does not propagate it over an owned Contact or Account score.

Changed values produce one canonical `ScoringEvaluation` timeline entry containing model, version, before/after score, band and actor, never private rule inputs. Replay and unchanged scores do not duplicate score-change entries. Contact and Account activity views reuse their existing timeline layouts and render these audit entries as read-only, without pin/delete or nonexistent native-record links. Timeline readers verify scoped evaluation provenance and score-field read access. Native Contact save history continues to drive the existing `score.reached` trigger rather than introducing another trigger pipeline.

Verification: 65 rollback-only scoring runtime checks, 30 scoring-authoring browser cases, ten focused Contact/Account timeline rendering cases, unowned two-tenant Lead conversion and customer-foundation runtime regressions passed. Repository `-Ci` verification passed. The broader native workflow suite passed 1,762 checks including its 36-row trigger/action permission matrix, preserving existing unowned workflow behavior. Browser cases use doubles; these are not a full authenticated browser-to-cron acceptance claim. Migration 0081 is applied locally. Current changes remain local and uncommitted. Governed manual adjustments, lifecycle policies, ABM, personalization and full Phase 7 acceptance remain pending.

## Scheduled Recalculation And Decay - 14 October 2026

Published models now have opt-in automatic recalculation at one, six or 24-hour intervals. Native `RecalculateScoringModels` runs each minute in the existing tenant-scoped scheduler. Migration `0080` adds schedule state to the existing model table; it does not introduce a second scheduler or modify existing records' scores. All existing models start disabled.

Each tick considers at most five due models and ten records per model. A stable cycle key, upper record-ID bound and durable cursor bound the scan. Evaluation history and cursor advancement share a transaction and the tenant-before-model lock order, so a rolled-back batch cannot leave a half-advanced scan. Publication rules remain immutable. Archiving or disabling a model stops future batches. Processing uses the enabling administrator's current native ACL and rejects inactive, deleted, downgraded or wrong-tenant/service execution identities. Missing entitlement pauses the schedule with a diagnostic; invalid configuration or identity disables it until an administrator explicitly re-enables it.

Positive Contact behavior rules can optionally reduce their contribution after a configurable inactivity grace period, repeating every configured number of whole UTC days. Reduction is capped at the original contribution, cannot turn it negative, and resets when a newer eligible event arrives. Example: 30 points, seven-day grace, five points removed every three days yields 25 at day ten and 20 at day thirteen. Decay uses the newest distinct canonical event in the rule's window, excluding future, restricted and wrong-source evidence. When the window expires the rule no longer matches. Native/custom fit properties and negative rules do not decay.

Model results retain behavior age, last eligible event time, base contribution, reduction, cutoff, inputs, version and definition hash. At this scheduled-recalculation slice, native `leadScore` remained untouched. The primary-ownership delivery above now explicitly opts models into native projection; it does not implicitly change unselected models.

Local evidence: 31 rule checks, 48 rollback-only native runtime checks and 25 browser cases across five viewports passed. A final ten-case desktop/mobile rerun passed after the layout correction, and repository verification with `-Ci` passed. Native tests include bounded batches, replay-before-due rejection, actor revocation, archiving and tenant scope. Browser tests use API doubles; simultaneous independent worker-process races and a real authenticated scheduling-to-cron browser gate remain part of full Phase 7 acceptance. Migration 0080 was applied through the checksum-tracked runner, and rebuild provisioned current tenant jobs. This work remains local and uncommitted.

## Custom Property Scoring - 14 October 2026

Contact and Account models now reuse active, enabled tenant custom properties from Objects & Properties. Supported scalar types are text, single-select, boolean and number. Currency, dates, multiselect and relationships remain unavailable until their comparison contracts are implemented; there is no implicit currency conversion.

Stored numbers remain decimal strings for exact comparisons. Boolean false is a comparable value, not missing data. Missing stored values use the existing property default. Rules bind a fingerprint of the property identity, type, options, default and validation; a structural change requires a new model draft instead of silently changing a published model. Disabled or archived properties fail closed, including evaluation replay. All value reads include tenant, service, entity type, record and property identity.

The existing authoring UI lists these properties by their business label with a custom marker and reuses configured option selectors. Native Contact/Account screens and their designs are unchanged. No database migration is required for this extension.

At this custom-property slice, automatic recalculation and inactivity decay were still pending; the scheduled delivery above supersedes that statement. Primary-score projection, lifecycle automation and full Phase 7 acceptance remain pending.

Verification on 14 October: 22 scoring definition checks, 36 rollback-only native runtime checks, 15 browser cases across five viewports (including accessibility and custom option authoring), and repository verification with `-Ci` passed. Browser cases use API doubles; the runtime fixture uses real tenant custom definitions and scoped stored values. No real-user browser-to-worker or scheduled-decay acceptance is claimed. WAMP cache and application timestamp were refreshed. This extension remains local and uncommitted.

12 October 2026. Phase 7/M13 is in progress, not complete. Phase 6 public transport acceptance remains externally blocked and has not been waived.

## Delivered Boundary

Open **Automation > Scoring & Lifecycle**, or the current tenant's `NexaScoring` route.

- Tenant administrators create and edit drafts for native Contact, Account and Opportunity records.
- Rules select approved, field-readable native properties, configured enum values, bounded positive/negative points and deterministic comparisons.
- Native record selection, instant catalogue search, sorting, draggable/resizable columns and internal table scrolling are available.
- Preview shows each rule's actual input, match and contribution. Numeric comparisons reuse exact-decimal helpers rather than floating-point thresholds.
- Publication retains a definition snapshot and hash; published rules are immutable. Archiving preserves evaluations.
- Administrator-recorded evaluations have stable request keys, input snapshots, actor/time, model version and hashes. Replay returns the original result rather than recomputing changed inputs.
- Tenant, service, native record and field permissions apply to catalogue, preview and history. Published definitions are checked against their immutable snapshot before evaluation.
- Publication is gated by an active automation plan/service entitlement. Its existing plan-service configuration can set `scoringModelLimit` to 5 or 50; an eligible plan defaults to 5, missing/inactive/expired entitlements give zero. Plan data is never editable through the scoring API. A downgraded workspace must archive excess published models before recording more evaluations.

Migration 0079 adds model/version/evaluation governance, not replacement CRM tables. It changes no existing record scores, plan configuration or credentials. Existing Contact/Account `leadScore` values and workflow increase/decrease actions remain untouched; model results are separate until primary-score ownership and projection are delivered.

This foundation supports approved native properties and the bounded Contact behavior counters described below. Monetary fields are deliberately excluded until currency-aware scoring can reuse native conversion. Custom-property scoring is not yet advertised. The current catalogue/storage safety cap is 200 models; broader archived-model browsing and retention must be resolved before the full M13 exit gate.

## Behavioral Counters And Bands - 13 October

Contact models now include scoped website page views, email opens, clicks and replies over 7, 30, 90 or 365 days. These reuse the canonical event/source vocabulary already used by email behavioral conditions. Counts deduplicate source IDs, exclude restricted visibility, future and out-of-window evidence, and fail rather than silently truncate above 10,000 distinct events. A single UTC cutoff is retained with each evaluation.

These are count-based conditions: a matching rule awards its points once, not once per event. Evidence expires from the selected window on the next evaluation. Gradual decay and scheduled recalculation are not implemented by this extension.

The editor supports 1-10 ascending score bands starting at zero. Defaults are Cold (0), Engaged (21), Marketing qualified (51) and Sales ready (81). Evaluations retain the effective thresholds and band, including historical evidence. Editing a saved draft requires saving before publication/evaluation; published rules remain immutable. Bands classify model results only; they do not silently change a Contact lifecycle stage or overwrite workflow scores.

Targeted extension checks passed: 19 stateless rule/threshold checks, 27 native isolation/evidence checks and desktop/mobile authoring/accessibility tests. Behavioral fixture evidence is rollback-only timeline projection data, not a new live Postal/tracking intake test. No new migration is required. Phase 7 remains open for the packages below.

## Verification

Native rollback tests cover draft/preview/publication, quota rejection, foreign tenants/services/records, staff management denial, field-level denial, Contact/Account/Opportunity subjects, immutable definitions, input replay, archive retention and inactive entitlements. Stateless rules test positive/negative contributions, missing values, invalid operators/options and exact large decimals.

The browser tests cover rule authoring, field errors, instant search, explanation, escaping, responsive framing and WCAG labels. Real local desktop/mobile acceptance uses native login, the actual tenant route, actual Contact selection, HTTP publication and persistent evaluation history. Test identities are random and disposable; credentials stay in memory and traces/screenshots are disabled in live runs.

Recorded results: 12 stateless definition checks, 24 native model/isolation checks, ten browser cases across five viewports, and two real authenticated desktop/mobile scenarios passed. The final live run completed in 27.2 seconds. `scripts/dev/verify.ps1 -Ci` passed; the full database regression suite has not been rerun for this Phase 7 increment. Desktop/mobile screenshots were inspected. WAMP rebuild/cache/timestamp refresh completed; no configuration or password changed.

```powershell
powershell -ExecutionPolicy Bypass -File scripts/dev/verify-automation-acceptance.ps1 -Suite Scoring -BaseUrl http://localhost/nexa -PhpPath C:\wamp64\bin\php\php8.2.29\php.exe -Projects desktop,mobile
```

## Remaining Phase 7 Packages

1. Customer-history personalization now has a local backend bridge, native audience picker, expiring consent-gated identity proof, current permission enforcement and authenticated WAMP acceptance. External tenant-site deployment remains an integration acceptance task; the PHP adapter example is not an automatically installed website plugin. See [personalization decision contract and audit-preservation boundaries](phase-7-personalization.md).
2. The combined functional suites, schema replay, independent-worker races, maximum backend capacities and 20-round native tenant worker progress now have recorded results. Global queue-selection fairness remains unresolved: the native selector is FIFO and changing its shared behavior needs explicit approval. Fresh application-installation and checksum-ledger acceptance are distinct from schema replay. See [the current Phase 7 exit gate](phase-7-exit-gate.md).
3. Complete the documented model browsing/retention and CRM model-history presentation boundaries before full M13 acceptance.

The native target-account, account-list and orchestration package is delivered locally; see [ABM implementation and boundaries](phase-7-abm.md). Its bounded tests do not close the full Phase 7 scale/concurrency gate.

Delivered locally: native/custom scalar scoring, behavior counters, Fit/Engagement categories, grouped conditions, native Account/Opportunity monetary inputs, scheduled recalculation/decay, primary ownership, governed adjustments, forward-only lifecycle policies and canonical CRM score timeline history.

Work is local and uncommitted. Phase 7 issues and its exit gate remain open.
