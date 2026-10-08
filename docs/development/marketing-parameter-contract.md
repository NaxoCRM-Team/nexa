# Programmable Email Parameter Foundation

`MarketingParameterContract` is a pure, side-effect-free validator for approved text parameters. It does not send mail, read tenant records or add template context. `MarketingParameterTemplate` connects it to declared text positions, and the [reusable-send API](marketing-reusable-send.md) binds its fingerprint to the scoped request ledger.

An approved schema declares each parameter name and maximum character count, for example:

```json
{"appointment_reference":40,"appointment_label":255}
```

The caller must provide exactly those keys, with nonempty UTF-8 string values. Names use lowercase ASCII letters, digits and underscores, starting with a letter. Contracts support at most 20 parameters, 255 characters per value and 4,096 bytes in total. Nested objects, extra/missing keys, template braces, HTML delimiters, line breaks, control characters and Unicode formatting controls are rejected. Values are preserved, not silently trimmed or coerced.

The validator returns values in canonical key order and a SHA-256 fingerprint over both the approved schema and supplied values. Changing input key order does not change identity; changing a value or contract does. A fingerprint is an identity component, not an authorization credential or a substitute for tenant/service scoping.

The schema is declared inline in immutable approved content as `[[nexa:name:40]]`, not supplied by the caller. The email editor's Insert parameter dialog adds these declarations with target selection and length/conflict checks. Cancel, Escape and changed-draft guards preserve the current content; keyboard focus remains inside the dialog and returns to its launch button. Server-side save and approval validate placement. The compiler replaces only approved literal text positions. Resolved native child content and its immutable version are saved before review/approval/queuing. Migration `0067` adds fingerprint binding to durable request identities. Sender, recipient, URLs, HTML and renderer-context overrides remain forbidden. Existing consent, approval, native ACL and quota checks remain enforced. Integration-user authorization is still pending.

## Sample-Value Preview

Choose Preview as Contact, then select a Contact with the existing native picker. Drafts with declarations open a sample-value dialog containing one required input per declared name. Values use the same backend validation/compiler as delivery, and the native processor applies Contact personalization to subject, body and preheader with ACL enabled. The resolved result appears in the existing inert preview. Values are not saved to the draft, approval or queue. Cancel and Escape discard sample values; changed content/generation and cancelled requests cannot apply late responses. Validation errors remain inside the dialog. The native preview test verifies parameter rendering, invalid values, cross-tenant rejection and unchanged queue counts. Five-viewport browser host tests verify errors, draft preservation, cancellation and stale-response guards. No new migration or real-provider send is involved.

`tests/browser/marketing-email-parameters.spec.js` loads the actual workspace view in an isolated host and checks all three insertion targets, cancellation, keyboard focus, conflicting declarations, field/name limits and changed-draft rejection across five viewports. These browser tests do not claim real-user authentication or provider delivery.

Run `tests/tenant/TenantMarketingParameterContractTest.php` with the WAMP PHP executable. It checks canonical identity, Unicode lengths, value/schema bounds, safe placement and escaping without touching customer data or calling Postal. `TenantMarketingReusableSendTest.php` checks real API types, immutable resolved copies, native personalized previews and changed-value replay rejection using rollback-only fixtures. Both are registered in repository verification. The pure validator requires no migration; API fingerprint persistence requires `0067`.
