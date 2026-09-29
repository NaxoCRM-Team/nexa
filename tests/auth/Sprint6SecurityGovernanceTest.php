<?php

declare(strict_types=1);

use Espo\Core\Application;
use Espo\Core\InjectableFactory;
use Espo\Custom\Tools\Auth\ProviderSecretCipher;

$root=dirname(__DIR__,2);require $root.'/espocrm/bootstrap.php';
$assert=static function(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);};
$read=static fn(string $path):string=>(string)file_get_contents($root.'/'.$path);

$old=base64_encode(random_bytes(32));$new=base64_encode(random_bytes(32));
putenv('NEXA_AUTH_SECRET_KEY='.$old);putenv('NEXA_SECRET_ACTIVE_KEY_ID=key-2026');putenv('NEXA_SECRET_KEYS='.json_encode(['key-2025'=>$old,'key-2026'=>$new],JSON_THROW_ON_ERROR));
$application=new Application();$cipher=$application->getContainer()->getByClass(InjectableFactory::class)->create(ProviderSecretCipher::class);
$tenantA='30000000-0000-4000-8000-000000000001';$tenantB='30000000-0000-4000-8000-000000000002';$service='20000000-0000-4000-8000-000000000001';
$encrypted=$cipher->encryptFor('top-secret',$tenantA,$service,'mailbox-oauth:refresh');
$assert(str_starts_with($encrypted,'nexa:v1:key-2026:'),'New ciphertext must carry a version and key ID.');
$assert($cipher->decryptFor($encrypted,$tenantA,$service,'mailbox-oauth:refresh')==='top-secret','Scoped ciphertext did not decrypt.');
$denied=false;try{$cipher->decryptFor($encrypted,$tenantB,$service,'mailbox-oauth:refresh');}catch(RuntimeException){$denied=true;}
$assert($denied,'Ciphertext copied to another tenant must be rejected.');
$tampered=substr($encrypted,0,-2).'AA';$denied=false;try{$cipher->decryptFor($tampered,$tenantA,$service,'mailbox-oauth:refresh');}catch(RuntimeException){$denied=true;}
$assert($denied,'Tampered ciphertext must be rejected.');
putenv('NEXA_SECRET_ACTIVE_KEY_ID=key-2025');$oldEnvelope=$cipher->encryptFor('rotate-me',$tenantA,$service,'integration:api');
putenv('NEXA_SECRET_ACTIVE_KEY_ID=key-2026');$plain=$cipher->decryptFor($oldEnvelope,$tenantA,$service,'integration:api');$newEnvelope=$cipher->encryptFor($plain,$tenantA,$service,'integration:api');
$assert($cipher->describe($oldEnvelope)['keyId']==='key-2025'&&$cipher->describe($newEnvelope)['keyId']==='key-2026','Key-ring rotation did not preserve old reads and new writes.');

$migration=$read('database/shared/migrations/0056_add_security_governance.sql');$impersonation=$read('espocrm/custom/Espo/Custom/Tools/Security/ImpersonationService.php');$audit=$read('espocrm/custom/Espo/Custom/Tools/Security/SecurityAuditService.php');$job=$read('espocrm/custom/Espo/Custom/Jobs/ExpireImpersonationSessions.php');$client=$read('espocrm/client/custom/tenant-workspace.js');
foreach(['nexa_managed_secret','nexa_impersonation_session','nexa_impersonation_action_cursor','effective_user_id','event_hash','nexa_audit_event_block_update','nexa_audit_event_block_delete'] as $marker)$assert(str_contains($migration,$marker),"Security migration is missing {$marker}.");
$assert(str_contains($impersonation,'A different platform operator must approve')&&str_contains($impersonation,'NEXA_PLATFORM_OPERATOR_IDS'),'Impersonation must require allowlisted two-person approval.');
$assert(str_contains($impersonation,"target_tenant_id=?")&&str_contains($impersonation,'TenantContext::CRM_SERVICE_ID'),'Impersonation reads must enforce tenant and service ownership.');
$assert(str_contains($audit,"'[REDACTED]'")&&str_contains($audit,'previous_hash'),'Security audit must redact secrets and hash-link events.');
$assert(str_contains($job,'UPDATE auth_token SET is_active=0')&&str_contains($job,'nexa_impersonation_action_cursor'),'Expiry and action correlation job is incomplete.');
$assert(str_contains($client,'nexa-impersonation-banner')&&str_contains($client,'Nexa/security/impersonation/exit'),'Persistent impersonation warning and exit control are missing.');

putenv('NEXA_AUTH_SECRET_KEY');putenv('NEXA_SECRET_ACTIVE_KEY_ID');putenv('NEXA_SECRET_KEYS');
echo "Sprint 06 security governance contracts passed.\n";
