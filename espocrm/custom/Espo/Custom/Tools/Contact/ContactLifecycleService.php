<?php

namespace Espo\Custom\Tools\Contact;

use DateTimeImmutable;
use DateTimeZone;
use Espo\Core\Acl;
use Espo\Core\Acl\Table;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\ORM\EntityManager;
use Espo\Core\ORM\Entity as CoreEntity;
use Espo\Core\Record\DeleteParams;
use Espo\Core\Record\ServiceContainer;
use Espo\Entities\User;
use Espo\Entities\ArrayValue;
use Espo\Entities\Attachment;
use Espo\Entities\Note;
use Espo\Entities\UserReaction;
use Espo\ORM\Defs\Params\RelationParam;
use Espo\ORM\Entity;
use Espo\ORM\Name\Attribute;
use Espo\ORM\Query\DeleteBuilder;
use Espo\ORM\Repository\RDBRepository;

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
            ->select(['id', 'firstName', 'lastName', 'emailAddress', 'deletedAt', 'deletedById'])
            ->from('Contact')
            ->where(['deleted' => true])
            ->order('deletedAt', 'DESC')
            ->limit(500)
            ->withDeleted()
            ->build();
        $collection = $this->entityManager->getRDBRepository('Contact')->clone($query)->find();
        $list = [];
        $userMap = [];

        foreach ($collection as $contact) {
            $deletedById = (string) ($contact->get('deletedById') ?? '');

            if ($deletedById !== '' && !isset($userMap[$deletedById])) {
                $deletedBy = $this->entityManager->getRDBRepository('User')->getById($deletedById);
                $deletedByName = $deletedBy
                    ? trim((string) $deletedBy->get('firstName') . ' ' . (string) $deletedBy->get('lastName'))
                    : '';
                $userMap[$deletedById] = [
                    'id' => $deletedById,
                    'name' => $deletedByName !== '' ? $deletedByName : (string) ($deletedBy?->get('userName') ?? 'Unknown user'),
                ];
            }

            $list[] = [
                'id' => $contact->getId(),
                'name' => trim((string) $contact->get('firstName') . ' ' . (string) $contact->get('lastName')),
                'emailAddress' => $contact->get('emailAddress'),
                'deletedAt' => $contact->get('deletedAt'),
                'deletedById' => $deletedById,
                'deletedByName' => $deletedById !== '' ? ($userMap[$deletedById]['name'] ?? 'Unknown user') : 'Unknown user',
            ];
        }

        return ['list' => $list, 'total' => count($list), 'userList' => array_values($userMap)];
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

    /** @param mixed[] $ids @return array{count: int, ids: string[]} */
    public function purge(array $ids): array
    {
        $this->requireTenantAdmin();
        $ids = $this->normalizeIds($ids);
        $purgedIds = [];
        $repository = $this->entityManager->getRDBRepository('Contact');

        $this->entityManager->getTransactionManager()->run(function () use ($ids, $repository, &$purgedIds): void {
            foreach ($ids as $id) {
                // Loading through the scoped query first prevents an administrator
                // from purging another tenant's record by guessing its identifier.
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

                $this->purgeDeletedEntity($contact);
                $purgedIds[] = $id;
            }
        });

        return ['count' => count($purgedIds), 'ids' => $purgedIds];
    }

    /** Mirrors the native cleanup job so an explicit purge removes dependent CRM data too. */
    private function purgeDeletedEntity(CoreEntity $entity): void
    {
        if (!$entity->get(Attribute::DELETED)) {
            throw new Forbidden('Only previously deleted records can be permanently removed.');
        }

        $repository = $this->entityManager->getRepository($entity->getEntityType());

        if (!$repository instanceof RDBRepository) {
            throw new Forbidden('This record cannot be permanently removed.');
        }

        $repository->deleteFromDb($entity->getId(), true);

        foreach ($entity->getRelationList() as $relation) {
            if ($entity->getRelationType($relation) !== Entity::MANY_MANY) {
                continue;
            }

            $relationName = $entity->getRelationParam($relation, RelationParam::RELATION_NAME);
            $midKeys = $entity->getRelationParam($relation, RelationParam::MID_KEYS) ?? [];
            $midKey = $midKeys[0] ?? null;

            if (!$relationName || !$midKey) {
                continue;
            }

            $where = [$midKey => $entity->getId()];
            foreach ($entity->getRelationParam($relation, RelationParam::CONDITIONS) ?? [] as $key => $value) {
                $where[$key] = $value;
            }

            $relationEntityType = ucfirst($relationName);
            if (!$this->entityManager->hasRepository($relationEntityType)) {
                continue;
            }

            $delete = $this->entityManager->getQueryBuilder()
                ->delete()
                ->from($relationEntityType)
                ->where($where)
                ->build();
            $this->entityManager->getQueryExecutor()->execute($delete);
        }

        $this->purgeRelatedNotes($entity);
        $this->purgeRelatedAttachments($entity);
        $this->purgeArrayValues($entity);

        if ($entity->getEntityType() === Note::ENTITY_TYPE) {
            $delete = DeleteBuilder::create()
                ->from(UserReaction::ENTITY_TYPE)
                ->where(['parentId' => $entity->getId(), 'parentType' => Note::ENTITY_TYPE])
                ->build();
            $this->entityManager->getQueryExecutor()->execute($delete);
        }
    }

    private function purgeRelatedNotes(CoreEntity $entity): void
    {
        $query = $this->entityManager->getQueryBuilder()
            ->select()
            ->from(Note::ENTITY_TYPE)
            ->withDeleted()
            ->build();
        $notes = $this->entityManager->getRDBRepository(Note::ENTITY_TYPE)
            ->clone($query)
            ->where(['OR' => [
                ['relatedType' => $entity->getEntityType(), 'relatedId' => $entity->getId()],
                ['parentType' => $entity->getEntityType(), 'parentId' => $entity->getId()],
            ]])
            ->find();

        foreach ($notes as $note) {
            $this->entityManager->removeEntity($note);
            $note->set(Attribute::DELETED, true);
            $this->purgeDeletedEntity($note);
        }
    }

    private function purgeRelatedAttachments(CoreEntity $entity): void
    {
        $attachments = $this->entityManager->getRDBRepository(Attachment::ENTITY_TYPE)
            ->where(['parentId' => $entity->getId(), 'parentType' => $entity->getEntityType()])
            ->find();

        foreach ($attachments as $attachment) {
            $this->entityManager->removeEntity($attachment);
            $this->entityManager->getRDBRepository(Attachment::ENTITY_TYPE)->deleteFromDb($attachment->getId());
        }
    }

    private function purgeArrayValues(CoreEntity $entity): void
    {
        $values = $this->entityManager->getRDBRepository(ArrayValue::ENTITY_TYPE)
            ->where(['entityType' => $entity->getEntityType(), 'entityId' => $entity->getId()])
            ->find();

        foreach ($values as $value) {
            $this->entityManager->getRDBRepository(ArrayValue::ENTITY_TYPE)->deleteFromDb($value->getId());
        }
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
            throw new Forbidden('Only a tenant administrator can manage deleted contacts.');
        }
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }
}
