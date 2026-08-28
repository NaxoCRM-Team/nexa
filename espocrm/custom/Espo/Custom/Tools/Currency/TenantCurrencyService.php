<?php

namespace Espo\Custom\Tools\Currency;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Conflict;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\ORM\EntityManager;
use Espo\Core\Tenant\TenantContext;
use Espo\Core\Tenant\TenantContextStore;
use Espo\Core\Utils\Metadata;
use Espo\Entities\User;
use PDO;
use stdClass;

/** Tenant-admin boundary for native EspoCRM currency configuration. */
final class TenantCurrencyService
{
    public function __construct(
        private EntityManager $entityManager,
        private TenantContextStore $tenantContextStore,
        private Metadata $metadata,
        private User $user,
        private FrankfurterRateProvider $rateProvider,
    ) {}

    /** @return array<string, mixed> */
    public function get(): array
    {
        $context = $this->tenantContextStore->require();
        $this->ensure($context);
        $profile = $this->profile($context);
        $currencies = $this->currencies($context);

        return [
            'baseCurrency' => $profile['base_currency'],
            'defaultCurrency' => $profile['default_currency'],
            'enabledCurrencies' => array_column($currencies, 'currency_code'),
            'rates' => array_reduce($currencies, static function (array $carry, array $row): array {
                $carry[$row['currency_code']] = (float) $row['rate'];
                return $carry;
            }, []),
            'catalogue' => $this->catalogue(),
            'canManage' => $this->user->isAdmin(),
            'rateMode' => $profile['rate_mode'],
            'rateProvider' => $profile['rate_provider'],
            'ratesEffectiveDate' => $profile['rates_effective_date'],
            'ratesUpdatedAt' => $profile['rates_updated_at'],
            'rateHelp' => 'Automatic rates use daily central-bank reference data. Manual override is available when an operational rate is required.',
        ];
    }

    /** @return array{provider: string, effectiveDate: string, rates: array<string, float>} */
    public function previewRates(stdClass $data): array
    {
        if (!$this->user->isAdmin()) {
            throw new Forbidden('Only a tenant administrator can refresh workspace currencies.');
        }

        $catalogue = $this->catalogue();
        $base = $this->code($data->baseCurrency ?? null, $catalogue);
        $enabled = $this->enabled($data->enabledCurrencies ?? null, $catalogue);

        if (!in_array($base, $enabled, true)) {
            throw new BadRequest('The base currency must remain enabled.');
        }

        return $this->rateProvider->fetch($base, $enabled);
    }

    /** @return array<string, mixed> */
    public function save(stdClass $data): array
    {
        if (!$this->user->isAdmin()) {
            throw new Forbidden('Only a tenant administrator can manage workspace currencies.');
        }

        $context = $this->tenantContextStore->require();
        $catalogue = $this->catalogue();
        $base = $this->code($data->baseCurrency ?? null, $catalogue);
        $default = $this->code($data->defaultCurrency ?? null, $catalogue);
        $enabled = $this->enabled($data->enabledCurrencies ?? null, $catalogue);

        if (!in_array($base, $enabled, true) || !in_array($default, $enabled, true)) {
            throw new BadRequest('Base and default currencies must remain enabled.');
        }

        $rateMode = strtolower(trim((string) ($data->rateMode ?? 'manual')));
        if (!in_array($rateMode, ['automatic', 'manual'], true)) {
            throw new BadRequest('Choose automatic reference rates or manual override.');
        }

        if ($rateMode === 'automatic') {
            $rateSnapshot = $this->rateProvider->fetch($base, $enabled);
            $rates = $rateSnapshot['rates'];
            $rateSource = $rateSnapshot['provider'];
            $effectiveDate = $rateSnapshot['effectiveDate'];
        } else {
            $rates = $this->rates($data->rates ?? null, $enabled, $base);
            $rateSource = 'manual';
            $effectiveDate = null;
        }
        $this->rejectCurrenciesInUse($context, $enabled);
        $pdo = $this->entityManager->getPDO();
        $ownsTransaction = !$pdo->inTransaction();

        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }

        try {
            $profile = $pdo->prepare(
                'INSERT INTO nexa_tenant_currency_profile (tenant_id, service_id, base_currency, default_currency, rate_mode, rate_provider, rates_effective_date, rates_updated_at, modified_by_id) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(6), ?) ' .
                'ON DUPLICATE KEY UPDATE base_currency = VALUES(base_currency), default_currency = VALUES(default_currency), rate_mode = VALUES(rate_mode), rate_provider = VALUES(rate_provider), rates_effective_date = VALUES(rates_effective_date), rates_updated_at = NOW(6), modified_by_id = VALUES(modified_by_id)'
            );
            $profile->execute([$context->tenantId, $context->serviceId, $base, $default, $rateMode, $rateSource, $effectiveDate, $this->user->getId()]);
            $pdo->prepare('DELETE FROM nexa_tenant_currency_rate WHERE tenant_id = ? AND service_id = ?')
                ->execute([$context->tenantId, $context->serviceId]);
            $insert = $pdo->prepare('INSERT INTO nexa_tenant_currency_rate (tenant_id, service_id, currency_code, rate, position) VALUES (?, ?, ?, ?, ?)');
            foreach ($enabled as $position => $code) {
                $insert->execute([$context->tenantId, $context->serviceId, $code, $rates[$code], $position]);
            }
            $snapshot = json_encode(array_map(
                static fn (string $code): array => ['code' => $code, 'rate' => $rates[$code]],
                $enabled,
            ), JSON_THROW_ON_ERROR);
            $pdo->prepare('INSERT INTO nexa_tenant_currency_history (id, tenant_id, service_id, base_currency, default_currency, currencies_json, rate_source, rate_effective_date, changed_by_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$this->uuid(), $context->tenantId, $context->serviceId, $base, $default, $snapshot, $rateSource, $effectiveDate, $this->user->getId()]);
            if ($ownsTransaction) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $e;
        }

        return $this->get();
    }

    /** @return array<int, string> */
    public function enabledCodes(): array
    {
        return $this->get()['enabledCurrencies'];
    }

    public function defaultCurrency(): string
    {
        return (string) $this->get()['defaultCurrency'];
    }

    private function ensure(TenantContext $context): void
    {
        $pdo = $this->entityManager->getPDO();
        $pdo->prepare("INSERT IGNORE INTO nexa_tenant_currency_profile (tenant_id, service_id, base_currency, default_currency) VALUES (?, ?, 'USD', 'USD')")
            ->execute([$context->tenantId, $context->serviceId]);
        $pdo->prepare("INSERT INTO nexa_tenant_currency_rate (tenant_id, service_id, currency_code, rate, position) SELECT ?, ?, 'USD', 1, 0 WHERE NOT EXISTS (SELECT 1 FROM nexa_tenant_currency_rate WHERE tenant_id = ? AND service_id = ?)")
            ->execute([$context->tenantId, $context->serviceId, $context->tenantId, $context->serviceId]);
    }

    /** @return array<string, string> */
    private function profile(TenantContext $context): array
    {
        $query = $this->entityManager->getPDO()->prepare('SELECT base_currency, default_currency, rate_mode, rate_provider, rates_effective_date, rates_updated_at FROM nexa_tenant_currency_profile WHERE tenant_id = ? AND service_id = ? LIMIT 1');
        $query->execute([$context->tenantId, $context->serviceId]);
        return $query->fetch(PDO::FETCH_ASSOC) ?: [
            'base_currency' => 'USD', 'default_currency' => 'USD', 'rate_mode' => 'automatic',
            'rate_provider' => null, 'rates_effective_date' => null, 'rates_updated_at' => null,
        ];
    }

    /** @return array<int, array{currency_code: string, rate: string}> */
    private function currencies(TenantContext $context): array
    {
        $query = $this->entityManager->getPDO()->prepare('SELECT currency_code, rate FROM nexa_tenant_currency_rate WHERE tenant_id = ? AND service_id = ? ORDER BY position, currency_code');
        $query->execute([$context->tenantId, $context->serviceId]);
        return $query->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<int, string> */
    private function catalogue(): array
    {
        $list = $this->metadata->get(['app', 'currency', 'list']) ?? [];
        $list = array_values(array_unique(array_filter(array_map('strval', is_array($list) ? $list : []), static fn (string $code): bool => preg_match('/^[A-Z]{3}$/', $code) === 1)));
        sort($list);
        return $list ?: ['USD'];
    }

    /** @param array<int, string> $catalogue */
    private function code(mixed $value, array $catalogue): string
    {
        $code = strtoupper(trim((string) $value));
        if (!in_array($code, $catalogue, true)) throw new BadRequest('Choose a supported ISO currency.');
        return $code;
    }

    /** @param array<int, string> $catalogue @return array<int, string> */
    private function enabled(mixed $value, array $catalogue): array
    {
        if (!is_array($value)) throw new BadRequest('Choose at least one enabled currency.');
        $enabled = [];
        foreach ($value as $item) {
            $code = $this->code($item, $catalogue);
            if (!in_array($code, $enabled, true)) $enabled[] = $code;
        }
        if ($enabled === []) throw new BadRequest('Choose at least one enabled currency.');
        return $enabled;
    }

    /** @param array<int, string> $enabled @return array<string, float> */
    private function rates(mixed $value, array $enabled, string $base): array
    {
        $provided = is_object($value) ? get_object_vars($value) : (is_array($value) ? $value : []);
        $rates = [];
        foreach ($enabled as $code) {
            $rate = $code === $base ? 1.0 : filter_var($provided[$code] ?? null, FILTER_VALIDATE_FLOAT);
            if ($rate === false || $rate <= 0) throw new BadRequest("Enter a positive exchange rate for {$code}.");
            $rates[$code] = (float) $rate;
        }
        return $rates;
    }

    /** @param array<int, string> $enabled */
    private function rejectCurrenciesInUse(TenantContext $context, array $enabled): void
    {
        $existing = array_column($this->currencies($context), 'currency_code');
        $removed = array_values(array_diff($existing, $enabled));
        if ($removed === []) return;
        $marks = implode(',', array_fill(0, count($removed), '?'));
        $params = [$context->tenantId, $context->serviceId, ...$removed];
        foreach ([
            "SELECT COUNT(*) FROM opportunity WHERE tenant_id = ? AND service_id = ? AND deleted = 0 AND amount_currency IN ({$marks})",
            "SELECT COUNT(*) FROM nexa_product WHERE tenant_id = ? AND service_id = ? AND is_active = 1 AND currency IN ({$marks})",
            "SELECT COUNT(*) FROM nexa_quote WHERE tenant_id = ? AND service_id = ? AND currency IN ({$marks})",
        ] as $sql) {
            $query = $this->entityManager->getPDO()->prepare($sql);
            $query->execute($params);
            if ((int) $query->fetchColumn() > 0) throw new Conflict('A currency used by existing opportunities, products or quotes cannot be disabled.');
        }
    }

    private function uuid(): string
    {
        $hex = bin2hex(random_bytes(16));
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }
}
