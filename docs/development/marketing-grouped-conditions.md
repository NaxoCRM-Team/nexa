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

This is the grouped backend/API foundation. The existing visual dialog still authors one scalar condition; a visual group editor, behavioral/custom-property rules and rich conditional layouts are not delivered by this change. No migration is required.

Verification: `tests/tenant/TenantMarketingConditionalContentTest.php` renders groups against native Contact fixtures and the native preview processor, checks all/any semantics and rejects invalid groups. Fixtures roll back without sending email.
