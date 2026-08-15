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
use Espo\Core\Record\UpdateParams;
use Espo\Core\Tenant\TenantContextStore;
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
use stdClass;

/** Enforces Contact ownership and recoverability at the authenticated API boundary. */
final class ContactLifecycleService
{
    private const MAX_BATCH_SIZE = 500;

    public function __construct(
        private EntityManager $entityManager,
        private ServiceContainer $recordServiceContainer,
        private Acl $acl,
        private User $user,
        private TenantContextStore $tenantContextStore,
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

    /** @return array{list: array<int, array{id: string, name: string, emailAddress: ?string}>} */
    public function getAssignableUsers(): array
    {
        if (!$this->acl->check('Contact', Table::ACTION_EDIT)) {
            throw new Forbidden('You do not have permission to assign contacts.');
        }

        // TenantQueryProcessor applies the active tenant and service to this
        // query, so platform, portal and other-tenant identities never appear.
        $users = $this->entityManager->getRDBRepository(User::ENTITY_TYPE)
            ->where([User::ATTRIBUTE_IS_ACTIVE => true])
            ->order('firstName', 'ASC')
            ->limit(500)
            ->find();
        $list = [];

        foreach ($users as $candidate) {
            $type = $candidate->getType();
            if (!in_array($type, [null, User::TYPE_REGULAR, User::TYPE_ADMIN], true)) {
                continue;
            }

            $name = trim((string) $candidate->get('firstName') . ' ' . (string) $candidate->get('lastName'));
            $list[] = [
                'id' => $candidate->getId(),
                'name' => $name !== '' ? $name : (string) $candidate->get('userName'),
                'emailAddress' => $candidate->get('emailAddress'),
            ];
        }

        return ['list' => $list];
    }

    /** @param mixed[] $ids @return array{count: int, ids: string[]} */
    public function assign(array $ids, ?string $assignedUserId): array
    {
        if (!$this->acl->check('Contact', Table::ACTION_EDIT)) {
            throw new Forbidden('You do not have permission to assign contacts.');
        }

        $ids = $this->normalizeIds($ids);
        $assignedUserId = trim((string) $assignedUserId) ?: null;

        if ($assignedUserId !== null) {
            $candidate = $this->entityManager->getRDBRepository(User::ENTITY_TYPE)->getById($assignedUserId);
            $type = $candidate?->getType();

            if (
                !$candidate || !$candidate->isActive() ||
                !in_array($type, [null, User::TYPE_REGULAR, User::TYPE_ADMIN], true)
            ) {
                throw new Forbidden('The selected owner is unavailable in this tenant.');
            }
        }

        $updatedIds = [];
        $service = $this->recordServiceContainer->get('Contact');

        $this->entityManager->getTransactionManager()->run(
            function () use ($ids, $assignedUserId, $service, &$updatedIds): void {
                foreach ($ids as $id) {
                    $contact = $this->entityManager->getRDBRepository('Contact')->getById($id);
                    if (!$contact || !$this->acl->check($contact, Table::ACTION_EDIT)) {
                        throw new Forbidden('One or more selected contacts cannot be assigned.');
                    }

                    $data = new stdClass();
                    $data->assignedUserId = $assignedUserId;
                    $service->update($id, $data, UpdateParams::create());
                    $updatedIds[] = $id;
                }
            }
        );

        return ['count' => count($updatedIds), 'ids' => $updatedIds];
    }

    /**
     * Records a channel-specific communication restriction without relying on
     * the browser to enforce it. Each change is retained as compliance history.
     *
     * @param mixed[] $ids
     * @param mixed[] $channels
     * @return array{count: int, ids: string[], status: string, channels: string[]}
     */
    public function setCommunicationPreference(
        array $ids,
        array $channels,
        string $status,
        string $reason,
        ?string $note,
    ): array {
        if (!$this->acl->check('Contact', Table::ACTION_EDIT)) {
            throw new Forbidden('You do not have permission to update contact preferences.');
        }

        $ids = $this->normalizeIds($ids);
        $channels = $this->normalizeCommunicationChannels($channels);
        $status = strtolower(trim($status));
        $reason = strtolower(trim($reason));
        $note = trim((string) $note) ?: null;

        if (!in_array($status, ['blocked', 'allowed'], true)) {
            throw new BadRequest('Select a valid communication preference.');
        }

        $allowedReasons = [
            'contact_request', 'unsubscribed', 'invalid_details',
            'legal_compliance', 'complaint', 'consent_restored',
            'correction', 'other',
        ];
        if (!in_array($reason, $allowedReasons, true)) {
            throw new BadRequest('Select a reason for this change.');
        }
        if ($note !== null && mb_strlen($note) > 1000) {
            throw new BadRequest('The internal note must be 1,000 characters or fewer.');
        }

        $tenant = $this->tenantContextStore->require();
        $service = $this->recordServiceContainer->get('Contact');
        $updatedIds = [];

        $this->entityManager->getTransactionManager()->run(
            function () use ($ids, $channels, $status, $reason, $note, $tenant, $service, &$updatedIds): void {
                foreach ($ids as $id) {
                    $contact = $this->entityManager->getRDBRepository('Contact')->getById($id);
                    if (!$contact || !$this->acl->check($contact, Table::ACTION_EDIT)) {
                        throw new Forbidden('One or more selected contacts cannot be updated.');
                    }

                    $current = array_values(array_filter(explode(',', (string) $contact->get('doNotContactChannels'))));
                    $effectiveChannels = $status === 'blocked'
                        ? $channels
                        : array_values(array_intersect($current, $channels));
                    if ($status === 'allowed' && $effectiveChannels === []) {
                        continue;
                    }
                    $next = $status === 'blocked'
                        ? array_values(array_unique(array_merge($current, $effectiveChannels)))
                        : array_values(array_diff($current, $effectiveChannels));
                    sort($next);

                    $data = new stdClass();

                    // Keep Espo's native delivery guards synchronized so current
                    // email and phone workflows respect the Nexa preference.
                    if (in_array('email', $effectiveChannels, true)) {
                        $data->emailAddressIsOptedOut = in_array('email', $next, true);
                        $data->marketingStatus = in_array('email', $next, true)
                            ? 'Unsubscribed'
                            : 'Non-Marketing';
                    }
                    if (array_intersect(['phone', 'sms', 'whatsapp'], $effectiveChannels)) {
                        $phoneBlocked = (bool) array_intersect(['phone', 'sms', 'whatsapp'], $next);
                        $data->doNotCall = $phoneBlocked;
                        $data->phoneNumberIsOptedOut = $phoneBlocked;
                    }

                    $updatedContact = $service->update($id, $data, UpdateParams::create());

                    // These summary fields are read-only to API clients. The
                    // trusted service persists them after native delivery
                    // guards have passed through the Record Service hooks.
                    $updatedContact->set('doNotContact', $next !== []);
                    $updatedContact->set('doNotContactChannels', $next === [] ? null : implode(',', $next));
                    if ($status === 'blocked') {
                        $updatedContact->set('doNotContactReason', $reason);
                        $updatedContact->set('doNotContactNote', $note);
                    } elseif ($next === []) {
                        $updatedContact->set('doNotContactReason', null);
                        $updatedContact->set('doNotContactNote', null);
                    }
                    $updatedContact->set('doNotContactChangedAt', $this->now());
                    $updatedContact->set('doNotContactChangedById', $this->user->getId());
                    $this->entityManager->saveEntity($updatedContact);
                    $this->recordCommunicationPreference(
                        $tenant->tenantId,
                        $tenant->serviceId,
                        $id,
                        $effectiveChannels,
                        $status,
                        $reason,
                        $note,
                    );
                    $this->recordCommunicationActivity($id, $effectiveChannels, $status, $reason, $note);
                    $updatedIds[] = $id;
                }
            }
        );

        return ['count' => count($updatedIds), 'ids' => $updatedIds, 'status' => $status, 'channels' => $channels];
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

    /** @param mixed[] $channels @return string[] */
    private function normalizeCommunicationChannels(array $channels): array
    {
        $channels = array_values(array_unique(array_map(
            static fn ($channel): string => strtolower(trim((string) $channel)),
            $channels
        )));

        if (in_array('all', $channels, true)) {
            return ['email', 'phone', 'sms', 'whatsapp', 'postal'];
        }

        $allowed = ['email', 'phone', 'sms', 'whatsapp', 'postal'];
        if ($channels === [] || array_diff($channels, $allowed) !== []) {
            throw new BadRequest('Select a valid communication channel.');
        }

        return $channels;
    }

    /** @param string[] $channels */
    private function recordCommunicationPreference(
        string $tenantId,
        string $serviceId,
        string $contactId,
        array $channels,
        string $status,
        string $reason,
        ?string $note,
    ): void {
        $statement = $this->entityManager->getPDO()->prepare(
            'INSERT INTO nexa_communication_preference ' .
            '(id, tenant_id, service_id, contact_id, channel, status, reason, note, source, changed_by_id) ' .
            "VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'bulk_action', ?)"
        );

        foreach ($channels as $channel) {
            $statement->execute([
                $this->uuid(), $tenantId, $serviceId, $contactId, $channel,
                $status, $reason, $note, $this->user->getId(),
            ]);
        }
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    /** @param string[] $channels */
    private function recordCommunicationActivity(
        string $contactId,
        array $channels,
        string $status,
        string $reason,
        ?string $note,
    ): void {
        $reasonLabels = [
            'contact_request' => 'Contact requested no communication',
            'unsubscribed' => 'Unsubscribed',
            'invalid_details' => 'Invalid contact details',
            'legal_compliance' => 'Legal or compliance requirement',
            'complaint' => 'Complaint',
            'consent_restored' => 'Consent restored',
            'correction' => 'Previous restriction was incorrect',
            'other' => 'Other',
        ];
        $channelLabels = array_map(
            static fn (string $channel): string => match ($channel) {
                'sms' => 'SMS',
                'whatsapp' => 'WhatsApp',
                'postal' => 'Postal mail',
                default => ucfirst($channel),
            },
            $channels
        );
        $heading = $status === 'blocked'
            ? 'Communication restriction set'
            : 'Communication restriction removed';
        $parts = [
            '<strong>' . $heading . '</strong>',
            'Channels: ' . implode(', ', $channelLabels),
            'Reason: ' . ($reasonLabels[$reason] ?? ucfirst(str_replace('_', ' ', $reason))),
        ];
        if ($note !== null) {
            $parts[] = 'Internal note: ' . htmlspecialchars($note, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        // The native stream surfaces this in Activities; the preference table
        // remains the immutable compliance audit record.
        $this->entityManager->createEntity(Note::ENTITY_TYPE, [
            'type' => 'Post',
            'post' => implode('<br>', $parts),
            'parentId' => $contactId,
            'parentType' => 'Contact',
        ], ['createdById' => $this->user->getId()]);
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
