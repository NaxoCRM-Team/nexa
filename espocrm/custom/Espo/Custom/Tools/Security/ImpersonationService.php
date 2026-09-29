<?php

namespace Espo\Custom\Tools\Security;

use Espo\Core\Authentication\AuthToken\Data as AuthTokenData;
use Espo\Core\Authentication\AuthToken\Manager as AuthTokenManager;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\ORM\EntityManager;
use Espo\Core\Tenant\PlatformExecutionGateway;
use Espo\Core\Tenant\TenantContext;
use Espo\Core\Tenant\TenantContextStore;
use Espo\Core\Utils\Config;
use Espo\Entities\User;
use PDO;
use RuntimeException;

/** Two-person, time-bounded operator impersonation using native Espo auth tokens. */
final class ImpersonationService
{
    public function __construct(
        private EntityManager $entityManager,
        private TenantContextStore $tenantContextStore,
        private PlatformExecutionGateway $platformGateway,
        private AuthTokenManager $authTokenManager,
        private SecurityAuditService $audit,
        private Config $config,
        private User $user,
    ) {}

    /** @return array{id:string,status:string,correlationId:string} */
    public function request(string $tenantSlug,string $targetUserId,string $reason,int $minutes=30):array
    {
        $this->requireOperator();$operatorContext=$this->tenantContextStore->require();$reason=trim($reason);
        if(mb_strlen($reason)<10||mb_strlen($reason)>1000)throw new BadRequest('Provide an impersonation reason between 10 and 1,000 characters.');
        if($minutes<5||$minutes>60)throw new BadRequest('Impersonation duration must be between 5 and 60 minutes.');
        $target=$this->target($tenantSlug,$targetUserId);$id=$this->uuid();$correlation=$this->uuid();
        $statement=$this->entityManager->getPDO()->prepare("INSERT INTO nexa_impersonation_session (id,operator_tenant_id,operator_user_id,target_tenant_id,service_id,target_user_id,reason,status,expires_at,correlation_id) VALUES (?,?,?,?,?,?,?,'requested',DATE_ADD(CURRENT_TIMESTAMP(6),INTERVAL ? MINUTE),?)");
        $statement->execute([$id,$operatorContext->tenantId,$this->user->getId(),$target['tenant_id'],TenantContext::CRM_SERVICE_ID,$targetUserId,$reason,$minutes,$correlation]);
        $this->audit->append($this->context($target,'impersonation-request'),'security.impersonation.requested','impersonation-session',$id,'success',['reason'=>$reason,'durationMinutes'=>$minutes],$this->user->getId(),null,$correlation);
        return ['id'=>$id,'status'=>'requested','correlationId'=>$correlation];
    }

    public function approve(string $id):void
    {
        $this->requireOperator();$pdo=$this->entityManager->getPDO();$row=$this->session($id);
        if($row['status']!=='requested')throw new BadRequest('Only a requested session can be approved.');
        if(hash_equals((string)$row['operator_user_id'],$this->user->getId()))throw new Forbidden('A different platform operator must approve this request.');
        $pdo->prepare("UPDATE nexa_impersonation_session SET status='approved',approved_by_user_id=?,approved_at=CURRENT_TIMESTAMP(6) WHERE id=? AND status='requested'")->execute([$this->user->getId(),$id]);
        $this->audit->append($this->context($row,'impersonation-approval'),'security.impersonation.approved','impersonation-session',$id,'success',[],$this->user->getId(),null,$row['correlation_id']);
    }

    /** @return array{token:string,userName:string,tenantSlug:string,expiresAt:string} */
    public function start(string $id):array
    {
        $this->requireOperator();$row=$this->session($id);
        if($row['status']!=='approved'||$row['operator_user_id']!==$this->user->getId())throw new Forbidden('This approved impersonation request cannot be started by the current operator.');
        if(new \DateTimeImmutable($row['expires_at'])<=new \DateTimeImmutable())throw new Forbidden('This impersonation approval has expired.');
        $context=$this->context($row,'impersonation-start');
        $target=$this->platformGateway->run('Resolve approved impersonation target',function()use($row){$s=$this->entityManager->getPDO()->prepare('SELECT id,user_name,is_active FROM `user` WHERE id=? AND tenant_id=? AND deleted=0 LIMIT 1');$s->execute([$row['target_user_id'],$row['target_tenant_id']]);return $s->fetch(PDO::FETCH_ASSOC);});
        if(!$target||(int)$target['is_active']!==1)throw new Forbidden('The target user is unavailable.');
        $token=$this->tenantContextStore->runWith($context,fn()=>$this->authTokenManager->create(AuthTokenData::create(['userId'=>$row['target_user_id']])));
        $tokenId=method_exists($token,'getId')?(string)$token->getId():null;if(!$tokenId)throw new RuntimeException('Impersonation token identifier is unavailable.');
        $this->entityManager->getPDO()->prepare("UPDATE nexa_impersonation_session SET status='active',started_at=CURRENT_TIMESTAMP(6),impersonation_auth_token_id=? WHERE id=? AND status='approved'")->execute([$tokenId,$id]);
        $this->audit->append($context,'security.impersonation.started','impersonation-session',$id,'success',[],$row['operator_user_id'],$row['target_user_id'],$row['correlation_id']);
        return ['token'=>$token->getToken(),'userName'=>(string)$target['user_name'],'tenantSlug'=>$context->slug,'expiresAt'=>(string)$row['expires_at']];
    }

    /** @return array{token:string,userName:string,tenantSlug:string} */
    public function exit():array
    {
        $context=$this->tenantContextStore->require();$tokenId=(string)$this->user->get('authTokenId');
        $s=$this->entityManager->getPDO()->prepare("SELECT s.*,t.slug,t.display_name FROM nexa_impersonation_session s JOIN nexa_tenant t ON t.id=s.operator_tenant_id WHERE s.impersonation_auth_token_id=? AND s.target_tenant_id=? AND s.status='active' LIMIT 1");$s->execute([$tokenId,$context->tenantId]);$row=$s->fetch(PDO::FETCH_ASSOC);
        if(!$row)throw new Forbidden('No active impersonation session was found.');
        $operator=$this->platformGateway->run('Restore impersonating operator',function()use($row){$s=$this->entityManager->getPDO()->prepare('SELECT id,user_name,is_active FROM `user` WHERE id=? AND tenant_id=? AND deleted=0 LIMIT 1');$s->execute([$row['operator_user_id'],$row['operator_tenant_id']]);return $s->fetch(PDO::FETCH_ASSOC);});
        if(!$operator||(int)$operator['is_active']!==1)throw new Forbidden('The operator account is unavailable.');
        $operatorContext=new TenantContext($row['operator_tenant_id'],$row['slug'],'impersonation-exit',$row['display_name'],TenantContext::CRM_SERVICE_ID);
        $token=$this->tenantContextStore->runWith($operatorContext,fn()=>$this->authTokenManager->create(AuthTokenData::create(['userId'=>$row['operator_user_id']])));
        $pdo=$this->entityManager->getPDO();$pdo->prepare('UPDATE auth_token SET is_active=0 WHERE id=?')->execute([$tokenId]);$pdo->prepare("UPDATE nexa_impersonation_session SET status='ended',ended_at=CURRENT_TIMESTAMP(6) WHERE id=? AND status='active'")->execute([$row['id']]);
        $this->audit->append($context,'security.impersonation.ended','impersonation-session',$row['id'],'success',[],$row['operator_user_id'],$row['target_user_id'],$row['correlation_id']);
        return ['token'=>$token->getToken(),'userName'=>(string)$operator['user_name'],'tenantSlug'=>(string)$row['slug']];
    }

    /** @return null|array{id:string,operatorName:string,reason:string,expiresAt:string,correlationId:string} */
    public function current():?array
    {
        $tokenId=(string)$this->user->get('authTokenId');if($tokenId==='')return null;
        $s=$this->entityManager->getPDO()->prepare("SELECT s.*,u.name operator_name FROM nexa_impersonation_session s LEFT JOIN `user` u ON u.id=s.operator_user_id WHERE s.impersonation_auth_token_id=? AND s.status='active' AND s.expires_at>CURRENT_TIMESTAMP(6) LIMIT 1");$s->execute([$tokenId]);$row=$s->fetch(PDO::FETCH_ASSOC);if(!$row)return null;
        return ['id'=>$row['id'],'operatorName'=>$row['operator_name']?:'Platform operator','reason'=>$row['reason'],'expiresAt'=>$row['expires_at'],'correlationId'=>$row['correlation_id']];
    }

    private function requireOperator():void
    {
        if(in_array($this->user->getId(),$this->operatorIds(),true))return;
        $context=$this->tenantContextStore->require();
        $this->audit->append($context,'security.impersonation.denied','tenant',$context->tenantId,'failure',['reason'=>'operator_not_allowlisted']);
        throw new Forbidden('Platform operator authorization is required.');
    }
    /** @return list<string> */ private function operatorIds():array{$raw=(string)(getenv('NEXA_PLATFORM_OPERATOR_IDS')?:$this->config->get('nexaPlatformOperatorIds',''));return array_values(array_filter(array_map('trim',explode(',',$raw))));}
    /** @return array<string,string> */ private function target(string $slug,string $userId):array{return $this->platformGateway->run('Resolve impersonation target',function()use($slug,$userId){$s=$this->entityManager->getPDO()->prepare("SELECT t.id tenant_id,t.slug,t.display_name FROM nexa_tenant t JOIN `user` u ON u.tenant_id=t.id AND u.id=? AND u.deleted=0 AND u.is_active=1 WHERE t.slug=? AND t.status='active' LIMIT 1");$s->execute([$userId,$slug]);return $s->fetch(PDO::FETCH_ASSOC)?:throw new BadRequest('Target tenant user was not found.');});}
    /** @return array<string,string> */ private function session(string $id):array{return $this->platformGateway->run('Read impersonation request',function()use($id){$s=$this->entityManager->getPDO()->prepare('SELECT s.*,t.slug,t.display_name FROM nexa_impersonation_session s JOIN nexa_tenant t ON t.id=s.target_tenant_id WHERE s.id=? LIMIT 1');$s->execute([$id]);return $s->fetch(PDO::FETCH_ASSOC)?:throw new BadRequest('Impersonation request was not found.');});}
    /** @param array<string,string> $row */ private function context(array $row,string $source):TenantContext{return new TenantContext($row['target_tenant_id']??$row['tenant_id'],$row['slug'],$source,$row['display_name']??'',TenantContext::CRM_SERVICE_ID);}
    private function uuid():string{$bytes=random_bytes(16);$bytes[6]=chr((ord($bytes[6])&15)|64);$bytes[8]=chr((ord($bytes[8])&63)|128);return vsprintf('%s%s-%s-%s-%s-%s%s%s',str_split(bin2hex($bytes),4));}
}
