<?php

namespace Espo\Custom\Tools\Tenant;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\ORM\EntityManager;
use Espo\Core\Tenant\TenantContextStore;
use Espo\Entities\User;

/** Tenant-admin-only mutations for the company profile shown on a user's own detail page. */
final class TenantProfileService
{
    public function __construct(
        private EntityManager $entityManager,
        private User $user,
        private TenantContextStore $tenantContextStore,
    ) {}

    public function updateCompanyName(string $displayName): void
    {
        if (!$this->user->isAdmin()) {
            throw new Forbidden('Only a tenant administrator can change the company name.');
        }

        $displayName = trim($displayName);

        if ($displayName === '' || mb_strlen($displayName) > 190) {
            throw new BadRequest('Enter a company name up to 190 characters.');
        }

        $tenant = $this->tenantContextStore->require();

        $statement = $this->entityManager->getPDO()->prepare(
            'UPDATE nexa_tenant SET display_name = :displayName WHERE id = :tenantId'
        );
        $statement->execute(['displayName' => $displayName, 'tenantId' => $tenant->tenantId]);
    }
}
