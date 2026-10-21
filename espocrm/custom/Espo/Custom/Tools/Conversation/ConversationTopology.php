<?php
declare(strict_types=1);
namespace Espo\Custom\Tools\Conversation;

use Espo\Core\ORM\EntityManager;
use Espo\Core\Tenant\TenantContextStore;
use PDO;

/** Mailbox membership, not a shared subject or tenant identity, defines disclosure. */
final class ConversationTopology
{
    public function __construct(private EntityManager $em, private TenantContextStore $contexts) {}

    public function isShared(string $id): bool
    {
        $c = $this->contexts->require();
        return (bool) $this->rows('SELECT ei.email_id FROM email_inbound_email ei INNER JOIN inbound_email i ON i.id=ei.inbound_email_id AND i.tenant_id=ei.tenant_id AND i.service_id=ei.service_id AND i.deleted=0 INNER JOIN email e ON e.id=ei.email_id AND e.tenant_id=ei.tenant_id AND e.service_id=ei.service_id AND e.deleted=0 WHERE ei.tenant_id=? AND ei.service_id=? AND ei.email_id=? AND ei.deleted=0 LIMIT 1', [$c->tenantId, $c->serviceId, $id]);
    }

    /** @param null|callable(string):bool $canRead */
    public function anchor(string $id, ?callable $canRead = null): ?string
    {
        $c = $this->contexts->require(); $seen = []; $path = []; $anchor = null;
        while ($id !== '') {
            if (isset($seen[$id]) || count($seen) >= 50) return null;
            $seen[$id] = true;
            $row = $this->rows('SELECT id,replied_id,status FROM email WHERE tenant_id=? AND service_id=? AND id=? AND deleted=0', [$c->tenantId, $c->serviceId, $id])[0] ?? null;
            if (!$row) break;
            $shared = $this->isShared($id);
            // Private incoming mail is a boundary, even if an administrator can read it natively.
            if (!$shared && $row['status'] !== 'Sent') break;
            $path[] = $id;
            if ($shared) $anchor = $id;
            if (!$row['replied_id']) break;
            $id = $row['replied_id'];
        }
        if ($anchor && $canRead) {
            foreach ($path as $candidate) {
                if (!$canRead($candidate)) return null;
                if ($candidate === $anchor) break;
            }
        }
        return $anchor;
    }

    /** @return array<int,array<string,mixed>> */
    public function children(string $id): array
    {
        $c = $this->contexts->require();
        return $this->rows("SELECT e.id FROM email e WHERE e.tenant_id=? AND e.service_id=? AND e.deleted=0 AND e.replied_id=? AND (e.status='Sent' OR EXISTS (SELECT 1 FROM email_inbound_email ei INNER JOIN inbound_email i ON i.id=ei.inbound_email_id AND i.tenant_id=ei.tenant_id AND i.service_id=ei.service_id AND i.deleted=0 WHERE ei.email_id=e.id AND ei.tenant_id=e.tenant_id AND ei.service_id=e.service_id AND ei.deleted=0)) ORDER BY e.id LIMIT 201", [$c->tenantId, $c->serviceId, $id]);
    }

    private function rows(string $sql, array $args): array
    {
        $q = $this->em->getPDO()->prepare($sql); $q->execute($args); return $q->fetchAll(PDO::FETCH_ASSOC);
    }
}
