<?php

namespace Espo\Custom\Tools\Security;

use Espo\Core\ORM\EntityManager;
use Espo\Core\Tenant\TenantContext;
use Espo\Entities\User;
use PDO;

/** Writes tenant-scoped, hash-linked security events with sensitive values redacted. */
final class SecurityAuditService
{
    private const REDACTED = '[REDACTED]';

    public function __construct(private EntityManager $entityManager, private User $user) {}

    /** @param array<string,mixed> $metadata */
    public function append(
        TenantContext $context,
        string $action,
        string $subjectType,
        ?string $subjectId,
        string $result = 'success',
        array $metadata = [],
        ?string $actorUserId = null,
        ?string $effectiveUserId = null,
        ?string $correlationId = null,
    ): string {
        $pdo = $this->entityManager->getPDO();
        $id = $this->uuid();
        $correlationId ??= $this->uuid();
        $actorUserId ??= $this->user->getId() ?: null;
        $metadataJson = json_encode($this->redact($metadata), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $lockName = 'nexa-audit:' . hash('sha256', $context->tenantId . ':' . $context->serviceId);
        $lock = $pdo->prepare('SELECT GET_LOCK(?,5)');
        $lock->execute([$lockName]);
        if ((int) $lock->fetchColumn() !== 1) {
            throw new \RuntimeException('The security audit ledger is busy.');
        }
        try {
            $previous = $this->previousHash($pdo, $context);
            $occurredAt = (new \DateTimeImmutable())->format('Y-m-d H:i:s.u');
            $hashData = implode("\0", [$previous ?? '',$id,$context->tenantId,$context->serviceId,$actorUserId ?? '',$effectiveUserId ?? '',$action,$subjectType,$subjectId ?? '',$result,$correlationId,$occurredAt,$metadataJson]);
            $eventHash = hash('sha256', $hashData);
            $statement = $pdo->prepare(
                'INSERT INTO nexa_audit_event (id,tenant_id,service_id,actor_type,actor_user_id,effective_user_id,action,result,subject_type,subject_id,correlation_id,source,ip_address,user_agent,metadata_json,previous_hash,event_hash,occurred_at) '
                . "VALUES (?,?,?,'user',?,?,?,?,?,?,?,'security-governance',?,?,?,?,?,?)"
            );
            $statement->execute([$id,$context->tenantId,$context->serviceId,$actorUserId,$effectiveUserId,$action,$result,$subjectType,$subjectId,$correlationId,$this->clientIp(),substr((string)($_SERVER['HTTP_USER_AGENT']??''),0,512),$metadataJson,$previous,$eventHash,$occurredAt]);
        } finally {
            $release = $pdo->prepare('SELECT RELEASE_LOCK(?)');
            $release->execute([$lockName]);
        }

        return $id;
    }

    /** @return array{valid:bool,checked:int,failedId:?string} */
    public function verify(TenantContext $context): array
    {
        $statement=$this->entityManager->getPDO()->prepare('SELECT * FROM nexa_audit_event WHERE tenant_id=? AND service_id=? AND event_hash IS NOT NULL ORDER BY occurred_at,id');
        $statement->execute([$context->tenantId,$context->serviceId]);
        $previous=null;$checked=0;
        foreach($statement->fetchAll(PDO::FETCH_ASSOC) as $row){
            if(($row['previous_hash']?:null)!==$previous)return ['valid'=>false,'checked'=>$checked,'failedId'=>(string)$row['id']];
            $hashData=implode("\0",[$previous??'',$row['id'],$row['tenant_id'],$row['service_id']??'',$row['actor_user_id']??'',$row['effective_user_id']??'',$row['action'],$row['subject_type']??'',$row['subject_id']??'',$row['result'],$row['correlation_id']??'',$row['occurred_at'],$row['metadata_json']??'[]']);
            if(!hash_equals((string)$row['event_hash'],hash('sha256',$hashData)))return ['valid'=>false,'checked'=>$checked,'failedId'=>(string)$row['id']];
            $previous=(string)$row['event_hash'];$checked++;
        }
        return ['valid'=>true,'checked'=>$checked,'failedId'=>null];
    }

    private function previousHash(PDO $pdo,TenantContext $context):?string
    {
        $statement=$pdo->prepare('SELECT event_hash FROM nexa_audit_event WHERE tenant_id=? AND service_id=? AND event_hash IS NOT NULL ORDER BY occurred_at DESC,id DESC LIMIT 1');
        $statement->execute([$context->tenantId,$context->serviceId]);$value=$statement->fetchColumn();
        return is_string($value)&&$value!==''?$value:null;
    }

    private function clientIp():?string{$value=trim((string)($_SERVER['REMOTE_ADDR']??''));return $value!==''?substr($value,0,64):null;}

    private function redact(mixed $value,string $key=''):mixed
    {
        if(preg_match('/pass(word)?|secret|token|authorization|cookie|api[_-]?key|private[_-]?key/i',$key))return self::REDACTED;
        if(!is_array($value))return $value;
        $clean=[];foreach($value as $itemKey=>$item)$clean[$itemKey]=$this->redact($item,(string)$itemKey);return $clean;
    }

    private function uuid():string{$bytes=random_bytes(16);$bytes[6]=chr((ord($bytes[6])&15)|64);$bytes[8]=chr((ord($bytes[8])&63)|128);return vsprintf('%s%s-%s-%s-%s-%s%s%s',str_split(bin2hex($bytes),4));}
}
