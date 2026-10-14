# Grouped Conditional Email Content

The authenticated `POST /api/v1/Nexa/marketing-email-conditions` composer accepts a flat group of one to six authorized scalar Contact conditions. Existing single-condition requests remain supported.

```json
{
  "matchMode": "all",
  "conditions": [
    {"field": "leadStatus", "operator": "equals", "value": "New"},
    {"field": "department", "operator": "has_value"}
  ],
  "matchingText": "Welcome {Contact.firstName}",
  "fallbackText": "Thank you for staying in touch"
}
```

`all` requires every condition; `any` requires at least one. The composer returns `bodyHtml` using nested native EspoCRM helpers, not a separate runtime rule engine. Exactly one matching/fallback message renders per recipient. Property permissions, controlled values, bounded text, literal escaping, native personalization and template syntax validation remain enforced. Nested groups and more than six conditions are rejected. No records or queue items are created by composition.

In **Marketing Email**, open a draft and choose **Conditional content**. Select **All conditions** or **Any condition**, use **Add condition** to combine up to six properties, then enter matching and fallback messages. The trash icon removes a condition; the final condition cannot be removed. **Insert content** stages the native helper block in the unsaved draft. Cancel/Escape leaves the draft unchanged. Save, review and recipient preview continue through the existing native workflow.

The visual group editor supports the same authorized scalar catalogue as the API, including enabled tenant Contact text, single-select, boolean, number and currency custom properties. Rich branch editing and supported recorded-event windows are available; date custom-property conditions and advanced behavioral aggregation remain pending. No migration is required.

## Recorded Behavior Windows

Choose Recorded website visit, Recorded marketing email open/click, or Recorded email reply. Occurred in the last and Did not occur in the last accept whole-day windows from 1 through 365 (default 30). These use existing unified timeline evidence linked to the Contact, scoped to its tenant/service and checked against native Contact read ACL. Website visits and replies use BehaviorEvent projections; email opens/clicks use MarketingDeliveryEvent projections. Restricted visibility, future timestamps and unsupported source types do not qualify. Anonymous visits must first be matched to the Contact through existing identity tracking.

The inverse condition means no eligible retained evidence was found, not proof that a person never visited or interacted. Event retention, tracking consent, missing instrumentation and privacy protections can all affect available evidence; email opens are recorded telemetry, not proof of human readership. Conditions evaluate at preview/delivery time and are not frozen into approval. Ordinary consent, suppression and Do Not Contact checks continue independently. These windows combine with native/custom properties and plain/rich branches through the native helper renderer; they do not introduce new event ingestion or outbound sending APIs.

## Rich Branches

Choose Rich content in the conditional-content dialog to use the existing native WYSIWYG field for matching and fallback branches. Formatting, lists, tables and links remain within the existing editor; plain-text mode remains available and keeps the previous API contract. Switching modes preserves each mode's staged content. Insert stages the block in the draft, while Cancel leaves the email unchanged. Failed editor loading restores plain-text mode. Responsive browser tests use native-editor doubles for lifecycle/payload checks, not authenticated acceptance of every native toolbar command.

The API accepts matchingHtml and fallbackHtml together, instead of matchingText/fallbackText. Mixing formats is rejected. HTML Purifier (already bundled with EspoCRM) restricts tags, attributes and URL schemes, removing executable scripts, event handlers and unsafe links. Each branch is limited to 100,000 characters, must retain visible text or an image, and cannot contain nested template helpers. Existing single-brace Contact personalization remains supported. Managed images in either branch must pass existing tenant-scoped publication checks and retain the ordinary save, approval and worker manifest enforcement. No new image-upload or publication path is introduced in this dialog.

Each rich branch has Insert image, using the existing tenant-scoped published email-image catalogue. Search filters names immediately. Select an image explicitly, then insert it through the native editor at its saved cursor position. Unpublished library assets are excluded; publish them through the existing email designer first. Cancel closes the picker without inserting. Missing results and loading failures appear inline, and closed/reopened picker requests cannot apply stale responses. Five-viewport tests verify filtering, search and correct-branch insertion using editor doubles.

## Tenant Custom Properties

Create a Contact text or single-select property in Administration > Objects & Properties, then select its `(custom)` entry in the Conditional content property selector. Existing equality, inequality, has-value and no-value conditions apply; select comparisons accept only declared options. Conditions can combine native and custom properties in the same group. Values are loaded through the existing customization service and Contact read ACL, scoped to the current tenant and service. The native preview and delivery processors use the same helper.

Boolean custom properties provide True and False choices. Recorded false counts as having a value; an absent value equals neither true nor false. As with native scalar inequality, a missing value is not equal to false (or true); combine `Has a value` with `Is not equal to` in an All group when missing values should be excluded. Property defaults follow the existing customization service rather than a separate email default policy.

Number and currency custom properties support all numeric operators, signed values and up to eight decimal places, matching existing DECIMAL(30,8) storage. Stored decimals are read as strings only after the existing record ACL check and compared without floating-point conversion. Thresholds allow at most 22 whole-number digits; exponents, NaN, excessive precision and oversized values are rejected. Missing values satisfy no numeric comparisons (including not-equal); zero counts as present. Currency properties compare the stored amount only, with no implied exchange-rate conversion. Native Lead score remains a non-negative whole-number condition.

Save/preview validation rejects referenced properties that have been archived or disabled; they do not silently turn into a fallback. Re-enable the property or update and reapprove the email before delivery. This integration uses existing definition/value tables, without new database columns or a migration.

## Lead Score Conditions

Select **Lead score** to compare the existing native Contact score with a whole-number threshold: equals, not equal, greater than, at least, less than or at most. **Has a value** includes zero; **Has no value** matches a missing score. Missing scores never satisfy numeric comparisons, including not-equal. Thresholds must be whole numbers from 0 through 2147483647; decimals, exponents and negative values are rejected.

Example: use **All conditions**, with **Lead status = New** and **Lead score is at least 50**. Only Contacts satisfying both receive the matching message. The stateless `nexaEmailNumeric` block helper is registered inside EspoCRM's native template renderer. It compares supplied raw integers only, does not load records, and does not calculate or modify scores. Existing scoring automation remains separate Phase 6/7 work. Numeric conditions also work in groups and native recipient previews.

Verification: `tests/tenant/TenantMarketingConditionalContentTest.php` renders groups against native Contact fixtures and the native preview processor, checks all/any semantics and rejects invalid groups. Fixtures roll back without sending email.

## Approval And Dispatch Preflight

Campaign scheduling, reusable-source copying, new single-send queue creation and administrator provider tests validate conditional references before creating delivery work. Final worker validation remains in place. Provider tests also verify immutable content/delivery settings and resolved programmable parameters before queue creation or capacity reservation. Existing request replay still returns its accepted identity rather than authorizing a new send; worker checks govern that accepted work.

The reusable-send regression fixture combines rich branches, a tenant custom property and a recorded-behavior window in one native approved template. After archiving its property, scheduling, reusable-send and single-send reject with an explicit property-availability error. The fixture verifies unchanged queue/profile counts and no consumed reusable request key. These are rollback-only acceptance checks; they do not prove successful live HTTP delivery or production Postal acceptance.
