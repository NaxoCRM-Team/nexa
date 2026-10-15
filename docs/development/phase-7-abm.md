# Phase 7 Account-Based Marketing

14 October 2026. ABM is implemented locally on the existing CRM foundation; Phase 7 acceptance remains open.

## Native Records And Audiences

- Migration `0083` adds `isTargetAccount` and `targetAccountTier` to the native Account. Existing `assignedUser` ownership remains authoritative. Target status/tier also appear in the existing company details profile; the design and activity tabs are retained.
- The tenant-admin workspace is **Automation > Target Accounts & ABM**, route `NexaAbm`. Non-admin staff retain normal native Account record/field permissions; the ABM management console is administrator-only.
- Static lists select designated target companies. Dynamic lists use bounded AND/OR conditions over approved native company properties, including native ownership. Native `TargetList` and `account_target_list` relationships remain the company membership store. `nexa_abm_list` adds configuration, revision, execution identity, refresh state and diagnostics, not a competing company or campaign entity.
- Each account list has one governed Contact audience in the existing segment store. It uses the same list ID and both primary `contact.account_id` and secondary `account_contact` associations. Contacts are deduplicated; foreign tenant/service links, deleted/non-target companies and account-list opt-outs cannot enter the projection. Refresh preserves Contact-list opt-outs.
- Existing campaigns can select these named audiences and apply their existing purpose/consent, marketing status, channel suppression and exclusion controls. Audience membership is not itself permission to send marketing. Existing `segment.entered`/`segment.exited` workflow triggers consume the standard membership/run events. There is no second automation engine or direct-send path.

## Operational Behavior

The workspace has live account/list search, tier filtering, native Account/User selection, static and dynamic editors, table sorting, column dragging/resizing, contained scrolling and archive confirmation. Generic Lists & Segments labels these as account-based audiences and redirects editing and recalculation to ABM. Generic save, clone and recalculation cannot bypass their governance; the internal projection path is used only after ABM authorization.

Account engagement shows observational counts over the last 30 UTC days across visible associated Contacts: page views, email opens, clicks and replies, plus last engagement. It reuses the canonical event/source vocabulary, excludes restricted/future evidence and retains native permission boundaries. These rollups do not silently replace Account or Contact scores.

Account-list writes are transactional and tenant-before-list locked. Configuration edits require the current version; stale edits fail rather than overwrite newer criteria. Target changes and list save/refresh/archive append audit evidence with the current tenant actor. Static membership intent survives untargeting; its projected Contacts exit until the Account is targeted again. Dynamic refresh safely removes deleted companies.

The native tenant-aware `RefreshTargetAccounts` job checks up to five due lists per tenant. Automatic refresh runs hourly, rechecks the configured active tenant administrator and the existing Automation entitlement, and stops with a non-sensitive diagnostic when configuration/access is unavailable. The ordinary segment job skips these projections so it cannot bypass that execution identity. Manual refresh remains available to an entitled administrator; archive remains available for cleanup after entitlement loss.

Current safety limits: 200 stored account lists, 5,000 target Accounts per workspace, 5,000 associated Contacts per audience/account rollup, 100 leaf conditions/four nested group levels, and 10,000 distinct events per engagement rollup. Excess data fails explicitly rather than being silently truncated. Contact detail previews return the first 50 visible Contacts while counts cover the whole supported association set. Large-scale asynchronous expansion and independent-worker race acceptance remain part of the Phase 7 exit gate, not claimed by these bounded checks.

## Verification

- 34 native rollback-only ABM checks: designation, native ownership, tenant/service and foreign-owner denial, static/dynamic/owner matching, relationship deduplication, consent-governed campaign enrollment, campaign exits, workflow enrollment, opt-out retention, deleted-company cleanup, revision conflicts, audit actor/before-after evidence, due replay, revoked execution identity, entitlement revocation and generic recalculation bypass denial.
- Native Lists & Segments and Campaign suites, plus the existing 98-check scoring suite, passed after integration. Customer foundation runtime isolation and Lists & Segments passed again after the final permission guard.
- 75 combined scoring/ABM/timeline browser cases passed across five viewports; a final 15-case ABM rerun passed. These use API doubles. Screenshots were inspected for responsive containment.
- Two authenticated WAMP desktop/mobile scenarios passed in 33.3 seconds using disposable tenants: native Account creation/designation, dynamic list authoring, real Contact projection, consent suppression, associated-contact rollup, governance markers and rejection of an actual foreign tenant Account. Credentials remain in memory; live traces/screenshots are disabled.
- Repository verification with `-Ci` passed. Migration `0083` applied incrementally and native rebuild provisioned the job. Cache and asset timestamp refreshed; no password/configuration file was changed.

```powershell
powershell -ExecutionPolicy Bypass -File scripts/dev/verify-automation-acceptance.ps1 -Suite Abm -BaseUrl http://localhost/nexa -PhpPath C:\wamp64\bin\php\php8.2.29\php.exe -Projects 'desktop,mobile'
```

Changes remain local, uncommitted and unpushed. Later Phase 7 verification now includes clean/incremental schema replay, separate-process scoring/ABM races, explicit maximum-capacity boundaries and repeated native worker execution. See [the current exit-gate checklist](phase-7-exit-gate.md) for results and remaining gaps; global queue-selection fairness still requires approval. External Postal/HTTPS acceptance remains separate, not solved by an ABM audience test.
