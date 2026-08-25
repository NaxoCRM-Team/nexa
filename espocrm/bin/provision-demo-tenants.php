<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Espo\Core\Application;
use Espo\Core\ORM\EntityManager;
use Espo\Core\Tenant\TenantResolver;
use Espo\Core\Utils\Config;

const DEMO_SERVICE_ID = '20000000-0000-4000-8000-000000000001';

/**
 * @param array<string, mixed> $values
 * @param string[]|null $updateColumns
 */
function upsertDemoRow(PDO $pdo, string $table, array $values, ?array $updateColumns = null): void
{
    $quote = static fn (string $name): string => '`' . str_replace('`', '``', $name) . '`';
    $columns = array_keys($values);
    $updateColumns ??= array_values(array_diff($columns, ['id', 'created_at']));

    $sql = 'INSERT INTO ' . $quote($table) .
        ' (' . implode(', ', array_map($quote, $columns)) . ')' .
        ' VALUES (' . implode(', ', array_map(static fn (string $column): string => ':' . $column, $columns)) . ')' .
        ' ON DUPLICATE KEY UPDATE ' .
        implode(', ', array_map(
            static fn (string $column): string => $quote($column) . ' = VALUES(' . $quote($column) . ')',
            $updateColumns
        ));

    $pdo->prepare($sql)->execute($values);
}

function demoId(string $tenantId, string $type, string $key): string
{
    return substr(hash('sha256', $tenantId . ':' . $type . ':' . $key), 0, 17);
}

/**
 * @return array<string, mixed>
 */
function getDemoProfile(string $slug): array
{
    if ($slug === 'isolation-alpha') {
        return [
            'accounts' => [
                ['key' => 'northstar', 'name' => 'Northstar Analytics', 'type' => 'Customer', 'industry' => 'Technology', 'website' => 'https://northstar.example.test', 'city' => 'London'],
                ['key' => 'cedar-finch', 'name' => 'Cedar & Finch Retail', 'type' => 'Prospect', 'industry' => 'Retail', 'website' => 'https://cedar-finch.example.test', 'city' => 'Manchester'],
            ],
            'contacts' => [
                ['key' => 'ava-morgan', 'firstName' => 'Ava', 'lastName' => 'Morgan', 'account' => 'northstar', 'email' => 'ava.morgan@northstar.example.test', 'title' => 'Revenue Operations Director'],
                ['key' => 'daniel-okafor', 'firstName' => 'Daniel', 'lastName' => 'Okafor', 'account' => 'northstar', 'email' => 'daniel.okafor@northstar.example.test', 'title' => 'Head of Sales'],
                ['key' => 'mia-chen', 'firstName' => 'Mia', 'lastName' => 'Chen', 'account' => 'cedar-finch', 'email' => 'mia.chen@cedar-finch.example.test', 'title' => 'Digital Commerce Manager'],
            ],
            'leads' => [
                ['key' => 'priya-shah', 'firstName' => 'Priya', 'lastName' => 'Shah', 'accountName' => 'Vertex Labs', 'title' => 'Growth Lead', 'status' => 'New', 'source' => 'Web Site', 'amount' => 18000, 'email' => 'priya.shah@vertex.example.test'],
                ['key' => 'ethan-williams', 'firstName' => 'Ethan', 'lastName' => 'Williams', 'accountName' => 'Brightpath Learning', 'title' => 'Commercial Director', 'status' => 'Assigned', 'source' => 'Campaign', 'amount' => 32000, 'email' => 'ethan.williams@brightpath.example.test'],
                ['key' => 'sofia-rossi', 'firstName' => 'Sofia', 'lastName' => 'Rossi', 'accountName' => 'Lumen Studio', 'title' => 'Founder', 'status' => 'In Process', 'source' => 'Referral', 'amount' => 12000, 'email' => 'sofia.rossi@lumen.example.test'],
            ],
            'opportunities' => [
                ['key' => 'analytics-expansion', 'name' => 'Analytics Platform Expansion', 'account' => 'northstar', 'contact' => 'ava-morgan', 'amount' => 48000, 'stage' => 'Negotiation', 'probability' => 75, 'days' => 21],
                ['key' => 'retail-automation', 'name' => 'Retail Automation Pilot', 'account' => 'cedar-finch', 'contact' => 'mia-chen', 'amount' => 22000, 'stage' => 'Proposal', 'probability' => 55, 'days' => 35],
                ['key' => 'sales-enablement', 'name' => 'Sales Enablement Rollout', 'account' => 'northstar', 'contact' => 'daniel-okafor', 'amount' => 15000, 'stage' => 'Qualification', 'probability' => 35, 'days' => 50],
                ['key' => 'customer-success', 'name' => 'Customer Success Expansion', 'account' => 'northstar', 'contact' => 'ava-morgan', 'amount' => 34000, 'stage' => 'Closed Won', 'probability' => 100, 'days' => -35],
            ],
        ];
    }

    return [
        'accounts' => [
            ['key' => 'harbor-health', 'name' => 'Harbor Health Network', 'type' => 'Customer', 'industry' => 'Healthcare', 'website' => 'https://harbor-health.example.test', 'city' => 'Birmingham'],
            ['key' => 'atlas-works', 'name' => 'Atlas Works Group', 'type' => 'Prospect', 'industry' => 'Manufacturing', 'website' => 'https://atlas-works.example.test', 'city' => 'Leeds'],
        ],
        'contacts' => [
            ['key' => 'olivia-bennett', 'firstName' => 'Olivia', 'lastName' => 'Bennett', 'account' => 'harbor-health', 'email' => 'olivia.bennett@harbor-health.example.test', 'title' => 'Patient Experience Director'],
            ['key' => 'noah-adeyemi', 'firstName' => 'Noah', 'lastName' => 'Adeyemi', 'account' => 'harbor-health', 'email' => 'noah.adeyemi@harbor-health.example.test', 'title' => 'Operations Manager'],
            ['key' => 'lucas-martin', 'firstName' => 'Lucas', 'lastName' => 'Martin', 'account' => 'atlas-works', 'email' => 'lucas.martin@atlas-works.example.test', 'title' => 'Commercial Manager'],
        ],
        'leads' => [
            ['key' => 'grace-kim', 'firstName' => 'Grace', 'lastName' => 'Kim', 'accountName' => 'Summit Care', 'title' => 'Marketing Director', 'status' => 'New', 'source' => 'Web Site', 'amount' => 26000, 'email' => 'grace.kim@summit-care.example.test'],
            ['key' => 'henry-jones', 'firstName' => 'Henry', 'lastName' => 'Jones', 'accountName' => 'Forge Logistics', 'title' => 'Sales Director', 'status' => 'Assigned', 'source' => 'Campaign', 'amount' => 41000, 'email' => 'henry.jones@forge.example.test'],
            ['key' => 'amelie-dubois', 'firstName' => 'Amelie', 'lastName' => 'Dubois', 'accountName' => 'Nouveau Digital', 'title' => 'Managing Partner', 'status' => 'In Process', 'source' => 'Referral', 'amount' => 14500, 'email' => 'amelie.dubois@nouveau.example.test'],
        ],
        'opportunities' => [
            ['key' => 'patient-engagement', 'name' => 'Patient Engagement Programme', 'account' => 'harbor-health', 'contact' => 'olivia-bennett', 'amount' => 62000, 'stage' => 'Proposal', 'probability' => 60, 'days' => 28],
            ['key' => 'operations-workspace', 'name' => 'Operations Workspace', 'account' => 'harbor-health', 'contact' => 'noah-adeyemi', 'amount' => 28000, 'stage' => 'Qualification', 'probability' => 40, 'days' => 42],
            ['key' => 'partner-portal', 'name' => 'Partner Portal Launch', 'account' => 'atlas-works', 'contact' => 'lucas-martin', 'amount' => 36000, 'stage' => 'Negotiation', 'probability' => 70, 'days' => 18],
            ['key' => 'care-rollout', 'name' => 'Care Network Rollout', 'account' => 'harbor-health', 'contact' => 'olivia-bennett', 'amount' => 52000, 'stage' => 'Closed Won', 'probability' => 100, 'days' => -48],
        ],
    ];
}

/**
 * @param array<string, mixed> $profile
 */
function provisionDemoCrmData(
    PDO $pdo,
    string $tenantId,
    string $tenantSlug,
    string $adminId,
    array $profile,
    string $currency
): int {
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $dateTime = static fn (string $change): string => $now->modify($change)->format('Y-m-d H:i:s');
    $date = static fn (string $change): string => $now->modify($change)->format('Y-m-d');
    $common = [
        'deleted' => 0,
        'created_at' => $dateTime('-14 days'),
        'modified_at' => $dateTime('now'),
        'created_by_id' => $adminId,
        'modified_by_id' => $adminId,
        'assigned_user_id' => $adminId,
        'tenant_id' => $tenantId,
        'service_id' => DEMO_SERVICE_ID,
    ];
    $accountIds = [];
    $contactIds = [];
    $recordCount = 0;

    foreach ($profile['accounts'] as $index => $account) {
        $id = demoId($tenantId, 'account', $account['key']);
        $accountIds[$account['key']] = $id;

        upsertDemoRow($pdo, 'account', array_merge($common, [
            'id' => $id,
            'name' => $account['name'],
            'website' => $account['website'],
            'type' => $account['type'],
            'industry' => $account['industry'],
            'billing_address_city' => $account['city'],
            'billing_address_country' => 'United Kingdom',
            'description' => 'Synthetic demo account for the ' . $tenantSlug . ' workspace.',
            'created_at' => $dateTime('-' . (14 - $index) . ' days'),
            'version_number' => 1,
        ]));
        $recordCount++;
    }

    foreach ($profile['contacts'] as $index => $contact) {
        $id = demoId($tenantId, 'contact', $contact['key']);
        $contactIds[$contact['key']] = $id;
        $accountId = $accountIds[$contact['account']];

        upsertDemoRow($pdo, 'contact', array_merge($common, [
            'id' => $id,
            'first_name' => $contact['firstName'],
            'last_name' => $contact['lastName'],
            'description' => $contact['title'] . '. Synthetic demo contact.',
            'address_city' => $profile['accounts'][$contact['account'] === $profile['accounts'][0]['key'] ? 0 : 1]['city'],
            'address_country' => 'United Kingdom',
            'account_id' => $accountId,
            'created_at' => $dateTime('-' . (12 - $index) . ' days'),
        ]));
        upsertDemoRow($pdo, 'account_contact', [
            'account_id' => $accountId,
            'contact_id' => $id,
            'role' => 'Decision Maker',
            'is_inactive' => 0,
            'deleted' => 0,
            'tenant_id' => $tenantId,
            'service_id' => DEMO_SERVICE_ID,
        ]);

        $emailId = demoId($tenantId, 'email', $contact['email']);
        upsertDemoRow($pdo, 'email_address', [
            'id' => $emailId,
            'name' => $contact['email'],
            'deleted' => 0,
            'lower' => strtolower($contact['email']),
            'invalid' => 0,
            'opt_out' => 0,
            'tenant_id' => $tenantId,
            'service_id' => DEMO_SERVICE_ID,
        ]);
        upsertDemoRow($pdo, 'entity_email_address', [
            'entity_id' => $id,
            'email_address_id' => $emailId,
            'entity_type' => 'Contact',
            'primary' => 1,
            'deleted' => 0,
            'tenant_id' => $tenantId,
            'service_id' => DEMO_SERVICE_ID,
        ]);
        $recordCount++;
    }

    foreach ($profile['leads'] as $index => $lead) {
        $id = demoId($tenantId, 'lead', $lead['key']);

        upsertDemoRow($pdo, 'lead', array_merge($common, [
            'id' => $id,
            'first_name' => $lead['firstName'],
            'last_name' => $lead['lastName'],
            'title' => $lead['title'],
            'status' => $lead['status'],
            'source' => $lead['source'],
            'industry' => 'Technology',
            'opportunity_amount' => $lead['amount'],
            'opportunity_amount_currency' => $currency,
            'account_name' => $lead['accountName'],
            'description' => 'Synthetic inbound lead for qualification.',
            'created_at' => $dateTime('-' . (8 - $index) . ' days'),
        ]));

        $emailId = demoId($tenantId, 'email', $lead['email']);
        upsertDemoRow($pdo, 'email_address', [
            'id' => $emailId,
            'name' => $lead['email'],
            'deleted' => 0,
            'lower' => strtolower($lead['email']),
            'invalid' => 0,
            'opt_out' => 0,
            'tenant_id' => $tenantId,
            'service_id' => DEMO_SERVICE_ID,
        ]);
        upsertDemoRow($pdo, 'entity_email_address', [
            'entity_id' => $id,
            'email_address_id' => $emailId,
            'entity_type' => 'Lead',
            'primary' => 1,
            'deleted' => 0,
            'tenant_id' => $tenantId,
            'service_id' => DEMO_SERVICE_ID,
        ]);
        $recordCount++;
    }

    foreach ($profile['opportunities'] as $index => $opportunity) {
        $id = demoId($tenantId, 'opportunity', $opportunity['key']);
        $accountId = $accountIds[$opportunity['account']];
        $contactId = $contactIds[$opportunity['contact']];

        upsertDemoRow($pdo, 'opportunity', array_merge($common, [
            'id' => $id,
            'name' => $opportunity['name'],
            'amount' => $opportunity['amount'],
            'amount_currency' => $currency,
            'stage' => $opportunity['stage'],
            'last_stage' => $opportunity['stage'],
            'probability' => $opportunity['probability'],
            'lead_source' => 'Campaign',
            'close_date' => $date('+' . $opportunity['days'] . ' days'),
            'description' => 'Synthetic pipeline opportunity for dashboard and reporting development.',
            'account_id' => $accountId,
            'contact_id' => $contactId,
            'created_at' => $dateTime('-' . (6 - $index) . ' days'),
            'version_number' => 1,
        ]));
        upsertDemoRow($pdo, 'contact_opportunity', [
            'contact_id' => $contactId,
            'opportunity_id' => $id,
            'role' => 'Decision Maker',
            'deleted' => 0,
            'tenant_id' => $tenantId,
            'service_id' => DEMO_SERVICE_ID,
        ]);
        $recordCount++;
    }

    $activityAccountId = $accountIds[$profile['accounts'][0]['key']];
    $activityContactId = $contactIds[$profile['contacts'][0]['key']];
    $tasks = [
        ['key' => 'follow-up', 'name' => 'Follow up on proposal', 'priority' => 'High', 'start' => '+1 day', 'end' => '+2 days'],
        ['key' => 'prepare-demo', 'name' => 'Prepare product demonstration', 'priority' => 'Normal', 'start' => '+2 days', 'end' => '+4 days'],
        ['key' => 'review-pipeline', 'name' => 'Review active pipeline', 'priority' => 'Normal', 'start' => '+4 days', 'end' => '+5 days'],
    ];

    foreach ($tasks as $index => $task) {
        upsertDemoRow($pdo, 'task', array_merge($common, [
            'id' => demoId($tenantId, 'task', $task['key']),
            'name' => $task['name'],
            'status' => $index === 0 ? 'Started' : 'Not Started',
            'priority' => $task['priority'],
            'date_start' => $dateTime($task['start']),
            'date_end' => $dateTime($task['end']),
            'description' => 'Synthetic task assigned to the demo tenant administrator.',
            'parent_id' => $activityAccountId,
            'parent_type' => 'Account',
            'account_id' => $activityAccountId,
            'contact_id' => $activityContactId,
            'created_at' => $dateTime('-2 days'),
            'version_number' => 1,
        ]));
        $recordCount++;
    }

    $meetings = [
        ['key' => 'discovery', 'name' => 'Discovery and requirements session', 'start' => '+1 day 10:00', 'end' => '+1 day 11:00'],
        ['key' => 'pipeline-review', 'name' => 'Weekly pipeline review', 'start' => '+3 days 14:00', 'end' => '+3 days 14:45'],
    ];

    foreach ($meetings as $meeting) {
        $meetingId = demoId($tenantId, 'meeting', $meeting['key']);
        upsertDemoRow($pdo, 'meeting', array_merge($common, [
            'id' => $meetingId,
            'name' => $meeting['name'],
            'status' => 'Planned',
            'date_start' => $dateTime($meeting['start']),
            'date_end' => $dateTime($meeting['end']),
            'is_all_day' => 0,
            'description' => 'Synthetic scheduled meeting for calendar and activity development.',
            'parent_id' => $activityAccountId,
            'parent_type' => 'Account',
            'account_id' => $activityAccountId,
            'created_at' => $dateTime('-1 day'),
        ]));
        upsertDemoRow($pdo, 'meeting_user', [
            'user_id' => $adminId,
            'meeting_id' => $meetingId,
            'status' => 'Accepted',
            'deleted' => 0,
            'tenant_id' => $tenantId,
            'service_id' => DEMO_SERVICE_ID,
        ]);
        $recordCount++;
    }

    return $recordCount;
}

$accounts = [
    [
        'host' => 'tenant-a.localhost',
        'slug' => 'isolation-alpha',
        'userName' => getenv('NEXA_TENANT_A_ADMIN_USERNAME') ?: 'demo-admin',
        'loginEmail' => getenv('NEXA_TENANT_A_ADMIN_EMAIL') ?: 'demo-admin@isolation-alpha.nexa.test',
        'password' => getenv('NEXA_TENANT_A_ADMIN_PASSWORD'),
        'firstName' => 'Tenant A',
    ],
    [
        'host' => 'tenant-b.localhost',
        'slug' => 'isolation-beta',
        'userName' => getenv('NEXA_TENANT_B_ADMIN_USERNAME') ?: 'demo-admin-b',
        'loginEmail' => getenv('NEXA_TENANT_B_ADMIN_EMAIL') ?: 'demo-admin-b@isolation-beta.nexa.test',
        'password' => getenv('NEXA_TENANT_B_ADMIN_PASSWORD'),
        'firstName' => 'Tenant B',
    ],
];

foreach ($accounts as $account) {
    if (!is_string($account['password']) || strlen($account['password']) < 12) {
        fwrite(STDERR, "Set a password of at least 12 characters for {$account['host']}.\n");
        exit(1);
    }

    if (!is_string($account['userName']) || $account['userName'] === '' || strlen($account['userName']) > 50) {
        fwrite(STDERR, "The demo administrator username for {$account['host']} must contain between 1 and 50 characters.\n");
        exit(1);
    }
}

if ($accounts[0]['userName'] === $accounts[1]['userName']) {
    fwrite(STDERR, "Tenant A and Tenant B demo administrator usernames must be different.\n");
    exit(1);
}

$application = new Application();
$container = $application->getContainer();
$entityManager = $container->getByClass(EntityManager::class);
$tenantResolver = $container->getByClass(TenantResolver::class);
$config = $container->getByClass(Config::class);
$pdo = $entityManager->getPDO();
$currency = (string) ($config->get('baseCurrency') ?: 'USD');

$find = $pdo->prepare(
    'SELECT id FROM user WHERE tenant_id = :tenantId AND service_id = :serviceId ' .
    'AND user_name = :userName AND deleted = 0 LIMIT 1'
);
$insert = $pdo->prepare(
    'INSERT INTO user ' .
    '(id, deleted, user_name, login_email, type, password, first_name, last_name, is_active, created_at, modified_at, delete_id, tenant_id, service_id) ' .
    'VALUES (:id, 0, :userName, :loginEmail, :type, :password, :firstName, :lastName, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP(), :deleteId, :tenantId, :serviceId)'
);
$update = $pdo->prepare(
    'UPDATE user SET login_email = :loginEmail, type = :type, password = :password, first_name = :firstName, ' .
    'last_name = :lastName, is_active = 1, service_id = :serviceId, modified_at = UTC_TIMESTAMP() ' .
    'WHERE id = :id AND tenant_id = :tenantId AND service_id = :serviceId'
);
$deactivateOtherDemoAccount = $pdo->prepare(
    'UPDATE user SET is_active = 0, modified_at = UTC_TIMESTAMP() ' .
    'WHERE tenant_id = :tenantId AND service_id = :serviceId AND user_name = :otherUserName AND deleted = 0'
);

$pdo->beginTransaction();

try {
    foreach ($accounts as $index => $account) {
        $tenant = $tenantResolver->resolveHost($account['host']);

        if ($tenant === null) {
            throw new RuntimeException(
                "Demo tenant for host {$account['host']} is missing. Apply development seeds first."
            );
        }

        $find->execute([
            'tenantId' => $tenant->tenantId,
            'serviceId' => $tenant->serviceId,
            'userName' => $account['userName'],
        ]);
        $id = $find->fetchColumn();
        $created = !is_string($id);

        if ($created) {
            $id = substr(bin2hex(random_bytes(9)), 0, 17);
            $insert->execute([
                'id' => $id,
                'userName' => $account['userName'],
                'loginEmail' => $account['loginEmail'],
                'type' => 'admin',
                'password' => password_hash($account['password'], PASSWORD_BCRYPT),
                'firstName' => $account['firstName'],
                'lastName' => 'Administrator',
                'deleteId' => '0',
                'tenantId' => $tenant->tenantId,
                'serviceId' => $tenant->serviceId,
            ]);
        } else {
            $update->execute([
                'id' => $id,
                'loginEmail' => $account['loginEmail'],
                'type' => 'admin',
                'password' => password_hash($account['password'], PASSWORD_BCRYPT),
                'firstName' => $account['firstName'],
                'lastName' => 'Administrator',
                'tenantId' => $tenant->tenantId,
                'serviceId' => $tenant->serviceId,
            ]);
        }

        $otherAccount = $accounts[$index === 0 ? 1 : 0];
        $deactivateOtherDemoAccount->execute([
            'tenantId' => $tenant->tenantId,
            'serviceId' => $tenant->serviceId,
            'otherUserName' => $otherAccount['userName'],
        ]);

        $recordCount = provisionDemoCrmData(
            $pdo,
            $tenant->tenantId,
            $account['slug'],
            $id,
            getDemoProfile($account['slug']),
            $currency
        );
        $action = $created ? 'created' : 'updated';
        fwrite(
            STDOUT,
            "{$action}: {$account['userName']} / {$tenant->tenantId}; provisioned {$recordCount} demo CRM records\n"
        );
    }

    $pdo->commit();
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(1);
}
