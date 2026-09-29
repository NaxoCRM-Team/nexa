<?php

namespace Espo\Custom\Jobs;

use Espo\Core\Job\JobDataLess;
use Espo\Core\Tenant\TenantContextStore;
use Espo\Custom\Tools\Security\SecurityAuditService;
use Espo\ORM\EntityManager;
use PDO;

/** Revokes expired tokens and correlates native CRUD history for the active tenant. */
final class ExpireImpersonationSessions implements JobDataLess
{
    public function __construct(
        private EntityManager $entityManager,
        private TenantContextStore $tenantContextStore,
        private SecurityAuditService $audit,
    ) {}

    public function run(): void
    {
        $context = $this->tenantContextStore->require();
        $this->captureActions($context->tenantId, $context->serviceId);
        $pdo = $this->entityManager->getPDO();
        $statement = $pdo->prepare(
            "SELECT id,operator_user_id,target_user_id,impersonation_auth_token_id,correlation_id " .
            "FROM nexa_impersonation_session WHERE target_tenant_id=? AND service_id=? " .
            "AND status='active' AND expires_at<=CURRENT_TIMESTAMP(6)"
        );
        $statement->execute([$context->tenantId, $context->serviceId]);

        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $session) {
            $pdo->beginTransaction();
            try {
                $pdo->prepare('UPDATE auth_token SET is_active=0 WHERE id=?')->execute([$session['impersonation_auth_token_id']]);
                $pdo->prepare("UPDATE nexa_impersonation_session SET status='expired',ended_at=CURRENT_TIMESTAMP(6) WHERE id=? AND status='active'")->execute([$session['id']]);
                $pdo->commit();
                $this->audit->append($context,'security.impersonation.expired','impersonation-session',(string)$session['id'],'success',[],(string)$session['operator_user_id'],(string)$session['target_user_id'],(string)$session['correlation_id']);
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }
        }
    }

    private function captureActions(string $tenantId, string $serviceId): void
    {
        $pdo=$this->entityManager->getPDO();
        $statement=$pdo->prepare("SELECT h.id,h.action,h.target_type,h.target_id,h.ip_address,s.id session_id,s.operator_user_id,s.target_user_id,s.correlation_id FROM action_history_record h JOIN nexa_impersonation_session s ON s.impersonation_auth_token_id=h.auth_token_id AND s.target_tenant_id=h.tenant_id AND s.service_id=h.service_id LEFT JOIN nexa_impersonation_action_cursor c ON c.action_history_id=h.id WHERE h.tenant_id=? AND h.service_id=? AND c.action_history_id IS NULL AND h.created_at>=s.started_at");
        $statement->execute([$tenantId,$serviceId]);
        $claim=$pdo->prepare('INSERT IGNORE INTO nexa_impersonation_action_cursor (action_history_id,session_id,tenant_id,service_id) VALUES (?,?,?,?)');
        $context=$this->tenantContextStore->require();
        foreach($statement->fetchAll(PDO::FETCH_ASSOC) as $row){$claim->execute([$row['id'],$row['session_id'],$tenantId,$serviceId]);if($claim->rowCount()!==1)continue;$this->audit->append($context,'security.impersonation.'.(string)$row['action'],(string)($row['target_type']?:'record'),$row['target_id']?:null,'success',['actionHistoryId'=>$row['id'],'ipAddress'=>$row['ip_address']],(string)$row['operator_user_id'],(string)$row['target_user_id'],(string)$row['correlation_id']);}
    }
}
