<?php
declare(strict_types=1);
namespace Espo\Custom\Tools\Conversation;

use Espo\Core\Exceptions\{BadRequest, Conflict, NotFound};
use Espo\Core\ORM\EntityManager;
use Espo\Core\Tenant\TenantContextStore;
use Espo\ORM\Entity;
use PDO;

/** Shared bounded persistence for the two independently authorized chat adapters. */
final class ChatStore
{
    public function __construct(private EntityManager $em, private TenantContextStore $contexts) {}
    public function scope(): array { $c = $this->contexts->require(); return [$c->tenantId, $c->serviceId]; }
    public function rows(string $sql, array $args = []): array
    {
        $q = $this->em->getPDO()->prepare($sql); $q->execute($args); return $q->fetchAll(PDO::FETCH_ASSOC);
    }
    public function execute(string $sql, array $args): void { $q = $this->em->getPDO()->prepare($sql); $q->execute($args); }
    public function atomic(callable $action, bool $admission = false): mixed
    {
        $pdo = $this->em->getPDO(); $owns = !$pdo->inTransaction(); $name = 'chat_'.bin2hex(random_bytes(5));
        if ($owns) $pdo->beginTransaction(); else $pdo->exec('SAVEPOINT '.$name);
        try {
            if ($admission) $this->rows('SELECT tenant_id FROM nexa_tenant_service WHERE tenant_id=? AND service_id=? FOR UPDATE', $this->scope());
            $result = $action(); if ($owns) $pdo->commit(); else $pdo->exec('RELEASE SAVEPOINT '.$name); return $result;
        }
        catch (\Throwable $error) {
            if ($owns && $pdo->inTransaction()) $pdo->rollBack();
            elseif (!$owns) { $pdo->exec('ROLLBACK TO SAVEPOINT '.$name); $pdo->exec('RELEASE SAVEPOINT '.$name); }
            throw $error;
        }
    }
    public function lock(string $id): Entity
    {
        if (!preg_match('/^[a-zA-Z0-9]{1,17}$/D', $id)) throw new NotFound('Chat unavailable.');
        $row = $this->rows('SELECT id FROM nexa_chat_conversation WHERE tenant_id=? AND service_id=? AND id=? AND deleted=0 FOR UPDATE', [...$this->scope(), $id]);
        if (!$row) throw new NotFound('Chat unavailable.');
        return $this->em->getEntityById('NexaChatConversation', $id);
    }
    public function append(Entity $root, mixed $nonce, mixed $body, ?string $userId = null, bool $bot = false): array
    {
        if (!$this->em->getPDO()->inTransaction()) throw new \LogicException('Chat messages require an atomic write boundary.');
        $root=$this->lock($root->getId());
        self::uuidValue($nonce);
        if (!is_string($body) || trim($body) === '' || mb_strlen($body) > 4000 || strlen($body) > 16000 || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $body)) throw new BadRequest('Enter a message of up to 4,000 characters.');
        if ($bot && $userId !== null) throw new \LogicException('Bot messages cannot impersonate staff.');
        $body = trim($body); $kind = $bot ? 'bot' : ($userId ? 'staff' : 'visitor');
        $old = $this->rows('SELECT id,body,author_kind,author_user_id FROM nexa_chat_message WHERE tenant_id=? AND service_id=? AND conversation_id=? AND nonce=?', [...$this->scope(), $root->getId(), $nonce])[0] ?? null;
        if ($old) {
            if ($old['body'] !== $body || $old['author_kind'] !== $kind || $old['author_user_id'] !== $userId) throw new Conflict('This message key was already used for different content.');
            return ['id' => (string) $old['id'], 'duplicate' => true];
        }
        $count = $this->rows('SELECT id FROM nexa_chat_message WHERE tenant_id=? AND service_id=? AND conversation_id=? LIMIT 1000', [...$this->scope(), $root->getId()]);
        if (count($count) >= 1000) throw new BadRequest('This conversation has reached its message limit.');
        // Serialize admission per tenant/service, not across unrelated workspaces.
        $this->rows('SELECT tenant_id FROM nexa_tenant_service WHERE tenant_id=? AND service_id=? FOR UPDATE', $this->scope());
        $volume = $this->rows('SELECT id FROM nexa_chat_message WHERE tenant_id=? AND service_id=? AND created_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 HOUR) LIMIT 10000', $this->scope());
        if (count($volume) >= 10000) throw new BadRequest('The workspace chat message limit has been reached. Try again later.');
        $this->execute('INSERT INTO nexa_chat_message (tenant_id,service_id,conversation_id,nonce,author_kind,author_user_id,body,created_at) VALUES (?,?,?,?,?,?,?,UTC_TIMESTAMP())', [...$this->scope(), $root->getId(), $nonce, $kind, $userId, $body]);
        $id = (string) $this->em->getPDO()->lastInsertId();
        $root->set(['status'=>'Open','modifiedAt'=>gmdate('Y-m-d H:i:s'),'modifiedById'=>$userId]); $this->em->saveEntity($root, ['silent' => true]);
        return ['id' => $id, 'duplicate' => false];
    }
    public function history(string $id, mixed $after = '', mixed $before = ''): array
    {
        if (!is_string($after) || !is_string($before) || ($after !== '' && !preg_match('/^[0-9]{1,20}$/D', $after)) || ($before !== '' && !preg_match('/^[0-9]{1,20}$/D', $before)) || ($after !== '' && $before !== '')) throw new BadRequest('Choose a valid message cursor.');
        $ascending = $after !== '';
        $clause = $ascending ? ' AND id>?' : ($before !== '' ? ' AND id<?' : '');
        $args = [...$this->scope(), $id]; if ($after !== '' || $before !== '') $args[] = $after ?: $before;
        $rows = $this->rows('SELECT id,author_kind,body,created_at FROM nexa_chat_message WHERE tenant_id=? AND service_id=? AND conversation_id=?'.$clause.' ORDER BY id '.($ascending ? 'ASC' : 'DESC').' LIMIT 101', $args);
        $more = count($rows) > 100; $rows = array_slice($rows, 0, 100); if (!$ascending) $rows = array_reverse($rows);
        return ['messages' => array_map(fn ($r) => ['id' => (string) $r['id'], 'author' => $r['author_kind'], 'body' => $r['body'], 'createdAt' => $r['created_at']], $rows), 'hasMore' => $more];
    }
    public function handoff(Entity $root): array
    {
        if (!$this->em->getPDO()->inTransaction()) throw new \LogicException('Handoff requires an atomic write boundary.');
        $root = $this->lock($root->getId());
        if (in_array($root->get('botState'), ['waiting','running'],true)) $root->set('botState','handoff');
        if (!$root->get('handoffRequestedAt')) {
            $root->set(['handoffRequestedAt'=>gmdate('Y-m-d H:i:s'),'handoffAnsweredAt'=>null,
                'modifiedAt'=>gmdate('Y-m-d H:i:s'),'modifiedById'=>null]);
            $this->em->saveEntity($root, ['silent'=>true]);
            // Public capabilities are visitors, never the ambient CRM cookie's user.
            $this->execute("INSERT INTO nexa_audit_event (id,tenant_id,service_id,actor_type,actor_user_id,action,subject_type,subject_id,correlation_id,source,metadata_json,occurred_at) VALUES (?,?,?,'visitor',NULL,'chat.handoff_requested','NexaChatConversation',?,?,'website-chat','{}',UTC_TIMESTAMP(6))",
                [self::uuid(), ...$this->scope(), $root->getId(), self::uuid()]);
        }
        return $this->handoffState($root);
    }
    public function handoffState(Entity $root): array
    {
        return ['requestedAt'=>$root->get('handoffRequestedAt'), 'answeredAt'=>$root->get('handoffAnsweredAt'),
            'state'=>$root->get('handoffAnsweredAt')?'answered':($root->get('assignedUserId')?'assigned':($root->get('handoffRequestedAt')?'waiting':'none'))];
    }
    public function answerHandoff(Entity $root, string $userId): void
    {
        if (!$this->em->getPDO()->inTransaction()) throw new \LogicException('Handoff requires an atomic write boundary.');
        $root = $this->lock($root->getId());
        if ($root->get('handoffRequestedAt') && !$root->get('handoffAnsweredAt')) {
            $root->set(['handoffAnsweredAt'=>gmdate('Y-m-d H:i:s'),'modifiedById'=>$userId]);
            $this->em->saveEntity($root, ['silent'=>true]);
        }
    }
    public static function uuid(): string
    {
        $s = bin2hex(random_bytes(16)); return substr($s,0,8).'-'.substr($s,8,4).'-4'.substr($s,13,3).'-a'.substr($s,17,3).'-'.substr($s,20);
    }
    public static function uuidValue(mixed $value): string
    {
        if (!is_string($value) || !preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D', $value)) throw new BadRequest('Provide a valid request identifier.');
        return $value;
    }
}
