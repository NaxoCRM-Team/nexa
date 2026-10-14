# Phase 6 Live Closure Acceptance

Run sequentially after other database suites finish:

```powershell
powershell -ExecutionPolicy Bypass -File scripts/dev/verify-automation-acceptance.ps1 -Suite Closure -LocalPostalCapture -BaseUrl http://localhost/nexa -PhpPath C:\wamp64\bin\php\php8.2.29\php.exe -Projects desktop,mobile
```

The disposable two-tenant fixture logs in through the real browser, approves an email source over HTTP, publishes a workflow with a runtime Contact binding, and runs native workers. The existing isolated Postal stack submits only to local Mailpit. Authenticated canonical callback HTTP reconciles the exact provider message and recipient, rejects duplicate receipts, and supplies click evidence for the existing behavioral branch. A native score action runs once; repeated workers and a canceled enrollment produce no extra queue or SMTP capture.

Delivery and click callback payloads are synthetic provider events posted to the real endpoint against an actual local submission. This proves local endpoint authentication, reconciliation and workflow integration, not public Postal callback transport or an actual recipient click. The workflow waits for the supported `email.clicked` condition; `email.delivered` is not advertised as a condition.

Credentials and callback tokens stay in Node process memory. Browser traces and screenshots are disabled. Existing local TLS verification uses the local CA only in the worker process. Cleanup preserves append-only audit records and removes fixture credentials and working records. No production configuration, pre-existing tenant, public inbox or public DNS record is changed.

The bounded fixture wake command only advances due time for waiting runs owned by the disposable tenant and workflow. It avoids a real one-minute poll delay without changing production timing rules.

## Recorded Local Result

12 October 2026: the command above passed both desktop and mobile scenarios (2 passed, 48 seconds, exit 0). Wrong callback credentials returned 403; a mismatched persisted recipient returned 400. Duplicate delivery/click callbacks did not repeat native writes or SMTP submissions. The second canceled enrollment created no queue item. Public Postal prerequisites remain separate from this local scenario.

## Actual Email Experiment Delivery

```powershell
powershell -ExecutionPolicy Bypass -File scripts/dev/verify-automation-acceptance.ps1 -Suite Experiment -LocalPostalCapture -BaseUrl http://localhost/nexa -PhpPath C:\wamp64\bin\php\php8.2.29\php.exe -Projects desktop,mobile
```

12 October: both scenarios passed (54.3 seconds, exit 0) with the real tenant `NexaEmailExperiments` page and authenticated HTTP create/approve/start controls. Eight native Contacts were created through the Record service, their dynamic segment refreshed, and campaign eligibility verified before assignment. The test retained four balanced A/B test recipients, two permanent holdouts and two winner-rollout recipients. Actual Postal/Mailpit capture had four messages after the test and exactly six after winner rollout. Recipient and native queue MIME attribution matched the persisted assignments; repeated workers did not create another queue or SMTP submission. Stale source versions, ordinary source broadcasting and premature winner selection were rejected.

Initial runs exposed incomplete fixture preparation: low-level ORM creation did not persist native email relationships, and cached dynamic membership had not been recalculated. The fixture now uses the real native Contact persistence and segment services. Production audience eligibility was not weakened to make the test pass.

The final schema is `0078`. The combined repository suite passed, followed by final programmable marketing/transactional/recovery acceptance (six desktop/mobile scenarios). New experiment and workflow split UI checks passed all five viewports, including WCAG catalogue, create and results scans. A results scroll region was made keyboard-focusable after the mobile scan identified it. The broader email/automation/recovery UI regression had 85 passes and five opt-in live tests skipped; actual live commands above are separate evidence.

Public domain/DNS, public callback transport, owner-approved HTTPS webhook delivery and external-inbox acceptance are still blocked on deployment inputs. No issue closure, commit or push is claimed.

Final reruns against schema 0078 passed: `-Suite Programmable -LocalPostalCapture` (six scenarios, 1.9 minutes), `-Suite Closure -LocalPostalCapture` (two scenarios, 41.9 seconds), and `-Suite Automation` (two local scenarios, two public scenarios explicitly skipped, two minutes). The automation run exercised independent competing workers, native-action cancellation races, revoked identities, concurrent leases/capacity and two-tenant fairness. None of the skipped public scenarios is reported as delivered network traffic.
