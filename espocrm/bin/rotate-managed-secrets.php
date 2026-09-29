<?php

declare(strict_types=1);

use Espo\Core\Application;
use Espo\Core\InjectableFactory;
use Espo\Core\ORM\EntityManager;
use Espo\Core\Tenant\TenantContext;
use Espo\Custom\Tools\Auth\ProviderSecretCipher;
require dirname(__DIR__) . '/bootstrap.php';

function option(array $argv, string $name): string
{
    foreach (array_slice($argv, 1) as $argument) {
        if (str_starts_with($argument, "--{$name}=")) return trim(substr($argument, strlen($name) + 3));
    }
    throw new RuntimeException("Missing --{$name}= value.");
}

function uuid(): string
{
    $bytes=random_bytes(16);$bytes[6]=chr((ord($bytes[6])&15)|64);$bytes[8]=chr((ord($bytes[8])&63)|128);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s',str_split(bin2hex($bytes),4));
}

try {
    $slug=option($argv,'tenant');
    $application=new Application();$container=$application->getContainer();
    $entityManager=$container->getByClass(EntityManager::class);
    $cipher=$container->getByClass(InjectableFactory::class)->create(ProviderSecretCipher::class);
    $pdo=$entityManager->getPDO();
    $tenantQuery=$pdo->prepare("SELECT id FROM nexa_tenant WHERE slug=? AND status='active' LIMIT 1");$tenantQuery->execute([$slug]);$tenantId=$tenantQuery->fetchColumn();
    if(!is_string($tenantId))throw new RuntimeException('Active tenant was not found.');
    $serviceId=TenantContext::CRM_SERVICE_ID;$count=0;$pdo->beginTransaction();
    try {
        $managed=$pdo->prepare('SELECT * FROM nexa_managed_secret WHERE tenant_id=? AND service_id=? FOR UPDATE');$managed->execute([$tenantId,$serviceId]);
        $managedUpdate=$pdo->prepare('UPDATE nexa_managed_secret SET key_id=?,cipher_version=?,ciphertext=?,rotated_at=CURRENT_TIMESTAMP(6) WHERE id=?');
        foreach($managed->fetchAll(PDO::FETCH_ASSOC) as $row){$scope=$row['purpose'].':'.$row['secret_name'];$plain=$cipher->decryptFor($row['ciphertext'],$tenantId,$serviceId,$scope);$encrypted=$cipher->encryptFor($plain,$tenantId,$serviceId,$scope);$description=$cipher->describe($encrypted);$managedUpdate->execute([$description['keyId'],$description['version'],$encrypted,$row['id']]);$count++;}
        $providers=$pdo->prepare('SELECT id,encrypted_client_secret FROM nexa_identity_provider WHERE tenant_id=? AND encrypted_client_secret IS NOT NULL FOR UPDATE');$providers->execute([$tenantId]);
        $providerUpdate=$pdo->prepare('UPDATE nexa_identity_provider SET encrypted_client_secret=?,secret_key_version=1 WHERE id=? AND tenant_id=?');
        foreach($providers->fetchAll(PDO::FETCH_ASSOC) as $row){$purpose='identity-provider:'.$row['id'];$plain=$cipher->decryptFor($row['encrypted_client_secret'],$tenantId,$serviceId,$purpose);$providerUpdate->execute([$cipher->encryptFor($plain,$tenantId,$serviceId,$purpose),$row['id'],$tenantId]);$count++;}
        $mailboxes=$pdo->prepare('SELECT * FROM nexa_mailbox_oauth_token WHERE tenant_id=? AND service_id=? FOR UPDATE');$mailboxes->execute([$tenantId,$serviceId]);
        $mailUpdate=$pdo->prepare('UPDATE nexa_mailbox_oauth_token SET access_token_encrypted=?,refresh_token_encrypted=?,secret_key_id=?,secret_cipher_version=1 WHERE id=? AND tenant_id=? AND service_id=?');
        foreach($mailboxes->fetchAll(PDO::FETCH_ASSOC) as $row){$access=$cipher->decryptFor($row['access_token_encrypted'],$tenantId,$serviceId,'mailbox-oauth:access');$refresh=$cipher->decryptFor($row['refresh_token_encrypted'],$tenantId,$serviceId,'mailbox-oauth:refresh');$accessEncrypted=$cipher->encryptFor($access,$tenantId,$serviceId,'mailbox-oauth:access');$description=$cipher->describe($accessEncrypted);$mailUpdate->execute([$accessEncrypted,$cipher->encryptFor($refresh,$tenantId,$serviceId,'mailbox-oauth:refresh'),$description['keyId'],$row['id'],$tenantId,$serviceId]);$count+=2;}
        $pdo->prepare("INSERT INTO nexa_audit_event (id,tenant_id,service_id,actor_type,action,result,subject_type,source,metadata_json) VALUES (?,?,?,'system','security.secret.rotation.completed','success','tenant','secret-rotation',JSON_OBJECT('records',?,'keyId',?))")->execute([uuid(),$tenantId,$serviceId,$count,$cipher->activeKeyId()]);
        $pdo->commit();
    } catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    fwrite(STDOUT,"Rotated {$count} encrypted values for tenant {$slug} using key {$cipher->activeKeyId()}.".PHP_EOL);
} catch(Throwable $e){fwrite(STDERR,'Secret rotation failed: '.$e->getMessage().PHP_EOL);exit(1);}
