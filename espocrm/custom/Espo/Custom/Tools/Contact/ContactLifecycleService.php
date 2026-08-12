<?php

namespace Espo\Custom\Tools\Contact;

use DateTimeImmutable;
use DateTimeZone;
use Espo\Core\Acl;
use Espo\Core\Acl\Table;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\ORM\EntityManager;
use Espo\Core\Record\DeleteParams;
use Espo\Core\Record\ServiceContainer;
use Espo\Entities\User;

/** Enforces Contact ownership and recoverability at the authenticated API boundary. */
final class ContactLifecycleService
{
    private const MAX_BATCH_SIZE = 500;

    public function __construct(
        private EntityManager $entityManager,
        private ServiceContainer $recordServiceContainer,
        private Acl $acl,
        private User $user,
    ) {}

    /** @param mixed[] $ids @return array{count: int, ids: string[]} */
    public function delete(array $ids): array
    {
        $ids = $this->normalizeIds($ids);

        if (!$this->acl->check('Contact', Table::ACTION_DELETE)) {
            throw new Forbidden('You do not have permission to delete contacts.');
        }

        $deletedIds = [];
        $service = $this->recordServiceContainer->get('Contact');

        $this->entityManager->getTransactionManager()->run(function () use ($ids, $service, &$deletedIds): void {
            foreach ($ids as $id) {
                // TenantQueryProcessor restricts every lookup to the trusted tenant
                // and service before ownership is evaluated.
                $contact = $this->entityManager->getRDBRepository('Contact')->getById($id);

                // Espo's record ACL resolves the user's configured ownership
                // level (own, team or all). TenantQueryProcessor has already
                // restricted the record to the active tenant and service.
                if (!$contact || !$this->acl->check($contact, Table::ACTION_DELETE)) {
                    throw new Forbidden('One or more selected contacts cannot be deleted.');
                }

                $contact->set('deletedAt', $this->now());
                $contact->set('deletedById', $this->user->getId());
                $this->entityManager->saveEntity($contact);
                $service->delete($id, DeleteParams::create());
                $deletedIds[] = $id;
            }
        });

        return ['count' => count($deletedIds), 'ids' => $deletedIds];
    }

    /** @return array{list: array<int, array<string, mixed>>, total: int} */
    public function getTrash(): array
    {
        $this->requireTenantAdmin();

        $query = $this->entityManager->getQueryBuilder()
            ->select(['id', 'firstName', 'lastName', 'emailAddress', 'deletedAt'])
            ->from('Contact')
            ->where(['deleted' => true])
            ->order('deletedAt', 'DESC')
            ->limit(200)
            ->withDeleted()
            ->build();
        $collection = $this->entityManager->getRDBRepository('Contact')->clone($query)->find();
        $list = [];

        foreach ($collection as $contact) {
            $list[] = [
                'id' => $contact->getId(),
                'name' => trim((string) $contact->get('firstName') . ' ' . (string) $contact->get('lastName')),
                'emailAddress' => $contact->get('emailAddress'),
                'deletedAt' => $contact->get('deletedAt'),
            ];
        }

        return ['list' => $list, 'total' => count($list)];
    }

    /** @param mixed[] $ids @return array{count: int, ids: string[]} */
    public function restore(array $ids): array
    {
        $this->requireTenantAdmin();
        $ids = $this->normalizeIds($ids);
        $restoredIds = [];
        $repository = $this->entityManager->getRDBRepository('Contact');

        $this->entityManager->getTransactionManager()->run(function () use ($ids, $repository, &$restoredIds): void {
            foreach ($ids as $id) {
                $query = $this->entityManager->getQueryBuilder()
                    ->select()
                    ->from('Contact')
                    ->where(['id' => $id, 'deleted' => true])
                    ->withDeleted()
                    ->build();
                $contact = $repository->clone($query)->findOne();

                if (!$contact) {
                    throw new Forbidden('A selected contact is outside this tenant or is no longer deleted.');
                }

                $repository->restoreDeleted($id);
                $restoredContact = $repository->getById($id);

                if (!$restoredContact) {
                    throw new Forbidden('The contact could not be restored.');
                }

                $restoredContact->set('deletedAt', null);
                $restoredContact->set('deletedById', null);
                $repository->save($restoredContact);
                $restoredIds[] = $id;
            }
        });

        return ['count' => count($restoredIds), 'ids' => $restoredIds];
    }

    /** @param mixed[] $ids @return string[] */
    private function normalizeIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map(
            static fn ($id): string => trim((string) $id),
            $ids
        ), static fn (string $id): bool => preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $id) === 1)));

        if ($ids === [] || count($ids) > self::MAX_BATCH_SIZE) {
            throw new BadRequest('Select between 1 and 500 contacts.');
        }

        return $ids;
    }

    private function requireTenantAdmin(): void
    {
        if (!$this->user->isAdmin()) {
            throw new Forbidden('Only a tenant administrator can restore contacts.');
        }
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }
}
