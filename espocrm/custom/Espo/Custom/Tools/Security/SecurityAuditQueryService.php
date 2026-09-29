<?php

namespace Espo\Custom\Tools\Security;

use Espo\Core\Exceptions\Forbidden;
use Espo\Core\ORM\EntityManager;
use Espo\Core\Tenant\TenantContextStore;
use Espo\Entities\User;
use PDO;

/** Tenant-admin audit reader that never crosses the active tenant/service boundary. */
final class SecurityAuditQueryService
{
    public function __construct(
        private EntityManager $entityManager,
        private TenantContextStore $tenantContextStore,
        private SecurityAuditService $audit,
        private User $user,
    ) {}

    /** @return array{total:int,list:list<array<string,mixed>>} */
    public function list(int $offset=0,int $limit=100):array
    {
        $this->requireAdmin();$context=$this->tenantContextStore->require();$offset=max(0,$offset);$limit=max(1,min(200,$limit));$pdo=$this->entityManager->getPDO();
        $count=$pdo->prepare('SELECT COUNT(*) FROM nexa_audit_event WHERE tenant_id=? AND service_id=?');$count->execute([$context->tenantId,$context->serviceId]);
        $statement=$pdo->prepare('SELECT id,actor_type,actor_user_id,effective_user_id,action,result,subject_type,subject_id,correlation_id,source,ip_address,metadata_json,occurred_at FROM nexa_audit_event WHERE tenant_id=? AND service_id=? ORDER BY occurred_at DESC,id DESC LIMIT ? OFFSET ?');
        $statement->bindValue(1,$context->tenantId);$statement->bindValue(2,$context->serviceId);$statement->bindValue(3,$limit,PDO::PARAM_INT);$statement->bindValue(4,$offset,PDO::PARAM_INT);$statement->execute();
        $list=[];foreach($statement->fetchAll(PDO::FETCH_ASSOC) as $row){$row['metadata']=json_decode((string)($row['metadata_json']??'{}'),true)?:[];unset($row['metadata_json']);$list[]=$row;}
        return ['total'=>(int)$count->fetchColumn(),'list'=>$list];
    }

    /** @return array{valid:bool,checked:int,failedId:?string} */
    public function verify():array{$this->requireAdmin();return $this->audit->verify($this->tenantContextStore->require());}
    private function requireAdmin():void{if(!$this->user->isAdmin())throw new Forbidden('Only a tenant administrator can review the security audit.');}
}
