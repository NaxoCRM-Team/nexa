<?php

namespace Espo\Custom\Tools\Account;

use DateTimeImmutable;
use DateTimeZone;
use Espo\Core\Acl;
use Espo\Core\Acl\Table;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\ORM\Entity as CoreEntity;
use Espo\Core\ORM\EntityManager;
use Espo\Core\Record\DeleteParams;
use Espo\Core\Record\ServiceContainer;
use Espo\Core\Select\SearchParams;
use Espo\Entities\ArrayValue;
use Espo\Entities\Attachment;
use Espo\Entities\Note;
use Espo\Entities\User;
use Espo\Entities\UserReaction;
use Espo\ORM\Entity;
use Espo\ORM\Name\Attribute;
use Espo\ORM\Query\DeleteBuilder;
use Espo\ORM\Repository\RDBRepository;
use Espo\ORM\Defs\Params\RelationParam;

/** Enforces tenant-safe Account deletion, recovery and permanent purge. */
final class AccountLifecycleService
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
        if (!$this->acl->check('Account', Table::ACTION_DELETE)) {
            throw new Forbidden('You do not have permission to delete accounts.');
        }

        $deletedIds = [];
        $service = $this->recordServiceContainer->get('Account');
        $this->entityManager->getTransactionManager()->run(function () use ($ids, $service, &$deletedIds): void {
            foreach ($ids as $id) {
                // The central query processor applies tenant and service scope
                // before record-level ownership permission is evaluated.
                $account = $this->entityManager->getRDBRepository('Account')->getById($id);
                if (!$account || !$this->acl->check($account, Table::ACTION_DELETE)) {
                    throw new Forbidden('One or more selected accounts cannot be deleted.');
                }

                $account->set('deletedAt', $this->now());
                $account->set('deletedById', $this->user->getId());
                $this->entityManager->saveEntity($account);
                $service->delete($id, DeleteParams::create());
                $deletedIds[] = $id;
            }
        });

        return ['count' => count($deletedIds), 'ids' => $deletedIds];
    }

    /** @return array{list: array<int, array<string, mixed>>, total: int, userList: array<int, array<string, string>>} */
    public function getTrash(): array
    {
        $this->requireTenantAdmin();
        $query = $this->entityManager->getQueryBuilder()
            ->select(['id', 'name', 'website', 'deletedAt', 'deletedById'])
            ->from('Account')->where(['deleted' => true])->order('deletedAt', 'DESC')
            ->limit(500)->withDeleted()->build();
        $accounts = $this->entityManager->getRDBRepository('Account')->clone($query)->find();
        $list = [];
        $userMap = [];

        foreach ($accounts as $account) {
            $deletedById = (string) ($account->get('deletedById') ?? '');
            if ($deletedById !== '' && !isset($userMap[$deletedById])) {
                $deletedBy = $this->entityManager->getRDBRepository('User')->getById($deletedById);
                $name = $deletedBy ? trim((string) $deletedBy->get('firstName') . ' ' . (string) $deletedBy->get('lastName')) : '';
                $userMap[$deletedById] = [
                    'id' => $deletedById,
                    'name' => $name !== '' ? $name : (string) ($deletedBy?->get('userName') ?? 'Unknown user'),
                ];
            }
            $list[] = [
                'id' => $account->getId(),
                'name' => (string) ($account->get('name') ?: 'Unnamed account'),
                'website' => $account->get('website'),
                'deletedAt' => $account->get('deletedAt'),
                'deletedById' => $deletedById,
                'deletedByName' => $deletedById !== '' ? ($userMap[$deletedById]['name'] ?? 'Unknown user') : 'Unknown user',
            ];
        }

        return ['list' => $list, 'total' => count($list), 'userList' => array_values($userMap)];
    }

    /**
     * Return ACL-scoped relationship totals in one browser request. Each count
     * uses Record Service strict access control rather than an unguarded SQL aggregate.
     *
     * @param mixed[] $ids
     * @return array{counts: array<string, int>}
     */
    public function getContactCounts(array $ids): array
    {
        $ids = $this->normalizeIds($ids);
        if (!$this->acl->check('Account', Table::ACTION_READ) ||
            !$this->acl->check('Contact', Table::ACTION_READ)) {
            throw new Forbidden('You do not have permission to view account contacts.');
        }

        $service = $this->recordServiceContainer->get('Account');
        $params = SearchParams::create()->withMaxSize(1);
        $counts = [];

        foreach ($ids as $id) {
            // findLinked enforces Account read access, Contact read access,
            // strict row ACL, tenant scope and service scope before counting.
            $collection = $service->findLinked($id, 'contacts', $params);
            $counts[$id] = max(0, (int) ($collection->getTotal() ?? 0));
        }

        return ['counts' => $counts];
    }

    /** @param mixed[] $ids @return array{count: int, ids: string[]} */
    public function restore(array $ids): array
    {
        $this->requireTenantAdmin();
        $ids = $this->normalizeIds($ids);
        $restoredIds = [];
        $repository = $this->entityManager->getRDBRepository('Account');

        $this->entityManager->getTransactionManager()->run(function () use ($ids, $repository, &$restoredIds): void {
            foreach ($ids as $id) {
                $query = $this->entityManager->getQueryBuilder()->select()->from('Account')
                    ->where(['id' => $id, 'deleted' => true])->withDeleted()->build();
                $account = $repository->clone($query)->findOne();
                if (!$account) {
                    throw new Forbidden('A selected account is outside this tenant or is no longer deleted.');
                }

                $repository->restoreDeleted($id);
                $restored = $repository->getById($id);
                if (!$restored) throw new Forbidden('The account could not be restored.');
                $restored->set('deletedAt', null);
                $restored->set('deletedById', null);
                $repository->save($restored);
                $restoredIds[] = $id;
            }
        });

        return ['count' => count($restoredIds), 'ids' => $restoredIds];
    }

    /** @param mixed[] $ids @return array{count: int, ids: string[]} */
    public function purge(array $ids): array
    {
        $this->requireTenantAdmin();
        $ids = $this->normalizeIds($ids);
        $purgedIds = [];
        $repository = $this->entityManager->getRDBRepository('Account');

        $this->entityManager->getTransactionManager()->run(function () use ($ids, $repository, &$purgedIds): void {
            foreach ($ids as $id) {
                $query = $this->entityManager->getQueryBuilder()->select()->from('Account')
                    ->where(['id' => $id, 'deleted' => true])->withDeleted()->build();
                $account = $repository->clone($query)->findOne();
                if (!$account) {
                    throw new Forbidden('A selected account is outside this tenant or is no longer deleted.');
                }
                $this->purgeDeletedEntity($account);
                $purgedIds[] = $id;
            }
        });

        return ['count' => count($purgedIds), 'ids' => $purgedIds];
    }

    /** Mirrors native cleanup so explicit purges remove dependent CRM rows. */
    private function purgeDeletedEntity(CoreEntity $entity): void
    {
        if (!$entity->get(Attribute::DELETED)) throw new Forbidden('Only deleted records can be permanently removed.');
        $repository = $this->entityManager->getRepository($entity->getEntityType());
        if (!$repository instanceof RDBRepository) throw new Forbidden('This record cannot be permanently removed.');
        $repository->deleteFromDb($entity->getId(), true);

        foreach ($entity->getRelationList() as $relation) {
            if ($entity->getRelationType($relation) !== Entity::MANY_MANY) continue;
            $relationName = $entity->getRelationParam($relation, RelationParam::RELATION_NAME);
            $midKeys = $entity->getRelationParam($relation, RelationParam::MID_KEYS) ?? [];
            $midKey = $midKeys[0] ?? null;
            if (!$relationName || !$midKey) continue;
            $where = [$midKey => $entity->getId()];
            foreach ($entity->getRelationParam($relation, RelationParam::CONDITIONS) ?? [] as $key => $value) $where[$key] = $value;
            $relationType = ucfirst($relationName);
            if (!$this->entityManager->hasRepository($relationType)) continue;
            $this->entityManager->getQueryExecutor()->execute(
                $this->entityManager->getQueryBuilder()->delete()->from($relationType)->where($where)->build()
            );
        }

        $notes = $this->entityManager->getRDBRepository(Note::ENTITY_TYPE)->clone(
            $this->entityManager->getQueryBuilder()->select()->from(Note::ENTITY_TYPE)->withDeleted()->build()
        )->where(['OR' => [
            ['relatedType' => $entity->getEntityType(), 'relatedId' => $entity->getId()],
            ['parentType' => $entity->getEntityType(), 'parentId' => $entity->getId()],
        ]])->find();
        foreach ($notes as $note) {
            $this->entityManager->removeEntity($note);
            $note->set(Attribute::DELETED, true);
            $this->purgeDeletedEntity($note);
        }

        foreach ($this->entityManager->getRDBRepository(Attachment::ENTITY_TYPE)
            ->where(['parentId' => $entity->getId(), 'parentType' => $entity->getEntityType()])->find() as $attachment) {
            $this->entityManager->removeEntity($attachment);
            $this->entityManager->getRDBRepository(Attachment::ENTITY_TYPE)->deleteFromDb($attachment->getId());
        }
        foreach ($this->entityManager->getRDBRepository(ArrayValue::ENTITY_TYPE)
            ->where(['entityType' => $entity->getEntityType(), 'entityId' => $entity->getId()])->find() as $value) {
            $this->entityManager->getRDBRepository(ArrayValue::ENTITY_TYPE)->deleteFromDb($value->getId());
        }
        if ($entity->getEntityType() === Note::ENTITY_TYPE) {
            $this->entityManager->getQueryExecutor()->execute(DeleteBuilder::create()->from(UserReaction::ENTITY_TYPE)
                ->where(['parentId' => $entity->getId(), 'parentType' => Note::ENTITY_TYPE])->build());
        }
    }

    /** @param mixed[] $ids @return string[] */
    private function normalizeIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map(
            static fn ($id): string => trim((string) $id), $ids
        ), static fn (string $id): bool => preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $id) === 1)));
        if ($ids === [] || count($ids) > self::MAX_BATCH_SIZE) {
            throw new BadRequest('Select between 1 and 500 accounts.');
        }
        return $ids;
    }

    private function requireTenantAdmin(): void
    {
        if (!$this->user->isAdmin()) {
            throw new Forbidden('Only a tenant administrator can manage deleted accounts.');
        }
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }
}
