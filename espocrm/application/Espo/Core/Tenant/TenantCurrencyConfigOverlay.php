<?php

namespace Espo\Core\Tenant;

use Espo\Core\ORM\EntityManager;
use Espo\Core\Utils\Config;
use PDO;
use Throwable;

/** Applies tenant currency values to EspoCRM's native Config for one execution frame. */
final class TenantCurrencyConfigOverlay
{
    private const KEYS = ['currencyList', 'defaultCurrency', 'baseCurrency', 'currencyRates'];

    public function __construct(
        private EntityManager $entityManager,
        private Config $config,
    ) {}

    public function run(TenantContext $context, callable $callback): mixed
    {
        $original = [];
        foreach (self::KEYS as $key) {
            $original[$key] = $this->config->get($key);
        }

        $settings = $this->load($context);
        if ($settings !== null) {
            $this->config->set($settings, null, true);
        }

        try {
            return $callback();
        } finally {
            $this->config->set($original, null, true);
        }
    }

    /** @return ?array{currencyList: array<int, string>, defaultCurrency: string, baseCurrency: string, currencyRates: array<string, float>} */
    private function load(TenantContext $context): ?array
    {
        try {
            $pdo = $this->entityManager->getPDO();
            $profile = $pdo->prepare('SELECT base_currency, default_currency FROM nexa_tenant_currency_profile WHERE tenant_id = ? AND service_id = ? LIMIT 1');
            $profile->execute([$context->tenantId, $context->serviceId]);
            $row = $profile->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) {
                return null;
            }

            $rateQuery = $pdo->prepare('SELECT currency_code, rate FROM nexa_tenant_currency_rate WHERE tenant_id = ? AND service_id = ? ORDER BY position, currency_code');
            $rateQuery->execute([$context->tenantId, $context->serviceId]);
            $currencyList = [];
            $rates = [];
            foreach ($rateQuery->fetchAll(PDO::FETCH_ASSOC) as $rate) {
                $code = (string) $rate['currency_code'];
                $currencyList[] = $code;
                $rates[$code] = (float) $rate['rate'];
            }

            $base = (string) $row['base_currency'];
            $default = (string) $row['default_currency'];
            if ($currencyList === []) {
                $currencyList = ['USD'];
                $rates = ['USD' => 1.0];
                $base = 'USD';
                $default = 'USD';
            }
            $rates[$base] = 1.0;

            return ['currencyList' => $currencyList, 'defaultCurrency' => $default, 'baseCurrency' => $base, 'currencyRates' => $rates];
        } catch (Throwable) {
            // Install and migration commands must remain usable before the currency tables exist.
            return null;
        }
    }
}
