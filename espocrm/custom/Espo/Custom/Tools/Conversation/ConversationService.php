<?php
declare(strict_types=1);
namespace Espo\Custom\Tools\Conversation;

use Espo\Core\Acl;
use Espo\Core\InjectableFactory;
use Espo\Core\Exceptions\{BadRequest, Conflict, Forbidden, NotFound};
use Espo\Core\ORM\EntityManager;
use Espo\Core\Record\{ServiceFactory, UpdateParams};
use Espo\Core\Tenant\TenantContextStore;
use Espo\Custom\Tools\Customer\CustomerFoundationRecorder;
use Espo\Entities\User;
use Espo\ORM\Entity;
use Espo\Tools\Email\InboxService;
use PDO;
use stdClass;

/** Shared email is a view over native messages, not another delivery system. */
final class ConversationService
{
    public function __construct(
        private EntityManager $em, private TenantContextStore $contexts,
        private User $user, private Acl $acl, private ServiceFactory $records,
        private InjectableFactory $factory, private InboxService $inbox,
        private ConversationTopology $topology,
    ) {}

    public function listing(string $query = '', string $before = '', string $status = '', string $owner = '', string $view = ''): array
    {
        $this->access();
        if (mb_strlen($query) > 160 || ($before !== '' && !preg_match('/^[a-zA-Z0-9]{1,24}$/D', $before)) ||
            !in_array($status, ['', 'Open', 'Pending', 'Closed'], true) || !in_array($owner, ['', 'mine', 'unassigned'], true) || !in_array($view, ['', 'unread', 'replied'], true)) {
            throw new BadRequest('Choose valid inbox filters.');
        }
        // SQL discovers candidates only; native record and field ACL governs output/search.
        $rows = $this->all("SELECT e.id FROM email e WHERE e.tenant_id=? AND e.service_id=? AND e.deleted=0 AND e.id<? AND EXISTS (
            SELECT 1 FROM email_inbound_email ei INNER JOIN inbound_email i ON i.id=ei.inbound_email_id
            AND i.tenant_id=ei.tenant_id AND i.service_id=ei.service_id AND i.deleted=0
            WHERE ei.email_id=e.id AND ei.tenant_id=e.tenant_id AND ei.service_id=e.service_id AND ei.deleted=0)
            ORDER BY e.id DESC LIMIT 100", [...$this->scope(), $before ?: str_repeat('z', 24)]);
        $list = [];
        foreach ($rows as $row) {
            try {
                $root = $this->root($row['id']);
                if ($root->getId() !== $row['id']) continue;
                $item = $this->summary($root);
                if ($status !== '' && $item['conversationStatus'] !== $status) continue;
                if ($owner === 'mine' && $item['assignedUserId'] !== $this->user->getId()) continue;
                if ($owner === 'unassigned' && $item['assignedUserId'] !== null) continue;
                if ($query !== '' && mb_stripos($item['name'].' '.$item['fromString'], $query) === false) continue;
                try {
                    $details = $this->thread($item['id']);
                    $item['isUnread'] = $details['conversation']['isUnread'];
                    $item['isReplied'] = $details['conversation']['isReplied'];
                    $item['threadLimitReached'] = false;
                } catch (BadRequest $error) {
                    // One oversized thread must not take down the rest of the inbox.
                    $item['isUnread'] = null; $item['isReplied'] = null; $item['threadLimitReached'] = true;
                }
                if ($view === 'unread' && $item['isUnread'] === false) continue;
                if ($view === 'replied' && $item['isReplied'] === false) continue;
                $list[] = $item;
            } catch (NotFound|Forbidden $error) { continue; }
        }
        return ['list' => $list, 'nextCursor' => count($rows) === 100 ? $rows[99]['id'] : null,
            'canConfigureMailboxes' => $this->user->isAdmin() && $this->acl->checkScope('InboundEmail', 'read')];
    }

    public function thread(string $id): array
    {
        $this->access(); $root = $this->root($id);
        $messages = []; $queue = [$root->getId()]; $visited = [];
        while ($queue) {
            $current = array_shift($queue);
            if (isset($visited[$current])) continue;
            $visited[$current] = true;
            if (count($visited) > 200) throw new BadRequest('This thread exceeds 200 messages. Open it in Email.');
            try { $email = $this->email($current); }
            catch (NotFound|Forbidden $error) { continue; }
            $messages[] = $this->summary($email) + [
                'bodyPlain' => $this->readable('body') ? ($email->get('bodyPlain') ?: strip_tags((string) $email->get('body'))) : '',
                'canReply' => $this->acl->checkScope('Email', 'create') && $this->readable('body'),
                'isSent' => $this->readable('status') && $email->get('status') === 'Sent',
            ];
            foreach ($this->topology->children($current) as $child) $queue[] = $child['id'];
        }
        usort($messages, fn ($a, $b) => strcmp($a['dateSent'] ?? '', $b['dateSent'] ?? '') ?: strcmp($a['id'], $b['id']));
        $ids = array_column($messages, 'id');
        $unread = false; $readRows = [];
        if ($ids && $this->readable('isRead')) {
            $marks = implode(',', array_fill(0, count($ids), '?'));
            $readRows = $this->all("SELECT email_id,is_read FROM email_user WHERE tenant_id=? AND service_id=? AND user_id=? AND deleted=0 AND email_id IN ($marks)", [...$this->scope(), $this->user->getId(), ...$ids]);
            $unread = count(array_filter($readRows, fn ($row) => !(bool) $row['is_read'])) > 0;
        }
        $summary = $this->summary($root) + ['isUnread' => $unread, 'isReplied' => count(array_filter($messages, fn ($message) => $message['isSent'] && $message['id'] !== $root->getId())) > 0];
        return ['conversation' => $summary, 'messages' => $messages, 'context' => $this->context($root), 'canMarkRead' => count($readRows) > 0,
            'canEdit' => $this->acl->checkEntity($root, 'edit') && $this->acl->checkField('Email', 'conversationStatus', 'edit'),
            'canAssign' => $this->acl->checkEntity($root, 'edit') && $this->acl->checkField('Email', 'assignedUser', 'edit')];
    }

    public function update(string $id, stdClass $input): array
    {
        $this->access();
        if (array_diff(array_keys(get_object_vars($input)), ['conversationStatus', 'assignedUserId', 'expectedStatus', 'expectedOwnerId', 'expectedVersion']) ||
            !in_array($input->conversationStatus ?? null, ['Open', 'Pending', 'Closed'], true) ||
            !property_exists($input, 'assignedUserId') || !property_exists($input, 'expectedOwnerId') ||
            !in_array($input->expectedStatus ?? null, ['Open', 'Pending', 'Closed'], true) || !is_int($input->expectedVersion ?? null) || $input->expectedVersion < 0) throw new BadRequest('Choose a conversation status, owner and current version.');
        $root = $this->root($id);
        if ($root->getId() !== $id) throw new Conflict('Conversation grouping changed. Reload the conversation before saving.');
        $tx = $this->em->getTransactionManager();
        $owns = !$this->em->getPDO()->inTransaction();
        $savepoint = 'conversation_'.bin2hex(random_bytes(5));
        if ($owns) $tx->start(); else $this->em->getPDO()->exec('SAVEPOINT '.$savepoint);
        try {
            $this->all('SELECT id FROM email WHERE tenant_id=? AND service_id=? AND id=? AND deleted=0 FOR UPDATE', [...$this->scope(), $root->getId()]);
            $root = $this->root($root->getId());
            if ($root->getId() !== $id) throw new Conflict('Conversation grouping changed. Reload the conversation before saving.');
            if (!$this->acl->checkEntity($root, 'edit') || !$this->acl->checkField('Email', 'conversationStatus', 'edit') ||
                !$this->acl->checkField('Email', 'assignedUser', 'edit')) throw new Forbidden('Conversation edit and assignment permission is required.');
            $before = $this->summary($root);
            if ($before['versionNumber'] !== $input->expectedVersion || $before['conversationStatus'] !== $input->expectedStatus || $before['assignedUserId'] !== $input->expectedOwnerId) {
                throw Conflict::createWithBody('Conversation changed.', json_encode(['message' => 'This conversation changed. The latest version must be loaded before saving.'], JSON_THROW_ON_ERROR));
            }
            if ($input->assignedUserId !== null) {
                if (!is_string($input->assignedUserId)) throw new BadRequest('Choose a workspace staff member.');
                $this->owner($input->assignedUserId);
            }
            $this->records->createForUser('Email', $this->user)->update($root->getId(), (object) [
                'conversationStatus' => $input->conversationStatus, 'assignedUserId' => $input->assignedUserId,
            ], UpdateParams::create()->withVersionNumber($input->expectedVersion));
            $this->factory->createWith(CustomerFoundationRecorder::class, ['user' => $this->user])->conversationRecorded($root->getId(), [
                'previousStatus' => $before['conversationStatus'], 'status' => $input->conversationStatus,
                'previousOwnerId' => $before['assignedUserId'], 'ownerId' => $input->assignedUserId,
            ]);
            if ($owns) $tx->commit(); else $this->em->getPDO()->exec('RELEASE SAVEPOINT '.$savepoint);
        } catch (\Throwable $error) {
            if ($owns && $tx->isStarted()) $tx->rollback();
            elseif (!$owns) { $this->em->getPDO()->exec('ROLLBACK TO SAVEPOINT '.$savepoint); $this->em->getPDO()->exec('RELEASE SAVEPOINT '.$savepoint); }
            throw $error;
        }
        return $this->thread($root->getId());
    }

    public function markRead(string $id, bool $isRead = true): array
    {
        $thread = $this->thread($id);
        if (!$this->readable('isRead')) throw new Forbidden('Read-state access is required.');
        $ids = array_column($thread['messages'], 'id');
        if ($isRead) $this->inbox->markAsReadIdList($ids, $this->user->getId());
        else $this->inbox->markAsNotReadIdList($ids, $this->user->getId());
        return ['success' => true];
    }

    public function owners(string $query): array
    {
        $this->access(); if (mb_strlen($query) > 160) throw new BadRequest('Search is too long.');
        $search = \Espo\Core\Select\SearchParams::fromRaw(['maxSize' => 50, 'orderBy' => 'name', 'order' => 'asc',
            'textFilter' => $query, 'where' => [
                ['type' => 'equals', 'attribute' => 'isActive', 'value' => true],
                ['type' => 'in', 'attribute' => 'type', 'value' => ['admin', 'regular']],
            ]]);
        $list = [];
        foreach ($this->records->createForUser('User', $this->user)->find($search, \Espo\Core\Record\FindParams::create()->withNoTotal())->getCollection() as $owner) {
            try { $owner = $this->owner($owner->getId()); } catch (NotFound|Forbidden $error) { continue; }
            $list[] = ['id' => $owner->getId(), 'name' => $owner->get('name') ?: 'Staff member'];
        }
        return ['list' => $list];
    }

    private function root(string $id): Entity
    {
        $anchor = $this->topology->anchor($id, function (string $candidate): bool {
            try { $this->email($candidate); return true; }
            catch (NotFound|Forbidden $error) { return false; }
        });
        if (!$anchor) throw new NotFound('Shared conversation unavailable.');
        return $this->email($anchor);
    }

    private function email(string $id): Entity
    {
        if (!preg_match('/^[a-zA-Z0-9]{1,24}$/D', $id)) throw new NotFound('Message unavailable.');
        $record = $this->records->createForUser('Email', $this->user)->getEntity($id);
        if (!$record || $record->get('deleted') || [$record->get('tenantId'), $record->get('serviceId')] !== $this->scope() || !$this->acl->checkEntity($record, 'read')) throw new NotFound('Message unavailable.');
        return $record;
    }

    private function owner(string $id): User
    {
        $owner = $this->records->createForUser('User', $this->user)->getEntity($id);
        if (!$owner instanceof User || !$owner->isActive() || !in_array($owner->getType(), ['admin', 'regular'], true) ||
            [$owner->get('tenantId'), $owner->get('serviceId')] !== $this->scope() || !$this->acl->checkEntity($owner, 'read') ||
            !$this->acl->checkField('User', 'name', 'read')) throw new NotFound('Staff member unavailable.');
        return $owner;
    }

    private function summary(Entity $email): array
    {
        $ownerId = $email->get('assignedUserId'); $ownerName = null;
        if ($ownerId) { try { $ownerName = $this->owner($ownerId)->get('name'); } catch (NotFound|Forbidden $error) {} }
        return ['id' => $email->getId(), 'name' => (string) $email->get('name'),
            'fromString' => $this->readable('from') && $this->readable('fromString') ? (string) $email->get('fromString') : '',
            'dateSent' => $this->readable('dateSent') ? $email->get('dateSent') : null,
            'conversationStatus' => $email->get('conversationStatus') ?: 'Open',
            'assignedUserId' => $ownerId, 'assignedUserName' => $ownerName,
            'versionNumber' => (int) ($email->get('versionNumber') ?? 0)];
    }

    private function context(Entity $email): ?array
    {
        if (!$this->readable('parent')) return null;
        $type = $email->get('parentType'); $id = $email->get('parentId');
        if (!in_array($type, ['Contact', 'Account', 'Lead', 'Opportunity', 'Case'], true) || !$id || !$this->acl->checkScope($type, 'read') || !$this->acl->checkField($type, 'name', 'read')) return null;
        if (in_array($type, ['Contact', 'Lead'], true) && (!$this->acl->checkField($type, 'firstName', 'read') || !$this->acl->checkField($type, 'lastName', 'read'))) return null;
        try { $record = $this->records->createForUser($type, $this->user)->getEntity($id); }
        catch (NotFound|Forbidden $error) { return null; }
        if (!$record || $record->get('deleted') || [$record->get('tenantId'), $record->get('serviceId')] !== $this->scope() || !$this->acl->checkEntity($record, 'read')) return null;
        return ['id' => $id, 'type' => $type, 'name' => (string) $record->get('name')];
    }

    private function access(): void
    {
        if (!$this->user->isActive() || !in_array($this->user->getType(), ['admin', 'regular'], true) ||
            [$this->user->get('tenantId'), $this->user->get('serviceId')] !== $this->scope() || !$this->acl->checkScope('Email', 'read')) throw new Forbidden('Workspace email access is required.');
        foreach (['name', 'conversationStatus', 'assignedUser', 'replied'] as $field) {
            if (!$this->readable($field)) throw new Forbidden('Conversation property access is required.');
        }
    }
    private function readable(string $field): bool { return $this->acl->checkField('Email', $field, 'read'); }
    private function scope(): array { $c = $this->contexts->require(); return [$c->tenantId, $c->serviceId]; }
    private function all(string $sql, array $args): array
    {
        $q = $this->em->getPDO()->prepare($sql); $q->execute($args); return $q->fetchAll(PDO::FETCH_ASSOC);
    }
}
