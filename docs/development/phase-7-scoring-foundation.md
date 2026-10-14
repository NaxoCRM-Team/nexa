# Phase 7 Scoring Foundation

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

1. Behavior/custom-property models, grouped fit/engagement conditions and currency-aware company/deal inputs.
2. Scheduled recalculation, expiration/decay, thresholds, manual adjustment and an explicit primary-score ownership policy that preserves workflow contributions.
3. CRM timeline explanations, model/history panels and lifecycle/automation integration.
4. Target accounts, ABM lists and account-based orchestration, reusing native Accounts and relationships.
5. Deterministic personalization decisions using existing rich email conditions, before AI-generated decisions.
6. Combined migration, concurrency, quota, permission, isolation and end-to-end Phase 7 exit-gate acceptance.

Work is local and uncommitted. Phase 7 issues and its exit gate remain open.
