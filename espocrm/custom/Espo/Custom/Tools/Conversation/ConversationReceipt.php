<?php
declare(strict_types=1);
namespace Espo\Custom\Tools\Conversation;

use Espo\Core\ORM\EntityManager;
use Espo\Core\Tenant\TenantContextStore;
use Espo\Custom\Tools\Customer\CustomerFoundationRecorder;
use PDO;

/** Complete a trusted native receipt only after the actual mailbox association exists. */
final class ConversationReceipt
{
    public function __construct(private EntityManager $em, private TenantContextStore $contexts, private ConversationTopology $topology, private CustomerFoundationRecorder $recorder) {}

    /** Retry only actual group-mailbox receipts, in a bounded active-tenant batch. */
    public function recover(): void
    {
        $c = $this->contexts->require();
        $q = $this->em->getPDO()->prepare('SELECT e.id FROM email e WHERE e.tenant_id=? AND e.service_id=? AND e.deleted=0 AND e.conversation_import_pending_at IS NOT NULL AND EXISTS (SELECT 1 FROM email_inbound_email ei INNER JOIN inbound_email i ON i.id=ei.inbound_email_id AND i.tenant_id=ei.tenant_id AND i.service_id=ei.service_id AND i.deleted=0 WHERE ei.email_id=e.id AND ei.tenant_id=e.tenant_id AND ei.service_id=e.service_id AND ei.deleted=0) ORDER BY e.conversation_import_pending_at,e.id LIMIT 100');
        $q->execute([$c->tenantId, $c->serviceId]);
        $ids = $q->fetchAll(PDO::FETCH_COLUMN);
        $failed = false;
        foreach ($ids as $id) {
            try {
                $this->process($id);
            } catch (\Throwable $error) {
                // Continue independent receipts; native job failure retains its retry signal.
                $failed = true;
            }
        }
        if ($failed) throw new \RuntimeException('Shared inbox receipt recovery failed; pending receipts remain retryable.');
    }

    public function process(string $id): void
    {
        if (!$this->topology->isShared($id)) return;
        $c = $this->contexts->require(); $tx = $this->em->getTransactionManager();
        $owns = !$this->em->getPDO()->inTransaction(); $savepoint = 'receipt_'.bin2hex(random_bytes(5));
        if ($owns) $tx->start(); else $this->em->getPDO()->exec('SAVEPOINT '.$savepoint);
        try {
            $row = $this->one("SELECT id,conversation_import_pending_at,(status='Archived' AND conversation_import_pending_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 10 MINUTE) AND conversation_import_pending_at<=UTC_TIMESTAMP()) fresh FROM email WHERE tenant_id=? AND service_id=? AND id=? AND deleted=0 FOR UPDATE", [$c->tenantId, $c->serviceId, $id]);
            if ($row && $row['conversation_import_pending_at']) {
                $anchor = $this->topology->anchor($id);
                if ($anchor && $this->topology->isShared($id)) {
                    if ($row['fresh'] && $anchor !== $id) {
                        $locked = $this->one('SELECT id FROM email WHERE tenant_id=? AND service_id=? AND id=? AND deleted=0 FOR UPDATE', [$c->tenantId, $c->serviceId, $anchor]);
                        if ($locked) {
                            $root = $this->em->getEntityById('Email', $anchor);
                            $before = $root->get('conversationStatus') ?: 'Open';
                            $root->set('conversationStatus', 'Open');
                            $this->em->saveEntity($root, ['silent' => true]);
                            $this->recorder->conversationRecorded($anchor, ['operation' => 'incoming_reply', 'messageId' => $id, 'previousStatus' => $before, 'status' => 'Open']);
                        }
                    }
                    // Internal receipt bookkeeping must not rewrite the imported message or its revision.
                    $clear = $this->em->getPDO()->prepare('UPDATE email SET conversation_import_pending_at=NULL WHERE tenant_id=? AND service_id=? AND id=? AND deleted=0');
                    $clear->execute([$c->tenantId, $c->serviceId, $id]);
                }
            }
            if ($owns) $tx->commit(); else $this->em->getPDO()->exec('RELEASE SAVEPOINT '.$savepoint);
        } catch (\Throwable $error) {
            if ($owns && $tx->isStarted()) $tx->rollback();
            elseif (!$owns) { $this->em->getPDO()->exec('ROLLBACK TO SAVEPOINT '.$savepoint); $this->em->getPDO()->exec('RELEASE SAVEPOINT '.$savepoint); }
            throw $error;
        }
    }
    private function one(string $sql, array $args): ?array
    {
        $q = $this->em->getPDO()->prepare($sql); $q->execute($args); return $q->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}
