<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root . '/espocrm/bootstrap.php';

use Espo\Core\Application;
use Espo\Core\InjectableFactory;
use Espo\Core\ORM\EntityManager;
use Espo\Core\Tenant\TenantContext;
use Espo\Core\Tenant\TenantContextStore;
use Espo\Custom\Tools\Sales\SalesWorkspaceService;

$assert = static function (bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); };
$shortId = static fn (string $prefix): string => substr($prefix . bin2hex(random_bytes(8)), 0, 17);
$tenantA = new TenantContext('30000000-0000-4000-8000-000000000001', 'isolation-alpha', 'tenant-sales-test');
$tenantB = new TenantContext('30000000-0000-4000-8000-000000000002', 'isolation-beta', 'tenant-sales-test');
$suffix = bin2hex(random_bytes(4));
$alphaFixtures = [];

$application = new Application();
$application->setupSystemUser();
$container = $application->getContainer();
$entityManager = $container->getByClass(EntityManager::class);
$store = $container->getByClass(TenantContextStore::class);
$service = $container->getByClass(InjectableFactory::class)->create(SalesWorkspaceService::class);
$pdo = $entityManager->getPDO();
$pdo->beginTransaction();

try {
    $idA = $shortId('nxsalesa');
    $idB = $shortId('nxsalesb');
    $store->runWith($tenantA, function () use ($entityManager, $service, $idA, $suffix, &$alphaFixtures): void {
        $opportunity = $entityManager->createEntity('Opportunity', ['id' => $idA, 'name' => "Alpha deal {$suffix}", 'amount' => 12000, 'amountCurrency' => 'USD', 'stage' => 'Qualification', 'closeDate' => '2026-12-31']);
        $product = $service->createProduct((object) ['name' => "Alpha product {$suffix}", 'sku' => "ALPHA-{$suffix}", 'unitPrice' => 1200, 'currency' => 'USD']);
        $service->updateProduct($product['id'], (object) ['name' => "Alpha product updated {$suffix}", 'sku' => "ALPHA-{$suffix}", 'unitPrice' => 1250, 'currency' => 'USD', 'taxPercent' => 5, 'unit' => 'seat']);
        $commercial = $service->addLineItem($idA, (object) ['productId' => $product['id'], 'quantity' => 2, 'discountPercent' => 10, 'taxPercent' => 5]);
        $lineId = (string) $commercial['lineItems'][0]['id'];
        $service->updateLineItem($idA, $lineId, (object) ['quantity' => 3, 'unitPrice' => 1250, 'discountPercent' => 10, 'taxPercent' => 5, 'currency' => 'USD']);
        $quote = $service->createQuote($idA, (object) ['name' => "Alpha quote {$suffix}"]);
        $service->updateQuoteStatus($quote['id'], (object) ['status' => 'Sent', 'comment' => 'Sent by tenant test.']);
        $service->updateQuoteStatus($quote['id'], (object) ['status' => 'Accepted']);
        $pipeline = $service->createPipeline((object) ['name' => "Renewals {$suffix}"]);
        $stage = $service->createStage($pipeline['id'], (object) ['name' => 'Contract review', 'probability' => 70, 'forecastCategory' => 'Commit', 'requiredFields' => ['name'], 'isClosed' => false, 'isWon' => false]);
        $service->updateStage($stage['id'], (object) ['name' => 'Commercial review', 'probability' => 75, 'forecastCategory' => 'Commit', 'requiredFields' => ['name', 'assignedUserId'], 'isClosed' => false, 'isWon' => false]);
        $service->archiveStage($stage['id']);
        $alphaFixtures = ['productId' => $product['id'], 'pipelineId' => $pipeline['id'], 'defaultPipelineId' => $opportunity->get('pipelineId'), 'quoteId' => $quote['id']];
    });
    $store->runWith($tenantB, function () use ($entityManager, $service, $idB, $suffix): void {
        $entityManager->createEntity('Opportunity', ['id' => $idB, 'name' => "Beta deal {$suffix}", 'amount' => 9000, 'amountCurrency' => 'USD', 'stage' => 'Proposal', 'closeDate' => '2026-12-31']);
        $service->createProduct((object) ['name' => "Beta product {$suffix}", 'sku' => "BETA-{$suffix}", 'unitPrice' => 900, 'currency' => 'USD']);
    });
    $alpha = $store->runWith($tenantA, fn (): array => $service->getWorkspace('all'));
    $beta = $store->runWith($tenantB, fn (): array => $service->getWorkspace('all'));
    $alphaIds = array_column($alpha['recentOpportunities'], 'id');
    $betaIds = array_column($beta['recentOpportunities'], 'id');
    $assert(in_array($idA, $alphaIds, true) && !in_array($idB, $alphaIds, true), 'Tenant A sales workspace leaked Tenant B opportunities.');
    $assert(in_array($idB, $betaIds, true) && !in_array($idA, $betaIds, true), 'Tenant B sales workspace leaked Tenant A opportunities.');
    $alphaDefaultStages = array_filter($alpha['stages'], fn (array $stage): bool => $stage['pipelineId'] === $alpha['defaultPipelineId']);
    $betaDefaultStages = array_filter($beta['stages'], fn (array $stage): bool => $stage['pipelineId'] === $beta['defaultPipelineId']);
    $assert(count($alphaDefaultStages) === 6 && count($betaDefaultStages) === 6, 'Each tenant needs an independent default sales pipeline.');
    $assert(str_starts_with((string) $alpha['products'][0]['sku'], 'ALPHA-'), 'Tenant A product catalogue is incorrect.');
    $assert(str_starts_with((string) $beta['products'][0]['sku'], 'BETA-'), 'Tenant B product catalogue is incorrect.');
    $alphaCommercial = $store->runWith($tenantA, fn (): array => $service->getOpportunity($idA));
    $betaCommercial = $store->runWith($tenantB, fn (): array => $service->getOpportunity($idB));
    $assert(count($alphaCommercial['lineItems']) === 1 && count($alphaCommercial['quotes']) === 1, 'Tenant A commercial records were not created.');
    $assert(count($betaCommercial['lineItems']) === 0 && count($betaCommercial['quotes']) === 0, 'Tenant B received Tenant A commercial records.');
    $quoteLines = $pdo->prepare('SELECT COUNT(*) FROM nexa_quote_line_item WHERE tenant_id = ?');
    $quoteLines->execute([$tenantA->tenantId]);
    $assert((int) $quoteLines->fetchColumn() === 1, 'Quote line items were not snapshotted for the current tenant.');
    $quoteStatus = $pdo->prepare('SELECT status FROM nexa_quote WHERE tenant_id = ? AND id = ?');
    $quoteStatus->execute([$tenantA->tenantId, $alphaFixtures['quoteId']]);
    $assert($quoteStatus->fetchColumn() === 'Accepted', 'Quote lifecycle did not persist the accepted status.');
    $quoteHistory = $pdo->prepare('SELECT COUNT(*) FROM nexa_quote_status_history WHERE tenant_id = ? AND quote_id = ?');
    $quoteHistory->execute([$tenantA->tenantId, $alphaFixtures['quoteId']]);
    $assert((int) $quoteHistory->fetchColumn() === 3, 'Quote lifecycle did not append complete tenant-scoped status history.');
    $renewals = $store->runWith($tenantA, fn (): array => $service->getWorkspace('all', $alphaFixtures['pipelineId']));
    $assert($renewals['totals']['count'] === 0, 'Pipeline forecast filter included opportunities from another pipeline.');
    $crossTenantBlocked = false;
    try {
        $store->runWith($tenantB, fn () => $service->updateProduct($alphaFixtures['productId'], (object) ['name' => 'Forbidden', 'sku' => 'FORBIDDEN', 'unitPrice' => 1, 'currency' => 'USD']));
    } catch (\Throwable) {
        $crossTenantBlocked = true;
    }
    $assert($crossTenantBlocked, 'Tenant B could update Tenant A product data.');
    $history = $pdo->prepare('SELECT COUNT(*) FROM nexa_opportunity_revenue_history WHERE tenant_id = ? AND opportunity_id = ?');
    $history->execute([$tenantA->tenantId, $idA]);
    $assert((int) $history->fetchColumn() >= 2, 'Opportunity creation and line-item pricing did not append tenant-scoped revenue history.');
    echo "Tenant sales pipeline isolation tests passed.\n";
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
}
