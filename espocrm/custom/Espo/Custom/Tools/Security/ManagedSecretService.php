<?php

namespace Espo\Custom\Tools\Security;

use Espo\Core\Exceptions\Forbidden;
use Espo\Core\ORM\EntityManager;
use Espo\Core\Tenant\TenantContextStore;
use Espo\Custom\Tools\Auth\ProviderSecretCipher;
use Espo\Entities\User;
use PDO;
use RuntimeException;

/** Internal secret store. Plaintext is returned only to an explicitly named runtime purpose. */
final class ManagedSecretService
{
    public function __construct(private EntityManager $entityManager,private TenantContextStore $tenantContextStore,private ProviderSecretCipher $cipher,private SecurityAuditService $audit,private User $user){}

    public function put(string $purpose,string $name,string $plaintext):void
    {
        $this->requireAdmin();$context=$this->tenantContextStore->require();$this->validateIdentity($purpose,$name);
        if($plaintext==='')throw new RuntimeException('Secret value is required.');
        $ciphertext=$this->cipher->encryptFor($plaintext,$context->tenantId,$context->serviceId,$purpose.':'.$name);$description=$this->cipher->describe($ciphertext);
        $statement=$this->entityManager->getPDO()->prepare('INSERT INTO nexa_managed_secret (id,tenant_id,service_id,purpose,secret_name,key_id,cipher_version,ciphertext,created_by_id) VALUES (?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE key_id=VALUES(key_id),cipher_version=VALUES(cipher_version),ciphertext=VALUES(ciphertext),rotated_by_id=VALUES(created_by_id),rotated_at=CURRENT_TIMESTAMP(6)');
        $statement->execute([$this->uuid(),$context->tenantId,$context->serviceId,$purpose,$name,$description['keyId'],$description['version'],$ciphertext,$this->user->getId()]);
        $this->audit->append($context,'security.secret.stored','managed-secret',null,'success',['purpose'=>$purpose,'name'=>$name,'keyId'=>$description['keyId']]);
    }

    public function revealForRuntime(string $purpose,string $name):string
    {
        $context=$this->tenantContextStore->require();$this->validateIdentity($purpose,$name);
        $statement=$this->entityManager->getPDO()->prepare('SELECT ciphertext FROM nexa_managed_secret WHERE tenant_id=? AND service_id=? AND purpose=? AND secret_name=? LIMIT 1');
        $statement->execute([$context->tenantId,$context->serviceId,$purpose,$name]);$ciphertext=$statement->fetchColumn();
        if(!is_string($ciphertext))throw new RuntimeException('Managed secret was not found.');
        return $this->cipher->decryptFor($ciphertext,$context->tenantId,$context->serviceId,$purpose.':'.$name);
    }

    public function rotateAll():int
    {
        $this->requireAdmin();$context=$this->tenantContextStore->require();$pdo=$this->entityManager->getPDO();$pdo->beginTransaction();
        try{$statement=$pdo->prepare('SELECT * FROM nexa_managed_secret WHERE tenant_id=? AND service_id=? FOR UPDATE');$statement->execute([$context->tenantId,$context->serviceId]);$rows=$statement->fetchAll(PDO::FETCH_ASSOC);
            $update=$pdo->prepare('UPDATE nexa_managed_secret SET key_id=?,cipher_version=?,ciphertext=?,rotated_by_id=?,rotated_at=CURRENT_TIMESTAMP(6) WHERE id=? AND tenant_id=? AND service_id=?');
            foreach($rows as $row){$scope=$row['purpose'].':'.$row['secret_name'];$plain=$this->cipher->decryptFor($row['ciphertext'],$context->tenantId,$context->serviceId,$scope);$encrypted=$this->cipher->encryptFor($plain,$context->tenantId,$context->serviceId,$scope);$description=$this->cipher->describe($encrypted);$update->execute([$description['keyId'],$description['version'],$encrypted,$this->user->getId(),$row['id'],$context->tenantId,$context->serviceId]);}
            $pdo->commit();$this->audit->append($context,'security.secret.rotated','managed-secret',null,'success',['count'=>count($rows),'keyId'=>$this->cipher->activeKeyId()]);return count($rows);
        }catch(\Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    }

    private function requireAdmin():void{if(!$this->user->isAdmin())throw new Forbidden('Only a tenant administrator can manage secrets.');}
    private function validateIdentity(string $purpose,string $name):void{if(!preg_match('/^[a-z][a-z0-9._-]{1,95}$/',$purpose)||!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{1,127}$/',$name))throw new RuntimeException('Secret purpose or name is invalid.');}
    private function uuid():string{$bytes=random_bytes(16);$bytes[6]=chr((ord($bytes[6])&15)|64);$bytes[8]=chr((ord($bytes[8])&63)|128);return vsprintf('%s%s-%s-%s-%s-%s%s%s',str_split(bin2hex($bytes),4));}
}
