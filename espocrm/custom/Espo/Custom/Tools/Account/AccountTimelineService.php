<?php

namespace Espo\Custom\Tools\Account;

use Espo\Core\Acl;
use Espo\Core\Acl\Table;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Record\ReadParams;
use Espo\Core\Record\ServiceContainer;
use Espo\Core\Select\SearchParams;
use stdClass;

/**
 * Builds one ACL-scoped company timeline from Account and associated Contact records.
 *
 * Record Service remains the only data boundary here. It applies tenant, service,
 * row and field permissions before the timeline is merged and returned.
 */
final class AccountTimelineService
{
    private const PAGE_SIZE_DEFAULT = 25;
    private const PAGE_SIZE_MAX = 100;
    private const CONTACT_PAGE_SIZE = 200;
    private const CONTACT_LIMIT = 5000;

    /** @var array<string, string> */
    private const TAB_ENTITY_MAP = [
        'notes' => 'Note',
        'tasks' => 'Task',
        'meetings' => 'Meeting',
        'calls' => 'Call',
        'emails' => 'Email',
    ];

    /** @var array<string, string[]> */
    private const SELECT_MAP = [
        'Note' => ['id', 'type', 'post', 'parentType', 'parentId', 'createdAt', 'createdById', 'createdByName', 'isPinned'],
        'Task' => ['id', 'name', 'status', 'priority', 'dateEnd', 'parentType', 'parentId', 'assignedUserId', 'assignedUserName', 'createdAt', 'description', 'isPinned'],
        'Meeting' => ['id', 'name', 'status', 'dateStart', 'dateEnd', 'parentType', 'parentId', 'contactsIds', 'contactsNames', 'assignedUserId', 'assignedUserName', 'createdAt', 'description', 'isPinned'],
        'Call' => ['id', 'name', 'status', 'direction', 'dateStart', 'dateEnd', 'parentType', 'parentId', 'contactsIds', 'contactsNames', 'assignedUserId', 'assignedUserName', 'createdAt', 'description', 'isPinned'],
        'Email' => ['id', 'name', 'subject', 'status', 'dateSent', 'sendAt', 'createdAt', 'fromString', 'fromName', 'to', 'cc', 'parentType', 'parentId', 'assignedUserId', 'assignedUserName', 'isPinned'],
    ];

    public function __construct(
        private ServiceContainer $recordServiceContainer,
        private Acl $acl,
    ) {}

    /** @return array{list: stdClass[], hasMore: bool, nextOffset: int, limit: int} */
    public function getPage(string $accountId, string $tab, int $offset, int $limit): array
    {
        $tab = strtolower(trim($tab));
        if ($tab !== 'activity' && !isset(self::TAB_ENTITY_MAP[$tab])) {
            throw new BadRequest('Unknown account timeline tab.');
        }
        if (!preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $accountId)) {
            throw new BadRequest('Invalid account identifier.');
        }

        $offset = max(0, $offset);
        $limit = min(self::PAGE_SIZE_MAX, max(1, $limit ?: self::PAGE_SIZE_DEFAULT));

        // Reading through Record Service proves the Account is visible to this request.
        $this->recordServiceContainer->get('Account')->read($accountId, ReadParams::create());
        $contactIds = $this->getContactIds($accountId);
        $entityTypes = $tab === 'activity' ? array_values(self::TAB_ENTITY_MAP) : [self::TAB_ENTITY_MAP[$tab]];
        $fetchSize = $offset + $limit + 1;
        $records = [];
        $sourceHasMore = false;

        foreach ($entityTypes as $entityType) {
            if (!$this->acl->check($entityType, Table::ACTION_READ)) {
                continue;
            }

            $collection = $this->recordServiceContainer->get($entityType)->find(
                SearchParams::fromRaw([
                    'select' => self::SELECT_MAP[$entityType],
                    'where' => $this->whereFor($entityType, $accountId, $contactIds),
                    'orderBy' => $this->dateField($entityType),
                    'order' => SearchParams::ORDER_DESC,
                    'maxSize' => $fetchSize,
                    'offset' => 0,
                ])
            );

            $total = $collection->getTotal();
            $sourceHasMore = $sourceHasMore || ($total !== null && $total > $fetchSize);
            foreach ($collection->getValueMapList() as $record) {
                $record->_entityType = $entityType;
                $records[] = $record;
            }
        }

        usort($records, fn (stdClass $left, stdClass $right): int =>
            strcmp($this->timestamp($right), $this->timestamp($left))
        );

        $page = array_slice($records, $offset, $limit);
        $hasMore = $sourceHasMore || count($records) > $offset + $limit;

        return [
            'list' => array_values($page),
            'hasMore' => $hasMore,
            'nextOffset' => $offset + count($page),
            'limit' => $limit,
        ];
    }

    /** @return string[] */
    private function getContactIds(string $accountId): array
    {
        if (!$this->acl->check('Contact', Table::ACTION_READ)) {
            return [];
        }

        $accountService = $this->recordServiceContainer->get('Account');
        $ids = [];
        foreach (['contacts', 'contactsPrimary'] as $link) {
            $offset = 0;
            do {
                $collection = $accountService->findLinked($accountId, $link, SearchParams::fromRaw([
                    'select' => ['id'],
                    'maxSize' => self::CONTACT_PAGE_SIZE,
                    'offset' => $offset,
                ]));
                $page = $collection->getValueMapList();
                foreach ($page as $contact) {
                    if (isset($contact->id)) {
                        $ids[(string) $contact->id] = true;
                    }
                }
                $offset += count($page);
                $total = $collection->getTotal();
            } while ($page !== [] && $offset < self::CONTACT_LIMIT && ($total === null || $offset < $total));
        }

        return array_keys($ids);
    }

    /** @param string[] $contactIds @return array<int, array<string, mixed>> */
    private function whereFor(string $entityType, string $accountId, array $contactIds): array
    {
        $parents = [[
            'type' => 'and',
            'value' => [
                ['type' => 'equals', 'attribute' => 'parentType', 'value' => 'Account'],
                ['type' => 'equals', 'attribute' => 'parentId', 'value' => $accountId],
            ],
        ]];

        if ($contactIds !== []) {
            $parents[] = [
                'type' => 'and',
                'value' => [
                    ['type' => 'equals', 'attribute' => 'parentType', 'value' => 'Contact'],
                    ['type' => 'in', 'attribute' => 'parentId', 'value' => $contactIds],
                ],
            ];
        }

        if (in_array($entityType, ['Meeting', 'Call'], true) && $contactIds !== []) {
            $parents[] = ['type' => 'linkedWith', 'attribute' => 'contacts', 'value' => $contactIds];
        }

        $where = [['type' => 'or', 'value' => $parents]];
        if ($entityType === 'Note') {
            array_unshift($where, ['type' => 'equals', 'attribute' => 'type', 'value' => 'Post']);
        }

        return $where;
    }

    private function dateField(string $entityType): string
    {
        return match ($entityType) {
            'Task' => 'dateEnd',
            'Meeting', 'Call' => 'dateStart',
            'Email' => 'dateSent',
            default => 'createdAt',
        };
    }

    private function timestamp(stdClass $record): string
    {
        foreach (['dateStart', 'dateSent', 'sendAt', 'dateEnd', 'createdAt'] as $field) {
            if (isset($record->$field) && $record->$field !== '') {
                return (string) $record->$field;
            }
        }
        return '';
    }
}
