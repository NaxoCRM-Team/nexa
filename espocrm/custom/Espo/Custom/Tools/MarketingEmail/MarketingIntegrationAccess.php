<?php
declare(strict_types=1);
namespace Espo\Custom\Tools\MarketingEmail;

use Espo\Core\Acl;
use Espo\Core\Exceptions\{BadRequest,Forbidden};
use Espo\Core\ORM\EntityManager;
use Espo\Core\Tenant\TenantContextStore;
use Espo\Entities\User;
use PDO;

/** Grants never confer native edit or approval rights. */
final class MarketingIntegrationAccess
{
    public function __construct(private EntityManager $em, private TenantContextStore $contexts, private User $actor, private Acl $acl, private MarketingContentIntegrity $contentIntegrity) {}

    public function grant(string $userId, string $sourceId, int $version, int $hours): void
    {
        $context = $this->contexts->require();
        if (!$this->actor->isAdmin() || !$this->acl->checkScope('MassEmail','edit')) throw new Forbidden('Only a tenant administrator can grant integration sends.');
        if ($hours < 1 || $hours > 720 || $version < 1) throw new BadRequest('Choose a valid version and an expiry from 1 to 720 hours.');
        $user = $this->em->getEntityById('User',$userId);
        if (!$user instanceof User || !$user->isApi() || !$user->get('isActive') || $user->get('tenantId') !== $context->tenantId || $user->get('serviceId') !== $context->serviceId) throw new BadRequest('Choose an active API user in this workspace.');
        $query = $this->em->getPDO()->prepare('INSERT INTO nexa_marketing_integration_grant (tenant_id,service_id,user_id,source_profile_id,source_version,expires_at,granted_by_id) VALUES (?,?,?,?,?,DATE_ADD(UTC_TIMESTAMP(),INTERVAL ? HOUR),?) ON DUPLICATE KEY UPDATE expires_at=VALUES(expires_at),revoked_at=NULL,granted_by_id=VALUES(granted_by_id),modified_at=NOW(6)');
        $this->mutation(function () use ($query,$context,$userId,$sourceId,$version,$hours): void {
            // Lock the source before the grant: revisions and authorization use one ordering.
            $source = $this->em->getPDO()->prepare("SELECT p.*,m.email_template_id FROM nexa_marketing_email_profile p INNER JOIN mass_email m ON m.id=p.mass_email_id AND m.tenant_id=p.tenant_id AND m.service_id=p.service_id WHERE p.id=? AND p.tenant_id=? AND p.service_id=? AND p.version_number=? AND p.lifecycle_status='approved' AND m.deleted=0 FOR UPDATE");
            $source->execute([$sourceId,$context->tenantId,$context->serviceId,$version]);
            $profile = $source->fetch(PDO::FETCH_ASSOC);
            $native = $profile ? $this->em->getEntityById('MassEmail',$profile['mass_email_id']) : null;
            $template = $profile ? $this->em->getEntityById('EmailTemplate',$profile['email_template_id']) : null;
            if (!$native instanceof \Espo\Modules\Crm\Entities\MassEmail || !$template instanceof \Espo\Entities\EmailTemplate || !$this->acl->checkEntity($native,'edit')) throw new BadRequest('Choose the current approved email version.');
            $this->contentIntegrity->requireSnapshot($profile,$template,$native);
            $activeUser = $this->em->getPDO()->prepare("SELECT id FROM user WHERE id=? AND tenant_id=? AND service_id=? AND deleted=0 AND type='api' AND is_active=1 FOR UPDATE");
            $activeUser->execute([$userId,$context->tenantId,$context->serviceId]);
            if (!$activeUser->fetchColumn()) throw new BadRequest('Choose an active API user in this workspace.');
            $query->execute([$context->tenantId,$context->serviceId,$userId,$sourceId,$version,$hours,$this->actor->getId()]);
            $this->audit($sourceId,$version,'integration_granted',['userId'=>$userId,'hours'=>$hours]);
        });
    }

    public function revoke(string $userId, string $sourceId, int $version): void
    {
        if (!$this->actor->isAdmin() || !$this->acl->checkScope('MassEmail','edit')) throw new Forbidden('Only a tenant administrator can revoke integration sends.');
        $context = $this->contexts->require();
        $this->mutation(function () use ($context,$userId,$sourceId,$version): void {
            $this->requireSource($sourceId,true);
            $query = $this->em->getPDO()->prepare('UPDATE nexa_marketing_integration_grant SET revoked_at=UTC_TIMESTAMP(),modified_at=NOW(6) WHERE tenant_id=? AND service_id=? AND user_id=? AND source_profile_id=? AND source_version=? AND revoked_at IS NULL');
            $query->execute([$context->tenantId,$context->serviceId,$userId,$sourceId,$version]);
            if ($query->rowCount()) $this->audit($sourceId,$version,'integration_revoked',['userId'=>$userId]);
        });
    }

    public function catalog(string $sourceId): array
    {
        $this->requireSource($sourceId); $context = $this->contexts->require();
        $query = $this->em->getPDO()->prepare('SELECT g.user_id AS userId,u.user_name AS userName,g.source_version AS sourceVersion,g.expires_at AS expiresAt,g.revoked_at AS revokedAt,g.granted_by_id AS grantedById FROM nexa_marketing_integration_grant g LEFT JOIN user u ON u.id=g.user_id AND u.tenant_id=g.tenant_id AND u.service_id=g.service_id AND u.deleted=0 WHERE g.tenant_id=? AND g.service_id=? AND g.source_profile_id=? ORDER BY g.modified_at DESC LIMIT 100');
        $query->execute([$context->tenantId,$context->serviceId,$sourceId]); return ['grants'=>$query->fetchAll(PDO::FETCH_ASSOC)];
    }

    private function requireSource(string $sourceId, bool $lock = false): void
    {
        if (!$this->actor->isAdmin() || !$this->acl->checkScope('MassEmail','edit')) throw new Forbidden('Only a tenant administrator can manage integration grants.');
        $context = $this->contexts->require();
        $query = $this->em->getPDO()->prepare('SELECT mass_email_id FROM nexa_marketing_email_profile WHERE id=? AND tenant_id=? AND service_id=?' . ($lock ? ' FOR UPDATE' : ''));
        $query->execute([$sourceId,$context->tenantId,$context->serviceId]); $nativeId = $query->fetchColumn();
        $native = $nativeId ? $this->em->getEntityById('MassEmail',$nativeId) : null;
        if (!$native || !$this->acl->checkEntity($native,'edit')) throw new Forbidden('This email is not available for grant administration.');
    }

    private function audit(string $sourceId, int $version, string $type, array $payload): void
    {
        $context = $this->contexts->require();
        $query = $this->em->getPDO()->prepare('INSERT INTO nexa_marketing_email_event (id,tenant_id,service_id,profile_id,event_type,version_number,payload_json,actor_user_id) VALUES (UUID(),?,?,?,?,?,?,?)');
        $query->execute([$context->tenantId,$context->serviceId,$sourceId,$type,$version,json_encode($payload,JSON_THROW_ON_ERROR),$this->actor->getId()]);
    }

    private function mutation(callable $operation): void
    {
        $pdo = $this->em->getPDO(); $owns = !$pdo->inTransaction();
        if ($owns) $pdo->beginTransaction(); else $pdo->exec('SAVEPOINT nexa_integration_grant');
        try {
            $operation();
            if ($owns) $pdo->commit(); else $pdo->exec('RELEASE SAVEPOINT nexa_integration_grant');
        } catch (\Throwable $error) {
            if ($owns && $pdo->inTransaction()) $pdo->rollBack();
            elseif ($pdo->inTransaction()) {$pdo->exec('ROLLBACK TO SAVEPOINT nexa_integration_grant'); $pdo->exec('RELEASE SAVEPOINT nexa_integration_grant');}
            throw $error;
        }
    }

    public function requireGrant(string $sourceId, int $version): void
    {
        $context = $this->contexts->require();
        if (!$this->actor->isApi() || !$this->actor->get('isActive') || $this->actor->get('tenantId') !== $context->tenantId || $this->actor->get('serviceId') !== $context->serviceId) throw new Forbidden('An active workspace API user is required.');
        foreach (['MassEmail','EmailTemplate','Contact'] as $scope) if (!$this->acl->checkScope($scope,'read')) throw new Forbidden('Native read permissions are required for integration sends.');
        if (!$this->acl->checkField('Contact','emailAddress') || !$this->acl->checkField('EmailTemplate','subject') || !$this->acl->checkField('EmailTemplate','body')) throw new Forbidden('Recipient and template field read permissions are required.');
        $query = $this->em->getPDO()->prepare("SELECT g.user_id FROM nexa_marketing_integration_grant g INNER JOIN user u ON u.id=g.user_id AND u.tenant_id=g.tenant_id AND u.service_id=g.service_id AND u.deleted=0 AND u.type='api' AND u.is_active=1 INNER JOIN nexa_marketing_email_profile p ON p.id=g.source_profile_id AND p.tenant_id=g.tenant_id AND p.service_id=g.service_id AND p.version_number=g.source_version AND p.lifecycle_status='approved' WHERE g.tenant_id=? AND g.service_id=? AND g.user_id=? AND g.source_profile_id=? AND g.source_version=? AND g.revoked_at IS NULL AND g.expires_at>UTC_TIMESTAMP()");
        $query->execute([$context->tenantId,$context->serviceId,$this->actor->getId(),$sourceId,$version]);
        if (!$query->fetch(PDO::FETCH_ASSOC)) throw new Forbidden('This approved version has no active integration grant.');
    }

    public function requireRequestOwner(array $request): void
    {
        $context = $this->contexts->require();
        if (($request['tenant_id'] ?? null) !== $context->tenantId || ($request['service_id'] ?? null) !== $context->serviceId) throw new Forbidden('This request belongs to another workspace.');
        if ($this->actor->isAdmin()) {
            $this->requireSource((string) ($request['source_profile_id'] ?? ''));
            return;
        }
        if (($request['created_by_id'] ?? null) !== $this->actor->getId()) throw new Forbidden('This request key belongs to another caller.');
        $this->requireGrant((string) ($request['source_profile_id'] ?? ''),(int) ($request['source_version'] ?? 0));
    }
}
