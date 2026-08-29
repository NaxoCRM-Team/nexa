<?php

declare(strict_types=1);

namespace Espo\Custom\Tools\Sales;

use DateTimeImmutable;
use DateTimeZone;
use Espo\Core\Acl;
use Espo\Core\Acl\Table;
use Espo\Core\Currency\Converter as CurrencyConverter;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Field\Currency;
use Espo\Core\Tenant\ServiceEntitlementChecker;
use Espo\Core\Tenant\TenantContext;
use Espo\Core\Tenant\TenantContextStore;
use Espo\Core\Utils\Config;
use Espo\Entities\User;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use PDO;
use stdClass;

/** Tenant-scoped pipelines, forecasts and product context around native Opportunities. */
final class SalesWorkspaceService
{
    private const FORECAST_CATEGORIES = ['Pipeline', 'Best Case', 'Commit', 'Closed', 'Omitted'];
    private const REQUIRED_FIELDS = ['name', 'accountId', 'amount', 'closeDate', 'nextStep', 'assignedUserId', 'lossReason'];
    private const QUOTE_TRANSITIONS = [
        'Draft' => ['Pending Approval', 'Sent', 'Expired', 'Cancelled'],
        'Pending Approval' => ['Approved', 'Rejected', 'Draft', 'Cancelled'],
        'Approved' => ['Sent', 'Cancelled'],
        'Sent' => ['Accepted', 'Rejected', 'Expired', 'Cancelled'],
        'Accepted' => [],
        'Rejected' => ['Draft', 'Cancelled'],
        'Expired' => ['Draft', 'Cancelled'],
        'Cancelled' => [],
    ];
    private const DEFAULT_STAGES = [
        ['key' => 'prospecting', 'name' => 'Prospecting', 'probability' => 10, 'category' => 'Pipeline'],
        ['key' => 'qualification', 'name' => 'Qualification', 'probability' => 20, 'category' => 'Pipeline'],
        ['key' => 'proposal', 'name' => 'Proposal', 'probability' => 50, 'category' => 'Best Case'],
        ['key' => 'negotiation', 'name' => 'Negotiation', 'probability' => 80, 'category' => 'Commit'],
        ['key' => 'closed-won', 'name' => 'Closed Won', 'probability' => 100, 'category' => 'Closed', 'closed' => true, 'won' => true],
        ['key' => 'closed-lost', 'name' => 'Closed Lost', 'probability' => 0, 'category' => 'Omitted', 'closed' => true],
    ];

    public function __construct(
        private TenantContextStore $tenantContextStore,
        private ServiceEntitlementChecker $entitlements,
        private Acl $acl,
        private EntityManager $entityManager,
        private Config $config,
        private User $user,
        private CurrencyConverter $currencyConverter,
    ) {}

    /** @return array<string, mixed> */
    public function getWorkspace(
        string $range = 'quarter',
        ?string $pipelineId = null,
        ?string $ownerId = null,
        ?string $teamId = null,
    ): array
    {
        $context = $this->context();
        $this->requireOpportunityRead();
        $defaultPipelineId = $this->ensureDefaultPipeline($context);
        $rows = $this->visibleOpportunityRows($context, $range, $pipelineId, $ownerId, $teamId);
        $stages = $this->stages($context);
        $stagePolicies = [];
        foreach ($stages as $stagePolicy) $stagePolicies[$stagePolicy['pipelineId'] . ':' . $stagePolicy['name']] = $stagePolicy;
        $baseCurrency = (string) ($this->config->get('baseCurrency') ?: 'USD');
        $byStage = [];
        $byCategory = [];
        $totals = ['openAmount' => 0.0, 'weightedAmount' => 0.0, 'wonAmount' => 0.0, 'count' => count($rows)];

        foreach ($rows as $row) {
            $stage = (string) ($row['stage'] ?: 'Unassigned');
            $category = (string) ($row['forecast_category'] ?: 'Pipeline');
            $amount = $this->baseAmount((float) ($row['amount'] ?? 0), (string) ($row['amount_currency'] ?: $baseCurrency), $baseCurrency);
            $probability = max(0, min(100, (int) ($row['probability'] ?? 0)));
            $weighted = $amount * $probability / 100;
            $pipeline = (string) ($row['pipeline_id'] ?: $defaultPipelineId);
            $stageKey = $pipeline . ':' . $stage;
            $stagePolicy = $stagePolicies[$stageKey] ?? null;
            $byStage[$stageKey] ??= ['pipelineId' => $pipeline, 'stage' => $stage, 'count' => 0, 'amount' => 0.0, 'weightedAmount' => 0.0];
            $byStage[$stageKey]['count']++;
            $byStage[$stageKey]['amount'] += $amount;
            $byStage[$stageKey]['weightedAmount'] += $weighted;
            $byCategory[$category] ??= ['category' => $category, 'count' => 0, 'amount' => 0.0];
            $byCategory[$category]['count']++;
            $byCategory[$category]['amount'] += $amount;
            if ($stagePolicy && (bool) $stagePolicy['isWon']) $totals['wonAmount'] += $amount;
            elseif (!$stagePolicy || !(bool) $stagePolicy['isClosed']) {
                $totals['openAmount'] += $amount;
                $totals['weightedAmount'] += $weighted;
            }
        }

        return [
            'tenant' => ['id' => $context->tenantId, 'slug' => $context->slug],
            'currency' => $baseCurrency,
            'currencySettings' => $this->currencySettings(),
            'range' => $range,
            'filters' => ['pipelineId' => $pipelineId, 'ownerId' => $ownerId, 'teamId' => $teamId],
            'defaultPipelineId' => $defaultPipelineId,
            'pipelines' => $this->pipelines($context),
            'stages' => $stages,
            'stageSummary' => array_values($byStage),
            'forecastSummary' => array_values($byCategory),
            'totals' => $totals,
            'products' => $this->products($context),
            'recentQuotes' => $this->recentQuotes($context),
            'recentOpportunities' => array_slice(array_map(fn (array $row): array => [
                'id' => $row['id'], 'name' => $row['name'], 'stage' => $row['stage'],
                'amount' => (float) ($row['amount'] ?? 0), 'currency' => $row['amount_currency'] ?: $baseCurrency,
                'probability' => (int) ($row['probability'] ?? 0), 'closeDate' => $row['close_date'],
            ], $rows), 0, 8),
            'owners' => $this->forecastOwners($context),
            'teams' => $this->forecastTeams($context),
            'permissions' => [
                'configure' => $this->user->isAdmin(),
                'create' => $this->acl->check('Opportunity', Table::ACTION_CREATE),
                'edit' => $this->acl->check('Opportunity', Table::ACTION_EDIT),
            ],
            'generatedAt' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DATE_ATOM),
        ];
    }

    /** @return array<string, mixed> */
    public function getOpportunity(string $id): array
    {
        $context = $this->context();
        $entity = $this->entityManager->getRDBRepository('Opportunity')->getById($id);
        if (!$entity || !$this->acl->check($entity, Table::ACTION_READ)) throw new Forbidden('Opportunity is unavailable.');
        $pdo = $this->entityManager->getPDO();
        $params = [$context->tenantId, $context->serviceId, $id];
        $lines = $pdo->prepare('SELECT id, product_id, name, quantity, unit_price, discount_percent, tax_percent, currency, position FROM nexa_opportunity_line_item WHERE tenant_id = ? AND service_id = ? AND opportunity_id = ? ORDER BY position, created_at');
        $lines->execute($params);
        $quotes = $pdo->prepare('SELECT id, quote_number, version_number, name, status, currency, subtotal, discount_total, tax_total, grand_total, valid_until, sent_at, accepted_at, rejected_at, created_at FROM nexa_quote WHERE tenant_id = ? AND service_id = ? AND opportunity_id = ? ORDER BY created_at DESC');
        $quotes->execute($params);
        $history = $pdo->prepare('SELECT amount, currency, expected_revenue, stage, forecast_category, changed_by_id, changed_at FROM nexa_opportunity_revenue_history WHERE tenant_id = ? AND service_id = ? AND opportunity_id = ? ORDER BY changed_at DESC LIMIT 25');
        $history->execute($params);
        return [
            'lineItems' => $lines->fetchAll(PDO::FETCH_ASSOC) ?: [],
            'quotes' => $quotes->fetchAll(PDO::FETCH_ASSOC) ?: [],
            'revenueHistory' => $history->fetchAll(PDO::FETCH_ASSOC) ?: [],
            'pipelines' => $this->pipelines($context),
            'products' => $this->products($context),
            'currencySettings' => $this->currencySettings(),
            'permissions' => ['edit' => $this->acl->check($entity, Table::ACTION_EDIT)],
        ];
    }

    /** @return array{id: string, name: string} */
    public function createPipeline(stdClass $data): array
    {
        $context = $this->context();
        $this->requireTenantAdmin();
        $name = trim((string) ($data->name ?? ''));
        if ($name === '' || mb_strlen($name) > 160) throw new BadRequest('Enter a pipeline name up to 160 characters.');
        $key = $this->key((string) ($data->key ?? $name));
        $id = $this->uuid();
        $pdo = $this->entityManager->getPDO();
        $position = $this->nextPosition('nexa_sales_pipeline', $context);
        $pdo->prepare('INSERT INTO nexa_sales_pipeline (id, tenant_id, service_id, pipeline_key, name, description, position, created_by_id, modified_by_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$id, $context->tenantId, $context->serviceId, $key, $name, trim((string) ($data->description ?? '')) ?: null, $position, $this->user->getId(), $this->user->getId()]);
        $this->insertDefaultStages($context, $id);
        return ['id' => $id, 'name' => $name];
    }

    /** @return array<string, mixed> */
    public function updatePipeline(string $id, stdClass $data): array
    {
        $context = $this->context();
        $this->requireTenantAdmin();
        $name = trim((string) ($data->name ?? ''));
        if ($name === '' || mb_strlen($name) > 160) throw new BadRequest('Enter a pipeline name up to 160 characters.');
        $pipeline = $this->pipeline($context, $id);
        $makeDefault = filter_var($data->isDefault ?? false, FILTER_VALIDATE_BOOL);
        $pdo = $this->entityManager->getPDO();
        $pdo->beginTransaction();
        try {
            if ($makeDefault) {
                $pdo->prepare('UPDATE nexa_sales_pipeline SET is_default = 0 WHERE tenant_id = ? AND service_id = ?')
                    ->execute([$context->tenantId, $context->serviceId]);
            }
            $pdo->prepare('UPDATE nexa_sales_pipeline SET name = ?, description = ?, is_default = ?, modified_by_id = ? WHERE id = ? AND tenant_id = ? AND service_id = ? AND is_active = 1')
                ->execute([$name, trim((string) ($data->description ?? '')) ?: null, $makeDefault || (bool) $pipeline['is_default'] ? 1 : 0, $this->user->getId(), $id, $context->tenantId, $context->serviceId]);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        return ['id' => $id, 'name' => $name, 'isDefault' => $makeDefault || (bool) $pipeline['is_default']];
    }

    public function archivePipeline(string $id): void
    {
        $context = $this->context();
        $this->requireTenantAdmin();
        $pipeline = $this->pipeline($context, $id);
        if ((bool) $pipeline['is_default']) throw new BadRequest('Choose another default pipeline before archiving this one.');
        $count = $this->entityManager->getPDO()->prepare('SELECT COUNT(*) FROM opportunity WHERE tenant_id = ? AND service_id = ? AND pipeline_id = ? AND deleted = 0');
        $count->execute([$context->tenantId, $context->serviceId, $id]);
        if ((int) $count->fetchColumn() > 0) throw new BadRequest('Move active opportunities to another pipeline before archiving this one.');
        $this->entityManager->getPDO()->prepare('UPDATE nexa_sales_pipeline SET is_active = 0, archived_at = NOW(6), modified_by_id = ? WHERE id = ? AND tenant_id = ? AND service_id = ?')
            ->execute([$this->user->getId(), $id, $context->tenantId, $context->serviceId]);
    }

    /** @param string[] $ids */
    public function reorderPipelines(array $ids): void
    {
        $context = $this->context();
        $this->requireTenantAdmin();
        $this->reorderTenantRows('nexa_sales_pipeline', $context, $ids);
    }

    /** @return array{id: string, name: string} */
    public function createProduct(stdClass $data): array
    {
        $context = $this->context();
        $this->requireTenantAdmin();
        $name = trim((string) ($data->name ?? ''));
        $sku = strtoupper(trim((string) ($data->sku ?? '')));
        $price = filter_var($data->unitPrice ?? null, FILTER_VALIDATE_FLOAT);
        $currency = strtoupper(trim((string) ($data->currency ?? ($this->config->get('defaultCurrency') ?: 'USD'))));
        $tax = filter_var($data->taxPercent ?? 0, FILTER_VALIDATE_FLOAT);
        $unit = trim((string) ($data->unit ?? 'each')) ?: 'each';
        $this->validateProduct($name, $sku, $price, $currency, $tax, $unit);
        $id = $this->uuid();
        $this->entityManager->getPDO()->prepare('INSERT INTO nexa_product (id, tenant_id, service_id, sku, name, description, unit, unit_price, currency, tax_percent, created_by_id, modified_by_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$id, $context->tenantId, $context->serviceId, $sku, $name, trim((string) ($data->description ?? '')) ?: null, $unit, $price, $currency, $tax, $this->user->getId(), $this->user->getId()]);
        return ['id' => $id, 'name' => $name];
    }

    /** @return array<string, mixed> */
    public function updateProduct(string $id, stdClass $data): array
    {
        $context = $this->context();
        $this->requireTenantAdmin();
        $name = trim((string) ($data->name ?? ''));
        $sku = strtoupper(trim((string) ($data->sku ?? '')));
        $price = filter_var($data->unitPrice ?? null, FILTER_VALIDATE_FLOAT);
        $currency = strtoupper(trim((string) ($data->currency ?? '')));
        $tax = filter_var($data->taxPercent ?? 0, FILTER_VALIDATE_FLOAT);
        $unit = trim((string) ($data->unit ?? 'each')) ?: 'each';
        $this->validateProduct($name, $sku, $price, $currency, $tax, $unit);
        $statement = $this->entityManager->getPDO()->prepare('UPDATE nexa_product SET sku = ?, name = ?, description = ?, unit = ?, unit_price = ?, currency = ?, tax_percent = ?, modified_by_id = ? WHERE id = ? AND tenant_id = ? AND service_id = ? AND is_active = 1');
        $statement->execute([$sku, $name, trim((string) ($data->description ?? '')) ?: null, $unit, $price, $currency, $tax, $this->user->getId(), $id, $context->tenantId, $context->serviceId]);
        if ($statement->rowCount() === 0) $this->product($context, $id);
        return ['id' => $id, 'name' => $name, 'sku' => $sku, 'unitPrice' => $price, 'currency' => $currency, 'taxPercent' => $tax, 'unit' => $unit];
    }

    public function archiveProduct(string $id): void
    {
        $context = $this->context();
        $this->requireTenantAdmin();
        $this->product($context, $id);
        $this->entityManager->getPDO()->prepare('UPDATE nexa_product SET is_active = 0, archived_at = NOW(6), modified_by_id = ? WHERE id = ? AND tenant_id = ? AND service_id = ?')
            ->execute([$this->user->getId(), $id, $context->tenantId, $context->serviceId]);
    }

    /** @return array<string, mixed> */
    public function createStage(string $pipelineId, stdClass $data): array
    {
        $context = $this->context();
        $this->requireTenantAdmin();
        $this->pipeline($context, $pipelineId);
        $values = $this->validateStage($data);
        $id = $this->uuid();
        $position = $this->nextPosition('nexa_sales_stage', $context, 'pipeline_id', $pipelineId);
        $this->entityManager->getPDO()->prepare('INSERT INTO nexa_sales_stage (id, tenant_id, service_id, pipeline_id, stage_key, name, probability, forecast_category, position, required_fields_json, is_closed, is_won, modified_by_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$id, $context->tenantId, $context->serviceId, $pipelineId, $this->key($values['name']), $values['name'], $values['probability'], $values['forecastCategory'], $position, json_encode($values['requiredFields'], JSON_THROW_ON_ERROR), $values['isClosed'] ? 1 : 0, $values['isWon'] ? 1 : 0, $this->user->getId()]);
        return ['id' => $id] + $values;
    }

    /** @return array<string, mixed> */
    public function updateStage(string $id, stdClass $data): array
    {
        $context = $this->context();
        $this->requireTenantAdmin();
        $values = $this->validateStage($data);
        $pdo = $this->entityManager->getPDO();
        $exists = $pdo->prepare('SELECT 1 FROM nexa_sales_stage WHERE id = ? AND tenant_id = ? AND service_id = ? AND is_active = 1');
        $exists->execute([$id, $context->tenantId, $context->serviceId]);
        if (!$exists->fetchColumn()) throw new BadRequest('The pipeline stage is unavailable.');
        $statement = $pdo->prepare('UPDATE nexa_sales_stage SET name = ?, probability = ?, forecast_category = ?, required_fields_json = ?, is_closed = ?, is_won = ?, modified_by_id = ? WHERE id = ? AND tenant_id = ? AND service_id = ? AND is_active = 1');
        $statement->execute([$values['name'], $values['probability'], $values['forecastCategory'], json_encode($values['requiredFields'], JSON_THROW_ON_ERROR), $values['isClosed'] ? 1 : 0, $values['isWon'] ? 1 : 0, $this->user->getId(), $id, $context->tenantId, $context->serviceId]);
        return ['id' => $id] + $values;
    }

    public function archiveStage(string $id): void
    {
        $context = $this->context();
        $this->requireTenantAdmin();
        $statement = $this->entityManager->getPDO()->prepare('SELECT pipeline_id, name FROM nexa_sales_stage WHERE id = ? AND tenant_id = ? AND service_id = ? AND is_active = 1');
        $statement->execute([$id, $context->tenantId, $context->serviceId]);
        $stage = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$stage) throw new BadRequest('The pipeline stage is unavailable.');
        $remaining = $this->entityManager->getPDO()->prepare('SELECT COUNT(*) FROM nexa_sales_stage WHERE tenant_id = ? AND service_id = ? AND pipeline_id = ? AND is_active = 1');
        $remaining->execute([$context->tenantId, $context->serviceId, $stage['pipeline_id']]);
        if ((int) $remaining->fetchColumn() <= 1) throw new BadRequest('A pipeline must retain at least one active stage.');
        $count = $this->entityManager->getPDO()->prepare('SELECT COUNT(*) FROM opportunity WHERE tenant_id = ? AND service_id = ? AND pipeline_id = ? AND stage = ? AND deleted = 0');
        $count->execute([$context->tenantId, $context->serviceId, $stage['pipeline_id'], $stage['name']]);
        if ((int) $count->fetchColumn() > 0) throw new BadRequest('Move opportunities out of this stage before archiving it.');
        $this->entityManager->getPDO()->prepare('UPDATE nexa_sales_stage SET is_active = 0, archived_at = NOW(6), modified_by_id = ? WHERE id = ? AND tenant_id = ? AND service_id = ?')
            ->execute([$this->user->getId(), $id, $context->tenantId, $context->serviceId]);
    }

    /** @param string[] $ids */
    public function reorderStages(string $pipelineId, array $ids): void
    {
        $context = $this->context();
        $this->requireTenantAdmin();
        $this->pipeline($context, $pipelineId);
        $this->reorderTenantRows('nexa_sales_stage', $context, $ids, 'pipeline_id', $pipelineId);
    }

    /** @return array<string, mixed> */
    public function addLineItem(string $opportunityId, stdClass $data): array
    {
        $context = $this->context();
        $opportunity = $this->editableOpportunity($opportunityId);
        $productId = trim((string) ($data->productId ?? ''));
        $product = $this->product($context, $productId);
        $quantity = filter_var($data->quantity ?? 1, FILTER_VALIDATE_FLOAT);
        $discount = filter_var($data->discountPercent ?? 0, FILTER_VALIDATE_FLOAT);
        $tax = filter_var($data->taxPercent ?? $product['tax_percent'] ?? 0, FILTER_VALIDATE_FLOAT);
        $unitPrice = filter_var($data->unitPrice ?? $product['unit_price'], FILTER_VALIDATE_FLOAT);
        $transactionCurrency = (string) ($opportunity->get('amountCurrency') ?: $this->config->get('defaultCurrency') ?: 'USD');
        $currency = strtoupper(trim((string) ($data->currency ?? $transactionCurrency)));
        $this->validateLineItem($quantity, $unitPrice, $discount, $tax, $currency);
        if ($currency !== $transactionCurrency) {
            throw new BadRequest("Opportunity line items must use {$transactionCurrency}.");
        }
        $existingCurrency = $this->lineItemCurrency($context, $opportunityId);
        if ($existingCurrency !== null && $existingCurrency !== $currency) {
            throw new BadRequest("All opportunity line items must use {$existingCurrency}.");
        }
        $pdo = $this->entityManager->getPDO();
        $positionStatement = $pdo->prepare('SELECT COALESCE(MAX(position), 0) + 10 FROM nexa_opportunity_line_item WHERE tenant_id = ? AND service_id = ? AND opportunity_id = ?');
        $positionStatement->execute([$context->tenantId, $context->serviceId, $opportunityId]);
        $position = (int) $positionStatement->fetchColumn();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) $pdo->beginTransaction();
        try {
            $id = $this->uuid();
            $pdo->prepare('INSERT INTO nexa_opportunity_line_item (id, tenant_id, service_id, opportunity_id, product_id, name, quantity, unit_price, discount_percent, tax_percent, currency, position) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$id, $context->tenantId, $context->serviceId, $opportunityId, $productId, $product['name'], $quantity, $unitPrice, $discount, $tax, $currency, $position]);
            $this->recalculateOpportunity($context, $opportunity, $currency);
            if ($ownsTransaction) $pdo->commit();
        } catch (\Throwable $e) {
            if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        return $this->getOpportunity($opportunityId);
    }

    /** @return array<string, mixed> */
    public function updateLineItem(string $opportunityId, string $id, stdClass $data): array
    {
        $context = $this->context();
        $opportunity = $this->editableOpportunity($opportunityId);
        $line = $this->lineItem($context, $opportunityId, $id);
        $quantity = filter_var($data->quantity ?? $line['quantity'], FILTER_VALIDATE_FLOAT);
        $unitPrice = filter_var($data->unitPrice ?? $line['unit_price'], FILTER_VALIDATE_FLOAT);
        $discount = filter_var($data->discountPercent ?? $line['discount_percent'], FILTER_VALIDATE_FLOAT);
        $tax = filter_var($data->taxPercent ?? $line['tax_percent'], FILTER_VALIDATE_FLOAT);
        $transactionCurrency = (string) ($opportunity->get('amountCurrency') ?: $line['currency'] ?: $this->config->get('defaultCurrency') ?: 'USD');
        $currency = strtoupper(trim((string) ($data->currency ?? $transactionCurrency)));
        $this->validateLineItem($quantity, $unitPrice, $discount, $tax, $currency);
        if ($currency !== $transactionCurrency) {
            throw new BadRequest("Opportunity line items must use {$transactionCurrency}.");
        }
        $pdo = $this->entityManager->getPDO();
        $pdo->prepare('UPDATE nexa_opportunity_line_item SET quantity = ?, unit_price = ?, discount_percent = ?, tax_percent = ?, currency = ? WHERE id = ? AND tenant_id = ? AND service_id = ? AND opportunity_id = ?')
            ->execute([$quantity, $unitPrice, $discount, $tax, $currency, $id, $context->tenantId, $context->serviceId, $opportunityId]);
        $this->recalculateOpportunity($context, $opportunity, $currency);
        return $this->getOpportunity($opportunityId);
    }

    /** @return array<string, mixed> */
    public function removeLineItem(string $opportunityId, string $id): array
    {
        $context = $this->context();
        $opportunity = $this->editableOpportunity($opportunityId);
        $line = $this->lineItem($context, $opportunityId, $id);
        $this->entityManager->getPDO()->prepare('DELETE FROM nexa_opportunity_line_item WHERE id = ? AND tenant_id = ? AND service_id = ? AND opportunity_id = ?')
            ->execute([$id, $context->tenantId, $context->serviceId, $opportunityId]);
        $currency = (string) ($opportunity->get('amountCurrency') ?: $line['currency'] ?: $this->config->get('defaultCurrency') ?: 'USD');
        $this->recalculateOpportunity($context, $opportunity, $currency);
        return $this->getOpportunity($opportunityId);
    }

    /** @return array<string, mixed> */
    public function createQuote(string $opportunityId, stdClass $data): array
    {
        $context = $this->context();
        $this->editableOpportunity($opportunityId);
        $name = trim((string) ($data->name ?? ''));
        $validUntil = trim((string) ($data->validUntil ?? ''));
        if ($name === '' || mb_strlen($name) > 200) throw new BadRequest('Enter a quote name up to 200 characters.');
        if ($validUntil !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $validUntil)) throw new BadRequest('Enter a valid quote expiry date.');
        $pdo = $this->entityManager->getPDO();
        $lines = $pdo->prepare('SELECT product_id, name, quantity, unit_price, discount_percent, tax_percent, currency, position FROM nexa_opportunity_line_item WHERE tenant_id = ? AND service_id = ? AND opportunity_id = ? ORDER BY position');
        $lines->execute([$context->tenantId, $context->serviceId, $opportunityId]);
        $items = $lines->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if (!$items) throw new BadRequest('Add at least one product before creating a quote.');
        $currency = (string) $items[0]['currency'];
        $subtotal = 0.0; $discountTotal = 0.0; $taxTotal = 0.0;
        foreach ($items as $item) {
            if ($item['currency'] !== $currency) throw new BadRequest('Quote line items must use one currency.');
            $gross = (float) $item['quantity'] * (float) $item['unit_price'];
            $subtotal += $gross;
            $discount = $gross * (float) $item['discount_percent'] / 100;
            $discountTotal += $discount;
            $taxTotal += ($gross - $discount) * (float) ($item['tax_percent'] ?? 0) / 100;
        }
        $grandTotal = round($subtotal - $discountTotal + $taxTotal, 2);
        $id = $this->uuid();
        $number = 'NQ-' . gmdate('Ymd') . '-' . strtoupper(substr(str_replace('-', '', $id), 0, 6));
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) $pdo->beginTransaction();
        try {
            $pdo->prepare('INSERT INTO nexa_quote (id, tenant_id, service_id, opportunity_id, quote_number, name, currency, subtotal, discount_total, tax_total, grand_total, valid_until, created_by_id, modified_by_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$id, $context->tenantId, $context->serviceId, $opportunityId, $number, $name, $currency, round($subtotal, 2), round($discountTotal, 2), round($taxTotal, 2), $grandTotal, $validUntil ?: null, $this->user->getId(), $this->user->getId()]);
            $snapshot = $pdo->prepare('INSERT INTO nexa_quote_line_item (id, tenant_id, service_id, quote_id, product_id, name, quantity, unit_price, discount_percent, tax_percent, currency, position) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            foreach ($items as $item) {
                $snapshot->execute([$this->uuid(), $context->tenantId, $context->serviceId, $id, $item['product_id'], $item['name'], $item['quantity'], $item['unit_price'], $item['discount_percent'], $item['tax_percent'], $item['currency'], $item['position']]);
            }
            $this->recordQuoteStatus($context, $id, null, 'Draft', 'Quote created.');
            if ($ownsTransaction) $pdo->commit();
        } catch (\Throwable $e) {
            if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        return ['id' => $id, 'quoteNumber' => $number, 'grandTotal' => $grandTotal, 'currency' => $currency];
    }

    /** @return array<string, mixed> */
    public function updateQuoteStatus(string $id, stdClass $data): array
    {
        $context = $this->context();
        $status = trim((string) ($data->status ?? ''));
        $quote = $this->quote($context, $id);
        $this->editableOpportunity((string) $quote['opportunity_id']);
        $from = (string) $quote['status'];
        if (!in_array($status, self::QUOTE_TRANSITIONS[$from] ?? [], true)) throw new BadRequest("A quote cannot move from {$from} to {$status}.");
        $timestampField = match ($status) { 'Sent' => 'sent_at', 'Accepted' => 'accepted_at', 'Rejected' => 'rejected_at', default => null };
        $sql = 'UPDATE nexa_quote SET status = ?, modified_by_id = ?' . ($timestampField ? ", {$timestampField} = NOW(6)" : '') . ' WHERE id = ? AND tenant_id = ? AND service_id = ?';
        $this->entityManager->getPDO()->prepare($sql)->execute([$status, $this->user->getId(), $id, $context->tenantId, $context->serviceId]);
        $this->recordQuoteStatus($context, $id, $from, $status, trim((string) ($data->comment ?? '')) ?: null);
        return ['id' => $id, 'status' => $status, 'allowedTransitions' => self::QUOTE_TRANSITIONS[$status] ?? []];
    }

    /** Apply configured stage rules to UI, API, import and background writes. */
    public function prepareOpportunity(Entity $entity): void
    {
        $context = $this->context(false);
        if (!$context) return;
        $pipelineId = trim((string) $entity->get('pipelineId')) ?: $this->ensureDefaultPipeline($context);
        $entity->set('pipelineId', $pipelineId);
        $statement = $this->entityManager->getPDO()->prepare('SELECT probability, forecast_category, required_fields_json FROM nexa_sales_stage WHERE tenant_id = ? AND service_id = ? AND pipeline_id = ? AND name = ? AND is_active = 1 LIMIT 1');
        $statement->execute([$context->tenantId, $context->serviceId, $pipelineId, (string) ($entity->get('stage') ?: 'Prospecting')]);
        $stage = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$stage) throw new BadRequest('Choose a stage that belongs to the selected pipeline.');
        $entity->set('probability', (int) $stage['probability']);
        $entity->set('forecastCategory', (string) $stage['forecast_category']);
        $requiredFields = json_decode((string) ($stage['required_fields_json'] ?? '[]'), true) ?: [];
        foreach ($requiredFields as $field) {
            $value = $entity->get((string) $field);
            if ($value === null || $value === '' || $value === []) throw new BadRequest("Complete {$field} before moving this opportunity to {$entity->get('stage')}.");
        }
        $amount = $entity->get('amount');
        $entity->set('expectedRevenue', is_numeric($amount) ? round((float) $amount * (int) ($entity->get('probability') ?? 0) / 100, 2) : null);
    }

    /** Append-only history makes revenue movement auditable and reportable. */
    public function recordOpportunity(Entity $entity): void
    {
        $context = $this->context(false);
        if (!$context || (!$entity->isNew() && !$this->revenueFieldsChanged($entity))) return;
        $this->entityManager->getPDO()->prepare('INSERT INTO nexa_opportunity_revenue_history (id, tenant_id, service_id, opportunity_id, account_id, amount, currency, expected_revenue, stage, forecast_category, changed_by_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$this->uuid(), $context->tenantId, $context->serviceId, $entity->getId(), $entity->get('accountId'), $entity->get('amount'), $entity->get('amountCurrency'), $entity->get('expectedRevenue'), $entity->get('stage'), $entity->get('forecastCategory'), $this->user->getId()]);
    }

    private function context(bool $required = true): ?TenantContext
    {
        $context = $this->tenantContextStore->current();
        if (!$context) {
            if (!$required) return null;
            $context = $this->tenantContextStore->require();
        }
        if (!$this->entitlements->isEnabled('crm')) {
            if ($required) throw new Forbidden('CRM is not enabled for this workspace.');
            return null;
        }
        return $context;
    }

    private function requireOpportunityRead(): void
    {
        if (!$this->acl->checkScope('Opportunity', Table::ACTION_READ)) throw new Forbidden('Sales data is unavailable for this role.');
    }

    private function requireTenantAdmin(): void
    {
        if (!$this->user->isAdmin()) throw new Forbidden('Only a tenant administrator can configure sales data.');
    }

    private function ensureDefaultPipeline(TenantContext $context): string
    {
        $pdo = $this->entityManager->getPDO();
        $select = $pdo->prepare('SELECT id FROM nexa_sales_pipeline WHERE tenant_id = ? AND service_id = ? AND is_default = 1 AND is_active = 1 LIMIT 1');
        $select->execute([$context->tenantId, $context->serviceId]);
        $id = $select->fetchColumn();
        if (is_string($id) && $id !== '') return $id;
        $id = $this->deterministicUuid("{$context->tenantId}:{$context->serviceId}:sales:default");
        $pdo->prepare('INSERT INTO nexa_sales_pipeline (id, tenant_id, service_id, pipeline_key, name, is_default, created_by_id) VALUES (?, ?, ?, ?, ?, 1, ?) ON DUPLICATE KEY UPDATE is_default = 1, is_active = 1')
            ->execute([$id, $context->tenantId, $context->serviceId, 'default-sales', 'Default sales pipeline', $this->user->getId()]);
        $this->insertDefaultStages($context, $id);
        return $id;
    }

    private function insertDefaultStages(TenantContext $context, string $pipelineId): void
    {
        $statement = $this->entityManager->getPDO()->prepare('INSERT INTO nexa_sales_stage (id, tenant_id, service_id, pipeline_id, stage_key, name, probability, forecast_category, position, is_closed, is_won) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE name = VALUES(name), probability = VALUES(probability), forecast_category = VALUES(forecast_category), position = VALUES(position), is_active = 1');
        foreach (self::DEFAULT_STAGES as $position => $stage) {
            $statement->execute([$this->deterministicUuid("{$pipelineId}:{$stage['key']}"), $context->tenantId, $context->serviceId, $pipelineId, $stage['key'], $stage['name'], $stage['probability'], $stage['category'], ($position + 1) * 10, !empty($stage['closed']) ? 1 : 0, !empty($stage['won']) ? 1 : 0]);
        }
    }

    private function pipelines(TenantContext $context): array
    {
        $statement = $this->entityManager->getPDO()->prepare('SELECT id, pipeline_key AS pipelineKey, name, description, position, is_default AS isDefault FROM nexa_sales_pipeline WHERE tenant_id = ? AND service_id = ? AND is_active = 1 ORDER BY is_default DESC, position, name');
        $statement->execute([$context->tenantId, $context->serviceId]);
        return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function stages(TenantContext $context): array
    {
        $statement = $this->entityManager->getPDO()->prepare('SELECT id, pipeline_id AS pipelineId, stage_key AS stageKey, name, probability, forecast_category AS forecastCategory, position, required_fields_json AS requiredFields, is_closed AS isClosed, is_won AS isWon FROM nexa_sales_stage WHERE tenant_id = ? AND service_id = ? AND is_active = 1 ORDER BY pipeline_id, position');
        $statement->execute([$context->tenantId, $context->serviceId]);
        return array_map(static function (array $row): array {
            $row['probability'] = (int) $row['probability'];
            $row['position'] = (int) $row['position'];
            $row['requiredFields'] = json_decode((string) ($row['requiredFields'] ?? '[]'), true) ?: [];
            return $row;
        }, $statement->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    private function products(TenantContext $context): array
    {
        $statement = $this->entityManager->getPDO()->prepare('SELECT id, sku, name, description, unit, unit_price AS unitPrice, currency, tax_percent AS taxPercent FROM nexa_product WHERE tenant_id = ? AND service_id = ? AND is_active = 1 ORDER BY name LIMIT 500');
        $statement->execute([$context->tenantId, $context->serviceId]);
        return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function recentQuotes(TenantContext $context): array
    {
        $statement = $this->entityManager->getPDO()->prepare('SELECT q.id, q.quote_number AS quoteNumber, q.name, q.status, q.currency, q.grand_total AS grandTotal, q.created_at AS createdAt, o.id AS opportunityId, o.name AS opportunityName FROM nexa_quote q INNER JOIN opportunity o ON o.id = q.opportunity_id AND o.tenant_id = q.tenant_id AND o.service_id = q.service_id AND o.deleted = 0 WHERE q.tenant_id = ? AND q.service_id = ? ORDER BY q.created_at DESC LIMIT 20');
        $statement->execute([$context->tenantId, $context->serviceId]);
        return array_values(array_filter($statement->fetchAll(PDO::FETCH_ASSOC) ?: [], function (array $row): bool {
            $entity = $this->entityManager->getRDBRepository('Opportunity')->getById((string) $row['opportunityId']);
            return $entity !== null && $this->acl->check($entity, Table::ACTION_READ);
        }));
    }

    /** @return array<string, mixed> */
    private function product(TenantContext $context, string $id): array
    {
        if ($id === '') throw new BadRequest('Select a product.');
        $statement = $this->entityManager->getPDO()->prepare('SELECT id, sku, name, description, unit, unit_price, currency, tax_percent FROM nexa_product WHERE id = ? AND tenant_id = ? AND service_id = ? AND is_active = 1 LIMIT 1');
        $statement->execute([$id, $context->tenantId, $context->serviceId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new BadRequest('The selected product is unavailable.');
        return $row;
    }

    private function editableOpportunity(string $id): Entity
    {
        $entity = $this->entityManager->getRDBRepository('Opportunity')->getById($id);
        if (!$entity || !$this->acl->check($entity, Table::ACTION_EDIT)) throw new Forbidden('This opportunity cannot be changed by your role.');
        return $entity;
    }

    private function lineItemCurrency(TenantContext $context, string $opportunityId): ?string
    {
        $statement = $this->entityManager->getPDO()->prepare('SELECT currency FROM nexa_opportunity_line_item WHERE tenant_id = ? AND service_id = ? AND opportunity_id = ? LIMIT 1');
        $statement->execute([$context->tenantId, $context->serviceId, $opportunityId]);
        $currency = $statement->fetchColumn();
        return is_string($currency) && $currency !== '' ? $currency : null;
    }

    private function recalculateOpportunity(TenantContext $context, Entity $opportunity, string $currency): void
    {
        $statement = $this->entityManager->getPDO()->prepare('SELECT COALESCE(SUM(quantity * unit_price * (1 - discount_percent / 100) * (1 + tax_percent / 100)), 0) FROM nexa_opportunity_line_item WHERE tenant_id = ? AND service_id = ? AND opportunity_id = ?');
        $statement->execute([$context->tenantId, $context->serviceId, $opportunity->getId()]);
        $opportunity->set('amount', round((float) $statement->fetchColumn(), 2));
        $opportunity->set('amountCurrency', $currency);
        $this->entityManager->saveEntity($opportunity);
    }

    private function baseAmount(float $amount, string $currency, string $baseCurrency): float
    {
        if ($currency === $baseCurrency || $amount === 0.0) return $amount;
        try {
            return (float) $this->currencyConverter->convert(new Currency((string) $amount, $currency), $baseCurrency)->getAmountAsString();
        } catch (\RuntimeException) {
            throw new BadRequest("Configure an exchange rate from {$currency} to {$baseCurrency} before calculating this forecast.");
        }
    }

    private function visibleOpportunityRows(
        TenantContext $context,
        string $range,
        ?string $pipelineId = null,
        ?string $ownerId = null,
        ?string $teamId = null,
    ): array
    {
        $where = 'tenant_id = ? AND service_id = ? AND deleted = 0';
        $params = [$context->tenantId, $context->serviceId];
        if ($range !== 'all') {
            [$start, $end] = $this->forecastRange($range);
            $where .= ' AND close_date BETWEEN ? AND ?';
            $params[] = $start;
            $params[] = $end;
        }
        if ($pipelineId) {
            $this->pipeline($context, $pipelineId);
            $where .= ' AND pipeline_id = ?';
            $params[] = $pipelineId;
        }
        if ($ownerId) {
            $where .= ' AND assigned_user_id = ?';
            $params[] = $ownerId;
        }
        if ($teamId) {
            $where .= ' AND EXISTS (SELECT 1 FROM entity_team et WHERE et.entity_id = opportunity.id AND et.entity_type = \'Opportunity\' AND et.team_id = ?)';
            $params[] = $teamId;
        }
        $statement = $this->entityManager->getPDO()->prepare("SELECT id, name, pipeline_id, stage, probability, forecast_category, amount, amount_currency, close_date, assigned_user_id, created_at FROM opportunity WHERE {$where} ORDER BY modified_at DESC LIMIT 5000");
        $statement->execute($params);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if ($this->acl->checkReadAll('Opportunity')) return $rows;
        return array_values(array_filter($rows, function (array $row): bool {
            $entity = $this->entityManager->getRDBRepository('Opportunity')->getById((string) $row['id']);
            return $entity !== null && $this->acl->check($entity, Table::ACTION_READ);
        }));
    }

    /** @return array<string, mixed> */
    private function pipeline(TenantContext $context, string $id): array
    {
        $statement = $this->entityManager->getPDO()->prepare('SELECT id, name, is_default FROM nexa_sales_pipeline WHERE id = ? AND tenant_id = ? AND service_id = ? AND is_active = 1 LIMIT 1');
        $statement->execute([$id, $context->tenantId, $context->serviceId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new BadRequest('The sales pipeline is unavailable.');
        return $row;
    }

    /** @return array<string, mixed> */
    private function lineItem(TenantContext $context, string $opportunityId, string $id): array
    {
        $statement = $this->entityManager->getPDO()->prepare('SELECT id, quantity, unit_price, discount_percent, tax_percent, currency FROM nexa_opportunity_line_item WHERE id = ? AND tenant_id = ? AND service_id = ? AND opportunity_id = ? LIMIT 1');
        $statement->execute([$id, $context->tenantId, $context->serviceId, $opportunityId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new BadRequest('The opportunity line item is unavailable.');
        return $row;
    }

    /** @return array<string, mixed> */
    private function quote(TenantContext $context, string $id): array
    {
        $statement = $this->entityManager->getPDO()->prepare('SELECT id, opportunity_id, status FROM nexa_quote WHERE id = ? AND tenant_id = ? AND service_id = ? LIMIT 1');
        $statement->execute([$id, $context->tenantId, $context->serviceId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new BadRequest('The quote is unavailable.');
        return $row;
    }

    private function recordQuoteStatus(TenantContext $context, string $quoteId, ?string $from, string $to, ?string $comment): void
    {
        $this->entityManager->getPDO()->prepare('INSERT INTO nexa_quote_status_history (id, tenant_id, service_id, quote_id, from_status, to_status, comment, changed_by_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$this->uuid(), $context->tenantId, $context->serviceId, $quoteId, $from, $to, $comment, $this->user->getId()]);
    }

    /** @return array{name: string, probability: int, forecastCategory: string, requiredFields: string[], isClosed: bool, isWon: bool} */
    private function validateStage(stdClass $data): array
    {
        $name = trim((string) ($data->name ?? ''));
        $probability = filter_var($data->probability ?? null, FILTER_VALIDATE_INT);
        $category = trim((string) ($data->forecastCategory ?? ''));
        $requiredFields = is_array($data->requiredFields ?? null) ? array_values(array_unique($data->requiredFields)) : [];
        $isClosed = filter_var($data->isClosed ?? false, FILTER_VALIDATE_BOOL);
        $isWon = filter_var($data->isWon ?? false, FILTER_VALIDATE_BOOL);
        if ($name === '' || mb_strlen($name) > 160) throw new BadRequest('Enter a stage name up to 160 characters.');
        if ($probability === false || $probability < 0 || $probability > 100) throw new BadRequest('Probability must be between 0 and 100.');
        if (!in_array($category, self::FORECAST_CATEGORIES, true)) throw new BadRequest('Choose a supported forecast category.');
        if ($isWon && !$isClosed) throw new BadRequest('A won stage must also be a closed stage.');
        foreach ($requiredFields as $field) {
            if (!is_string($field) || !in_array($field, self::REQUIRED_FIELDS, true)) throw new BadRequest('A required stage field is not supported.');
        }
        return compact('name', 'probability', 'requiredFields', 'isClosed', 'isWon') + ['forecastCategory' => $category];
    }

    private function validateProduct(string $name, string $sku, mixed $price, string $currency, mixed $tax, string $unit): void
    {
        $currencies = (array) ($this->config->get('currencyList') ?: [$this->config->get('defaultCurrency') ?: 'USD']);
        if ($name === '' || mb_strlen($name) > 200 || $sku === '' || mb_strlen($sku) > 100 || $price === false || $price < 0) throw new BadRequest('Product name, SKU and a non-negative price are required.');
        if (!in_array($currency, $currencies, true)) throw new BadRequest('Choose a currency enabled in Administration settings.');
        if ($tax === false || $tax < 0 || $tax > 100) throw new BadRequest('Tax must be between 0 and 100.');
        if (mb_strlen($unit) > 40) throw new BadRequest('Unit must be 40 characters or fewer.');
    }

    private function validateLineItem(mixed $quantity, mixed $unitPrice, mixed $discount, mixed $tax, string $currency): void
    {
        $currencies = (array) ($this->config->get('currencyList') ?: [$this->config->get('defaultCurrency') ?: 'USD']);
        if ($quantity === false || $quantity <= 0 || $unitPrice === false || $unitPrice < 0 || $discount === false || $discount < 0 || $discount > 100 || $tax === false || $tax < 0 || $tax > 100 || !in_array($currency, $currencies, true)) {
            throw new BadRequest('Enter a positive quantity, non-negative price, discount and tax from 0 to 100, and an enabled currency.');
        }
    }

    /** @return array{baseCurrency: string, defaultCurrency: string, enabledCurrencies: array<int, string>, rates: array<string, float>} */
    private function currencySettings(): array
    {
        $base = (string) ($this->config->get('baseCurrency') ?: 'USD');
        $default = (string) ($this->config->get('defaultCurrency') ?: $base);
        $enabled = array_values((array) ($this->config->get('currencyList') ?: [$default]));
        $rates = array_map('floatval', (array) ($this->config->get('currencyRates') ?: []));
        $rates[$base] = 1.0;

        return ['baseCurrency' => $base, 'defaultCurrency' => $default, 'enabledCurrencies' => $enabled, 'rates' => $rates];
    }

    private function nextPosition(string $table, TenantContext $context, ?string $parentColumn = null, ?string $parentId = null): int
    {
        if (!in_array($table, ['nexa_sales_pipeline', 'nexa_sales_stage'], true)) throw new \LogicException('Unsupported ordered sales table.');
        $sql = "SELECT COALESCE(MAX(position), 0) + 10 FROM {$table} WHERE tenant_id = ? AND service_id = ? AND is_active = 1";
        $params = [$context->tenantId, $context->serviceId];
        if ($parentColumn) { $sql .= " AND {$parentColumn} = ?"; $params[] = $parentId; }
        $statement = $this->entityManager->getPDO()->prepare($sql);
        $statement->execute($params);
        return (int) $statement->fetchColumn();
    }

    /** @param string[] $ids */
    private function reorderTenantRows(string $table, TenantContext $context, array $ids, ?string $parentColumn = null, ?string $parentId = null): void
    {
        if (!in_array($table, ['nexa_sales_pipeline', 'nexa_sales_stage'], true)) throw new \LogicException('Unsupported ordered sales table.');
        if (!$ids || count($ids) !== count(array_unique($ids))) throw new BadRequest('Provide each ordered record exactly once.');
        $where = 'tenant_id = ? AND service_id = ? AND is_active = 1';
        $params = [$context->tenantId, $context->serviceId];
        if ($parentColumn) { $where .= " AND {$parentColumn} = ?"; $params[] = $parentId; }
        $available = $this->entityManager->getPDO()->prepare("SELECT id FROM {$table} WHERE {$where}");
        $available->execute($params);
        $availableIds = array_map('strval', $available->fetchAll(PDO::FETCH_COLUMN) ?: []);
        sort($availableIds); $submitted = array_map('strval', $ids); sort($submitted);
        if ($availableIds !== $submitted) throw new BadRequest('The ordered records do not match the active tenant records.');
        $update = $this->entityManager->getPDO()->prepare("UPDATE {$table} SET position = ? WHERE id = ? AND tenant_id = ? AND service_id = ?");
        foreach ($ids as $index => $id) $update->execute([($index + 1) * 10, $id, $context->tenantId, $context->serviceId]);
    }

    /** @return array<int, array{id: string, name: string}> */
    private function forecastOwners(TenantContext $context): array
    {
        $statement = $this->entityManager->getPDO()->prepare("SELECT id, TRIM(CONCAT(COALESCE(first_name, ''), ' ', COALESCE(last_name, ''))) AS name FROM user WHERE tenant_id = ? AND service_id = ? AND deleted = 0 AND is_active = 1 AND type NOT IN ('system', 'portal', 'api') ORDER BY first_name, last_name");
        $statement->execute([$context->tenantId, $context->serviceId]);
        return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return array<int, array{id: string, name: string}> */
    private function forecastTeams(TenantContext $context): array
    {
        $statement = $this->entityManager->getPDO()->prepare('SELECT id, name FROM team WHERE tenant_id = ? AND service_id = ? AND deleted = 0 ORDER BY name');
        $statement->execute([$context->tenantId, $context->serviceId]);
        return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return array{0: string, 1: string} */
    private function forecastRange(string $range): array
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        if ($range === 'month') return [$now->modify('first day of this month')->format('Y-m-d'), $now->modify('last day of this month')->format('Y-m-d')];
        if ($range === 'year') return [$now->setDate((int) $now->format('Y'), 1, 1)->format('Y-m-d'), $now->setDate((int) $now->format('Y'), 12, 31)->format('Y-m-d')];
        if ($range !== 'quarter') throw new BadRequest('Choose month, quarter, year or all time.');
        $startMonth = ((int) floor(((int) $now->format('n') - 1) / 3) * 3) + 1;
        $start = $now->setDate((int) $now->format('Y'), $startMonth, 1);
        return [$start->format('Y-m-d'), $start->modify('+3 months -1 day')->format('Y-m-d')];
    }

    private function revenueFieldsChanged(Entity $entity): bool
    {
        foreach (['amount', 'amountCurrency', 'stage', 'probability', 'forecastCategory', 'closeDate', 'accountId'] as $field) if ($entity->isAttributeChanged($field)) return true;
        return false;
    }

    private function key(string $value): string
    {
        $key = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($value)) ?? '', '-');
        if ($key === '') throw new BadRequest('Pipeline key is invalid.');
        return substr($key, 0, 80);
    }

    private function deterministicUuid(string $seed): string
    {
        $hash = hash('sha256', $seed);
        return sprintf('%s-%s-4%s-a%s-%s', substr($hash, 0, 8), substr($hash, 8, 4), substr($hash, 13, 3), substr($hash, 17, 3), substr($hash, 20, 12));
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20));
    }
}
