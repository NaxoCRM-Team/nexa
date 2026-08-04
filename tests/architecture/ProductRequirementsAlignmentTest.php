<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

$read = static function (string $relative) use ($root): string {
    $content = file_get_contents($root . '/' . $relative);

    if (!is_string($content)) {
        throw new RuntimeException("Required product document is missing: {$relative}");
    }

    return $content;
};

$inventory = $read('docs/product/feature-inventory.md');
$specification = $read('docs/product/unified-product-specification.md');
$traceability = $read('docs/product/requirements-traceability.md');
$roadmap = $read('docs/product/module-build-roadmap.md');
$dataContract = $read('docs/architecture/unified-customer-data-contract.md');
$screenInventory = $read('docs/product/screen-inventory.md');
$navigation = $read('espocrm/client/custom/tenant-workspace.js');
$adminPanel = $read('espocrm/custom/Espo/Custom/Resources/metadata/app/adminPanel.json');
$readme = $read('README.md');

$count = static function (string $pattern, string $content): int {
    $result = preg_match_all($pattern, $content, $matches);

    if ($result === false) {
        throw new RuntimeException("Invalid alignment-test pattern: {$pattern}");
    }

    return $result;
};

$expectedCounts = [
    'advanced requirements' => [86, '/^\| F-\d{3} /m', $inventory],
    'non-functional requirements' => [70, '/^\| NFR-\d{3} /m', $inventory],
    'detailed specification sections' => [47, '/^## \d+\. /m', $specification],
    'traceability rows' => [47, '/^\| \d+\. /m', $traceability],
    'modules' => [23, '/^\| M\d{2} /m', $roadmap],
    'phases' => [13, '/^### Phase \d+ /m', $roadmap],
];

foreach ($expectedCounts as $label => [$expected, $pattern, $content]) {
    $actual = $count($pattern, $content);

    if ($actual !== $expected) {
        throw new RuntimeException("Expected {$expected} {$label}, found {$actual}.");
    }
}

preg_match_all('/^## (\d+)\. /m', $specification, $sectionMatches);
$sectionNumbers = array_map('intval', $sectionMatches[1] ?? []);

if ($sectionNumbers !== range(1, 47)) {
    throw new RuntimeException('Detailed specification sections must remain ordered from 1 through 47.');
}

$requiredContracts = [
    [$roadmap, 'Phase 3 - Unified Customer, CRM, Sales and Service Product'],
    [$roadmap, 'Phase 5 - Customer Timeline, Tracking and Event Foundation'],
    [$roadmap, 'Phase 6 - Marketing Email and Visual Automation'],
    [$traceability, 'Event ingestion and identity resolution precede tracked marketing email'],
    [$dataContract, 'nexa_identity_link'],
    [$dataContract, 'nexa_relationship_edge'],
    [$dataContract, 'Lead Conversion Contract'],
    [$screenInventory, 'Canonical Tenant Navigation'],
    [$navigation, "label: 'CRM'"],
    [$navigation, "label: 'Sales'"],
    [$navigation, "label: 'Marketing'"],
    [$navigation, "label: 'Automation'"],
    [$navigation, "label: 'Service'"],
    [$navigation, "label: 'Analytics'"],
    [$navigation, "label: 'Data & Integrations'"],
    [$adminPanel, '"nexaWorkspace"'],
    [$readme, 'docs/product/unified-product-specification.md'],
    [$readme, 'docs/product/requirements-traceability.md'],
];

foreach ($requiredContracts as [$content, $required]) {
    if (!str_contains($content, $required)) {
        throw new RuntimeException("Product alignment contract is missing: {$required}");
    }
}

if (str_contains($navigation, 'SaaS Administration')) {
    throw new RuntimeException('Tenant navigation must not expose the platform-operator administration console.');
}

$adminPanelData = json_decode($adminPanel, true, flags: JSON_THROW_ON_ERROR);

if (!isset($adminPanelData['nexaWorkspace']['itemList'])) {
    throw new RuntimeException('Workspace Management must extend the existing Administration application.');
}

if (str_contains($inventory, 'Missing Non-Functional SaaS Requirements')) {
    throw new RuntimeException('Approved non-functional requirements must not be described as missing.');
}

echo "Product requirements alignment tests passed.\n";