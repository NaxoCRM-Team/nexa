<?php

namespace Espo\Core\Tenant;

use InvalidArgumentException;

final readonly class TenantContext
{
    public const LEGACY_LOCAL_ID = '00000000-0000-4000-8000-000000000001';
    public const CRM_SERVICE_ID = '20000000-0000-4000-8000-000000000001';

    public function __construct(
        public string $tenantId,
        public string $slug,
        public string $source,
        public string $displayName = '',
        public string $serviceId = self::CRM_SERVICE_ID,
    ) {
        if (!$this->isUuid($tenantId)) {
            throw new InvalidArgumentException('Invalid tenant identifier.');
        }

        if (!$this->isUuid($serviceId)) {
            throw new InvalidArgumentException('Invalid service identifier.');
        }

        if ($slug === '' || $source === '') {
            throw new InvalidArgumentException('Tenant slug and source are required.');
        }
    }

    private function isUuid(string $value): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value) === 1;
    }
}
