<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static function (string $relative) use ($root): string {
    $content = file_get_contents($root . '/' . $relative);
    if (!is_string($content)) {
        throw new RuntimeException("Missing customer-profile contract: {$relative}");
    }
    return $content;
};
$decode = static fn (string $relative): array => json_decode(
    $read($relative), true, 512, JSON_THROW_ON_ERROR
);
$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$contact = $decode('espocrm/custom/Espo/Custom/Resources/metadata/entityDefs/Contact.json');
$account = $decode('espocrm/custom/Espo/Custom/Resources/metadata/entityDefs/Account.json');
$contactLayout = $decode('espocrm/custom/Espo/Custom/Resources/layouts/Contact/detail.json');
$contactSmallLayout = $decode('espocrm/custom/Espo/Custom/Resources/layouts/Contact/detailSmall.json');
$contactClientDefs = $decode('espocrm/custom/Espo/Custom/Resources/metadata/clientDefs/Contact.json');
$accountLayout = $decode('espocrm/custom/Espo/Custom/Resources/layouts/Account/detail.json');
$migration = $read('database/shared/migrations/0011_extend_customer_company_profiles.sql');
$contactExperienceMigration = $read('database/shared/migrations/0012_extend_contact_profile_experience.sql');
$contactImageMigration = $read('database/shared/migrations/0013_add_contact_profile_image.sql');
$contactLifecycleMigration = $read('database/shared/migrations/0014_extend_contact_lifecycle_compliance.sql');
$contactProfileImageApi = $read('espocrm/custom/Espo/Custom/Tools/Contact/Api/GetProfileImage.php');
$contactProfileImageView = $read('espocrm/client/custom/src/views/contact/fields/profile-image.js');
$contactRecordEditView = $read('espocrm/client/custom/src/views/contact/record/edit.js');
$contactRecordEditV2View = $read('espocrm/client/custom/src/views/contact/record/edit-v2.js');
$contactRecordEditV3View = $read('espocrm/client/custom/src/views/contact/record/edit-v3.js');
$contactNameView = $read('espocrm/client/custom/src/views/contact/fields/name-v2.js');
$contactNameTemplate = $read('espocrm/client/custom/res/templates/contact/fields/name/edit-v2.tpl');
$surfaceRegistry = $read('espocrm/client/custom/src/product-surface-registry.js');
$addressView = $read('espocrm/client/custom/src/views/contact/fields/address.js');
$addressData = $decode('espocrm/custom/Espo/Custom/Resources/data/address-subdivisions.json');
$appParams = $decode('espocrm/custom/Espo/Custom/Resources/metadata/app/appParams.json');
$countryBootstrap = $read('espocrm/bin/populate-address-countries.php');
$localSetup = $read('scripts/dev/complete-local-setup.ps1');

foreach (['profileImage', 'department', 'website', 'facebookUrl', 'instagramUrl', 'skypeName', 'xUrl', 'linkedinUrl', 'tiktokUrl', 'tags', 'preferredTimeZone', 'lastWebsiteVisitAt', 'source', 'lifecycleStage', 'leadStatus', 'marketingStatus', 'leadScore', 'legalBasis'] as $field) {
    $assert(isset($contact['fields'][$field]), "Contact profile is missing {$field}.");
}
foreach (['annualRevenue', 'numberOfEmployees', 'parentAccount', 'subsidiaries', 'leadScore'] as $field) {
    $assert(isset($account['fields'][$field]), "Account profile is missing {$field}.");
}

$assert(($contact['fields']['leadScore']['readOnly'] ?? false) === true, 'Contact lead score must be automation-owned.');
$assert(($contact['fields']['lastWebsiteVisitAt']['readOnly'] ?? false) === true, 'Last website visit must be tracking-owned.');
$assert(($contact['fields']['lifecycleStage']['options'] ?? []) === ['', 'Subscriber', 'Lead', 'MarketingQualifiedLead', 'SalesQualifiedLead', 'Opportunity', 'Customer', 'Evangelist', 'Other'], 'Contact lifecycle options must match the product lifecycle.');
$assert(($contact['fields']['leadStatus']['options'] ?? []) === ['', 'New', 'Open', 'InProgress', 'OpenDeal', 'Unqualified', 'AttemptedToContact', 'Connected', 'BadTiming'], 'Contact lead-status options must match the operating workflow.');
$assert(($contact['fields']['legalBasis']['options'] ?? []) === ['', 'LegitimateInterestLead', 'LegitimateInterestCustomer', 'LegitimateInterestOther', 'PerformanceOfContract', 'FreelyGivenConsent', 'NotApplicable'], 'Contact legal-basis options must match the consent classification contract.');
$assert(($contact['fields']['address']['view'] ?? '') === 'custom:views/contact/fields/address', 'Contact address must use the searchable region selector.');
$assert(count($addressData['byCountry'] ?? []) >= 200, 'Address subdivision catalogue must cover worldwide countries and territories.');
$assert(str_contains($addressView, 'lookupFunction'), 'Address state/province selector must support search.');
$assert(isset($appParams['nexaTenant']), 'Contact experience must preserve the tenant identity app parameter.');
$assert(isset($appParams['addressSubdivisionData']), 'Contact experience must register subdivision data.');
$assert(str_contains($contactImageMigration, 'profile_image_id'), 'Contact image migration must persist the attachment ID.');
$assert(($contact['fields']['preferredTimeZone']['view'] ?? '') === 'custom:views/contact/fields/preferred-time-zone', 'Preferred timezone must use the searchable Contact control.');
$assert(($contact['fields']['profileImage']['showPreview'] ?? false) === true, 'Contact profile images must preview after upload.');
$assert(($contact['fields']['profileImage']['view'] ?? '') === 'custom:views/contact/fields/profile-image', 'Contact profile images must use the immediate-preview field.');
$assert(in_array('image/webp', $contact['fields']['profileImage']['accept'] ?? [], true), 'Contact profile images must restrict uploads to supported image formats.');
$assert(str_contains($contactProfileImageApi, "getRDBRepository('Contact')->getById"), 'Profile-image delivery must verify the tenant-scoped Contact.');
$assert(str_contains($contactProfileImageApi, 'checkEntityRead'), 'Profile-image delivery must enforce Contact read permission.');
$assert(str_contains($contactProfileImageView, 'Nexa/contact-profile-image/'), 'Contact portraits must use the authenticated API path.');
$assert(($contact['fields']['targetLists']['layoutDetailDisabled'] ?? true) === false, 'Contact segments must be available in the record form.');
$assert(($contactClientDefs['views']['edit'] ?? '') === 'custom:views/contact/edit', 'Contact creation must use the New Contact page view.');
$assert(($contactClientDefs['recordViews']['edit'] ?? '') === 'custom:views/contact/record/edit-v3', 'Contact forms must use the current cache-busted edit view.');
$assert(($contact['fields']['name']['view'] ?? '') === 'custom:views/contact/fields/name-v2', 'Contact forms must use the first-and-last-name field view.');
$assert(str_contains($countryBootstrap, 'CountryDefaultsPopulator'), 'Country bootstrap must use Espo native defaults.');
$assert(str_contains($countryBootstrap, 'PlatformExecutionGateway'), 'Country bootstrap must use the audited platform-write boundary.');
$assert(str_contains($localSetup, 'populate-address-countries.php'), 'Local setup must populate searchable countries.');
$assert(($account['fields']['leadScore']['readOnly'] ?? false) === true, 'Account lead score must be automation-owned.');
$assert(($account['links']['parentAccount']['entity'] ?? '') === 'Account', 'Parent company must link to Account.');
$assert(($account['links']['subsidiaries']['foreign'] ?? '') === 'parentAccount', 'Subsidiaries must use the inverse parent-company link.');

$layoutFields = static function (array $layout): array {
    $names = [];
    array_walk_recursive($layout, static function (mixed $value, string|int $key) use (&$names): void {
        if ($key === 'name' && is_string($value)) {
            $names[] = $value;
        }
    });
    return $names;
};

foreach (['accounts', 'website', 'facebookUrl', 'instagramUrl', 'skypeName', 'xUrl', 'linkedinUrl', 'tiktokUrl', 'tags', 'preferredTimeZone', 'source', 'lifecycleStage', 'leadStatus', 'marketingStatus', 'legalBasis', 'targetLists'] as $field) {
    $assert(in_array($field, $layoutFields($contactLayout), true), "Contact detail layout is missing {$field}.");
}
$assert(!in_array('title', $layoutFields($contactLayout), true), 'Account Title must be edited only through the Account relationship control.');
$assert(!in_array('title', $layoutFields($contactSmallLayout), true), 'Responsive Contact forms must not duplicate the Account relationship title control.');
$assert(str_contains($contactRecordEditView, "hideField('title', true)"), 'Contact editing must prevent dynamic logic from restoring a duplicate Account Title field.');
$assert(str_contains($contactRecordEditV2View, "hideField('title', true)"), 'The cache-busted Contact edit view must also lock the duplicate title field.');
$assert(str_contains($contactRecordEditV3View, "['department', 'leadScore', 'lastWebsiteVisitAt']"), 'The current Contact create view must hide enrichment and automation fields.');
foreach (['annualRevenue', 'numberOfEmployees', 'parentAccount', 'leadScore'] as $field) {
    $assert(in_array($field, $layoutFields($accountLayout), true), "Account detail layout is missing {$field}.");
}

foreach (['department', 'website', 'source', 'lead_status', 'marketing_status', 'lead_score', 'annual_revenue', 'number_of_employees', 'parent_account_id'] as $column) {
    $assert(str_contains($migration, $column), "Customer profile migration is missing {$column}.");
}
foreach (['facebook_url', 'instagram_url', 'skype_name', 'x_url', 'linkedin_url', 'tiktok_url', 'tags', 'preferred_time_zone', 'last_website_visit_at'] as $column) {
    $assert(str_contains($contactExperienceMigration, $column), "Contact experience migration is missing {$column}.");
}
foreach (['lifecycle_stage', 'legal_basis'] as $column) {
    $assert(str_contains($contactLifecycleMigration, $column), "Contact lifecycle migration is missing {$column}.");
}
$assert(str_contains($contactNameView, "editTemplate = 'custom:contact/fields/name/edit-v2'"), 'Contact editing must use the first-and-last-name template.');
$assert(!str_contains($contactNameTemplate, 'salutation'), 'The Contact name template must not render a salutation selector.');
$assert(!in_array('middleName', $layoutFields($contactLayout), true), 'Contact detail layout must not display middle name.');
$assert(array_intersect(['department', 'leadScore', 'lastWebsiteVisitAt'], $layoutFields($contactLayout)) === [], 'Contact forms must not render enrichment or automation-owned fields.');
$assert(!in_array('department', $layoutFields($contactSmallLayout), true), 'Responsive Contact forms must not render Department.');
$assert(!in_array('assignedUser', $layoutFields($contactLayout), true), 'Contact detail layout must not duplicate Assigned User from the side panel.');
$assert(!in_array('teams', $layoutFields($contactLayout), true), 'Contact detail layout must not duplicate Teams from the side panel.');
foreach (['CRM', 'Sales', 'Marketing', 'Automation', 'Service', 'Channels', 'Analytics', 'Data & Integrations'] as $workspace) {
    $assert(str_contains($surfaceRegistry, "label: '{$workspace}'"), "Navigation registry is missing {$workspace}.");
}
foreach (['Overview', 'Sales', 'Marketing', 'Automation', 'Service', 'Channels', 'Customer', 'Analytics'] as $dashboard) {
    $assert(str_contains($surfaceRegistry, "name: '{$dashboard}'"), "Dashboard registry is missing {$dashboard}.");
}

echo "Customer profile and product-surface skeleton tests passed.\n";
