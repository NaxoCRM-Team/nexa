<?php

namespace Espo\Custom\Rebuild\Actions;

use Espo\Core\Rebuild\RebuildAction;
use Espo\Core\Tenant\PlatformExecutionGateway;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\SystemUser;
use Espo\Entities\User;
use Espo\ORM\EntityManager;
use Espo\ORM\Name\Attribute;

/**
 * Replaces core's Espo\Core\Rebuild\Actions\AddSystemUser. The stock action looks up
 * the platform's single "system" user scoped to whichever tenant happens to be active,
 * which is a false negative for every tenant except the one that owns that row -
 * leading it to attempt a duplicate insert on the shared `id = 'system'` primary key.
 * Wrapping the lookup/creation in the platform bypass (same pattern as AddSystemData)
 * makes it tenant-agnostic, matching the fact that there is exactly one system user
 * for the whole platform.
 */
class AddSystemUser implements RebuildAction
{
    public function __construct(
        private EntityManager $entityManager,
        private Config $config,
        private SystemUser $systemUser,
        private PlatformExecutionGateway $platformExecutionGateway,
    ) {}

    public function process(): void
    {
        $this->platformExecutionGateway->run(
            'Rebuild global system user',
            fn () => $this->processInternal()
        );
    }

    private function processInternal(): void
    {
        $repository = $this->entityManager->getRDBRepositoryByClass(User::class);

        $user = $repository
            ->where(['userName' => SystemUser::NAME])
            ->findOne();

        if ($user) {
            if ($user->getId() === $this->systemUser->getId()) {
                return;
            }

            $this->entityManager
                ->getQueryExecutor()
                ->execute(
                    $this->entityManager
                        ->getQueryBuilder()
                        ->delete()
                        ->from(User::ENTITY_TYPE)
                        ->where([Attribute::ID => $user->getId()])
                        ->build()
                );
        }

        /** @var array<string, mixed> $attributes */
        $attributes = $this->config->get('systemUserAttributes');

        $user = $repository->getNew();

        $user
            ->set(Attribute::ID, $this->systemUser->getId())
            ->setUserName(SystemUser::NAME)
            ->setType(User::TYPE_SYSTEM);

        $user->setMultiple($attributes);

        $repository->save($user);
    }
}
