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

The visual group editor supports the same authorized scalar catalogue as the API. Behavioral/custom-property rules and rich conditional layouts remain pending. No migration is required.

## Lead Score Conditions

Select **Lead score** to compare the existing native Contact score with a whole-number threshold: equals, not equal, greater than, at least, less than or at most. **Has a value** includes zero; **Has no value** matches a missing score. Missing scores never satisfy numeric comparisons, including not-equal. Thresholds must be whole numbers from 0 through 2147483647; decimals, exponents and negative values are rejected.

Example: use **All conditions**, with **Lead status = New** and **Lead score is at least 50**. Only Contacts satisfying both receive the matching message. The stateless `nexaEmailNumeric` block helper is registered inside EspoCRM's native template renderer. It compares supplied raw integers only, does not load records, and does not calculate or modify scores. Existing scoring automation remains separate Phase 6/7 work. Numeric conditions also work in groups and native recipient previews.

Verification: `tests/tenant/TenantMarketingConditionalContentTest.php` renders groups against native Contact fixtures and the native preview processor, checks all/any semantics and rejects invalid groups. Fixtures roll back without sending email.
