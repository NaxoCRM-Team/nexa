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

The visual group editor supports the same authorized scalar catalogue as the API, including enabled tenant Contact text, single-select, boolean, number and currency custom properties. Behavioral rules, date custom-property conditions and rich conditional layouts remain pending. No migration is required.

## Tenant Custom Properties

Create a Contact text or single-select property in Administration > Objects & Properties, then select its `(custom)` entry in the Conditional content property selector. Existing equality, inequality, has-value and no-value conditions apply; select comparisons accept only declared options. Conditions can combine native and custom properties in the same group. Values are loaded through the existing customization service and Contact read ACL, scoped to the current tenant and service. The native preview and delivery processors use the same helper.

Boolean custom properties provide True and False choices. Recorded false counts as having a value; an absent value equals neither true nor false. As with native scalar inequality, a missing value is not equal to false (or true); combine `Has a value` with `Is not equal to` in an All group when missing values should be excluded. Property defaults follow the existing customization service rather than a separate email default policy.

Number and currency custom properties support all numeric operators, signed values and up to eight decimal places, matching existing DECIMAL(30,8) storage. Stored decimals are read as strings only after the existing record ACL check and compared without floating-point conversion. Thresholds allow at most 22 whole-number digits; exponents, NaN, excessive precision and oversized values are rejected. Missing values satisfy no numeric comparisons (including not-equal); zero counts as present. Currency properties compare the stored amount only, with no implied exchange-rate conversion. Native Lead score remains a non-negative whole-number condition.

Save/preview validation rejects referenced properties that have been archived or disabled; they do not silently turn into a fallback. Re-enable the property or update and reapprove the email before delivery. This integration uses existing definition/value tables, without new database columns or a migration.

## Lead Score Conditions

Select **Lead score** to compare the existing native Contact score with a whole-number threshold: equals, not equal, greater than, at least, less than or at most. **Has a value** includes zero; **Has no value** matches a missing score. Missing scores never satisfy numeric comparisons, including not-equal. Thresholds must be whole numbers from 0 through 2147483647; decimals, exponents and negative values are rejected.

Example: use **All conditions**, with **Lead status = New** and **Lead score is at least 50**. Only Contacts satisfying both receive the matching message. The stateless `nexaEmailNumeric` block helper is registered inside EspoCRM's native template renderer. It compares supplied raw integers only, does not load records, and does not calculate or modify scores. Existing scoring automation remains separate Phase 6/7 work. Numeric conditions also work in groups and native recipient previews.

Verification: `tests/tenant/TenantMarketingConditionalContentTest.php` renders groups against native Contact fixtures and the native preview processor, checks all/any semantics and rejects invalid groups. Fixtures roll back without sending email.
