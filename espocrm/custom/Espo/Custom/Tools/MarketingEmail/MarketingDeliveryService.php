<?php

declare(strict_types=1);

namespace Espo\Custom\Tools\MarketingEmail;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\ORM\EntityManager;
use Espo\Core\Tenant\TenantContext;
use Espo\Core\Tenant\TenantContextStore;
use Espo\Custom\Tools\Security\ManagedSecretService;
use Espo\Entities\User;
use PDO;
use stdClass;

/** Tenant delivery readiness without duplicating the native EspoCRM queue. */
final class MarketingDeliveryService
{
    private const SECRET_PURPOSE = 'marketing-delivery';
    private const SECRET_NAME = 'postal-api-key';

    public function __construct(
        private EntityManager $entityManager,
        private TenantContextStore $tenantContextStore,
        private ManagedSecretService $secrets,
        private User $user,
    ) {}

    /** @return array<string, mixed> */
    public function getSettings(): array
    {
        $this->requireAdmin();
        $context = $this->tenantContextStore->require();
        $provider = $this->provider($context);
        $domains = $this->domains($context);
        $readyDomains = count(array_filter($domains, static fn (array $row): bool => $row['ready']));

        return [
            'provider' => $provider,
            'domains' => $domains,
            'readiness' => [
                'providerConfigured' => $provider !== null && $provider['hasCredential'],
                'verifiedDomainCount' => $readyDomains,
                'ready' => $provider !== null && $provider['hasCredential'] && $readyDomains > 0,
            ],
            'callbackPath' => '/api/v1/Nexa/public/marketing-delivery/postal/{callback-token}',
        ];
    }

    /** @return array{providerConfigured:bool,verifiedDomainCount:int,ready:bool} */
    public function readiness(): array
    {
        $context = $this->tenantContextStore->require();
        $provider = $this->provider($context);
        $readyDomains = count(array_filter($this->domains($context), static fn (array $row): bool => $row['ready']));
        $configured = $provider !== null && $provider['hasCredential'];
        return ['providerConfigured'=>$configured,'verifiedDomainCount'=>$readyDomains,'ready'=>$configured && $readyDomains>0];
    }

    /** @return array<string, int|string|null> */
    public function usage(): array
    {
        $this->requireAdmin();
        $context = $this->tenantContextStore->require();
        $query = $this->entityManager->getPDO()->prepare(
            "SELECT DATE_FORMAT(CURRENT_DATE(),'%Y-%m-01') AS month,p.monthly_send_limit," .
            "COALESCE(u.accepted_count,0) AS reserved,COALESCE(u.delivered_count,0) AS delivered,COALESCE(u.failed_count,0) AS failed " .
            "FROM nexa_marketing_delivery_provider p LEFT JOIN nexa_marketing_monthly_usage u " .
            "ON u.tenant_id=p.tenant_id AND u.service_id=p.service_id AND u.usage_month=DATE_FORMAT(CURRENT_DATE(),'%Y-%m-01') " .
            "WHERE p.tenant_id=? AND p.service_id=?"
        );
        $query->execute([$context->tenantId, $context->serviceId]);
        $row = $query->fetch(PDO::FETCH_ASSOC) ?: [];
        $limit = MarketingAllowance::limit($this->entityManager->getPDO(), $context, (int) ($row['monthly_send_limit'] ?? 0));
        $reserved = (int) ($row['reserved'] ?? 0);
        return ['month' => $row['month'] ?? gmdate('Y-m-01'), 'limit' => $limit,
            'reserved' => $reserved, 'remaining' => max(0, $limit - $reserved),
            'delivered' => (int) ($row['delivered'] ?? 0), 'failed' => (int) ($row['failed'] ?? 0)];
    }

    /** @return array<string, mixed> */
    public function saveProvider(stdClass $input): array
    {
        $this->requireAdmin();
        $context = $this->tenantContextStore->require();
        $baseUrl = rtrim(trim((string) ($input->apiBaseUrl ?? '')), '/');
        if (!filter_var($baseUrl, FILTER_VALIDATE_URL) || !str_starts_with(strtolower($baseUrl), 'https://')) {
            throw new BadRequest('Enter the HTTPS URL of the Postal server.');
        }
        $serverName = $this->text($input->serverName ?? null, 128);
        $limit = max(0, min(100_000_000, (int) ($input->monthlySendLimit ?? 0)));
        $apiKey = trim((string) ($input->apiKey ?? ''));
        if ($apiKey !== '') $this->secrets->put(self::SECRET_PURPOSE, self::SECRET_NAME, $apiKey);
        if ($apiKey === '' && !$this->hasCredential($context)) throw new BadRequest('Postal API key is required.');

        $pdo = $this->entityManager->getPDO();
        $existing = $this->provider($context);
        $callbackToken = null;
        if ($existing) {
            $tokenState=$pdo->prepare('SELECT callback_token_hash FROM nexa_marketing_delivery_provider WHERE tenant_id=? AND service_id=?');$tokenState->execute([$context->tenantId,$context->serviceId]);$hasCallback=(string)$tokenState->fetchColumn()!=='';
            if(!$hasCallback)$callbackToken=bin2hex(random_bytes(24));
            $statement = $pdo->prepare("UPDATE nexa_marketing_delivery_provider SET api_base_url=?,server_name=?,monthly_send_limit=?,status='configured',callback_token_hash=COALESCE(callback_token_hash,?),callback_token_prefix=COALESCE(callback_token_prefix,?),modified_by_id=?,modified_at=NOW(6) WHERE tenant_id=? AND service_id=?");
            $statement->execute([$baseUrl, $serverName, $limit, $callbackToken?hash('sha256',$callbackToken):null, $callbackToken?substr($callbackToken,0,8):null, $this->user->getId(), $context->tenantId, $context->serviceId]);
        } else {
            $callbackToken=bin2hex(random_bytes(24));
            $statement = $pdo->prepare('INSERT INTO nexa_marketing_delivery_provider (id,tenant_id,service_id,provider_key,api_base_url,server_name,credential_name,callback_token_hash,callback_token_prefix,status,monthly_send_limit,created_by_id,modified_by_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');
            $statement->execute([$this->uuid(), $context->tenantId, $context->serviceId, 'postal', $baseUrl, $serverName, self::SECRET_NAME, hash('sha256',$callbackToken),substr($callbackToken,0,8),'configured', $limit, $this->user->getId(), $this->user->getId()]);
        }
        $settings=$this->getSettings();if($callbackToken!==null)$settings['callbackToken']=$callbackToken;return $settings;
    }

    /** @return array<string, mixed> */
    public function rotateCallbackToken(): array
    {
        $this->requireAdmin();
        $context = $this->tenantContextStore->require();
        if ($this->provider($context) === null) {
            throw new BadRequest('Save the Postal provider before creating a callback URL.');
        }

        $token = bin2hex(random_bytes(24));
        $statement = $this->entityManager->getPDO()->prepare(
            'UPDATE nexa_marketing_delivery_provider SET callback_token_hash=?, callback_token_prefix=?, modified_by_id=?, modified_at=NOW(6) WHERE tenant_id=? AND service_id=?'
        );
        $statement->execute([
            hash('sha256', $token),
            substr($token, 0, 8),
            $this->user->getId(),
            $context->tenantId,
            $context->serviceId,
        ]);

        $settings = $this->getSettings();
        $settings['callbackToken'] = $token;

        return $settings;
    }

    /** @return array<string, mixed> */
    public function saveDomain(stdClass $input): array
    {
        $this->requireAdmin();
        $context = $this->tenantContextStore->require();
        $domain = $this->domain($input->domainName ?? '');
        $spfInclude = $this->host($input->spfInclude ?? null);
        $dkimHost = $this->host($input->dkimHost ?? null);
        $dkimValue = $this->text($input->dkimValue ?? null, 8192);
        $returnPathHost = $this->host($input->returnPathHost ?? null);
        $returnPathValue = $this->host($input->returnPathValue ?? null);
        $pdo = $this->entityManager->getPDO();
        $statement = $pdo->prepare('SELECT id FROM nexa_marketing_sending_domain WHERE tenant_id=? AND service_id=? AND domain_name=? LIMIT 1');
        $statement->execute([$context->tenantId, $context->serviceId, $domain]);
        $id = $statement->fetchColumn();
        if ($id) {
            $update = $pdo->prepare("UPDATE nexa_marketing_sending_domain SET spf_include=?,dkim_host=?,dkim_value=?,return_path_host=?,return_path_value=?,ownership_status='pending',spf_status='pending',dkim_status='pending',dmarc_status='pending',return_path_status='pending',modified_by_id=?,modified_at=NOW(6) WHERE id=? AND tenant_id=? AND service_id=?");
            $update->execute([$spfInclude, $dkimHost, $dkimValue, $returnPathHost, $returnPathValue, $this->user->getId(), $id, $context->tenantId, $context->serviceId]);
        } else {
            $id = $this->uuid();
            $insert = $pdo->prepare('INSERT INTO nexa_marketing_sending_domain (id,tenant_id,service_id,domain_name,verification_token,spf_include,dkim_host,dkim_value,return_path_host,return_path_value,created_by_id,modified_by_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
            $insert->execute([$id, $context->tenantId, $context->serviceId, $domain, bin2hex(random_bytes(24)), $spfInclude, $dkimHost, $dkimValue, $returnPathHost, $returnPathValue, $this->user->getId(), $this->user->getId()]);
        }
        return $this->getSettings();
    }

    /** @return array<string, mixed> */
    public function verifyDomain(string $id): array
    {
        $this->requireAdmin();
        $context = $this->tenantContextStore->require();
        $statement = $this->entityManager->getPDO()->prepare('SELECT * FROM nexa_marketing_sending_domain WHERE id=? AND tenant_id=? AND service_id=? LIMIT 1');
        $statement->execute([$id, $context->tenantId, $context->serviceId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new BadRequest('Sending domain was not found.');

        $ownership = $this->txtContains((string) $row['domain_name'], 'nexa-verification=' . $row['verification_token']);
        $spf = $row['spf_include'] && $this->txtContains((string) $row['domain_name'], 'include:' . $row['spf_include']);
        $dkim = $row['dkim_host'] && $row['dkim_value'] && $this->txtContains((string) $row['dkim_host'], $this->normalizeTxt((string) $row['dkim_value']));
        $dmarc = count($this->txtRecords('_dmarc.' . $row['domain_name'])) > 0;
        $returnPath = $row['return_path_host'] && $row['return_path_value'] && $this->cnameMatches((string) $row['return_path_host'], (string) $row['return_path_value']);
        $ready = $ownership && $spf && $dkim && $dmarc && $returnPath;
        $update = $this->entityManager->getPDO()->prepare('UPDATE nexa_marketing_sending_domain SET ownership_status=?,spf_status=?,dkim_status=?,dmarc_status=?,return_path_status=?,last_checked_at=NOW(6),verified_at=IF(?,NOW(6),verified_at),modified_by_id=? WHERE id=? AND tenant_id=? AND service_id=?');
        $update->execute([$ownership?'verified':'missing',$spf?'verified':'missing',$dkim?'verified':'missing',$dmarc?'verified':'missing',$returnPath?'verified':'missing',$ready?1:0,$this->user->getId(),$id,$context->tenantId,$context->serviceId]);
        return $this->getSettings();
    }

    /** @return array<string, mixed>|null */
    private function provider(TenantContext $context): ?array
    {
        $statement = $this->entityManager->getPDO()->prepare('SELECT id,provider_key AS providerKey,api_base_url AS apiBaseUrl,server_name AS serverName,status,monthly_send_limit AS monthlySendLimit,callback_token_prefix AS callbackTokenPrefix,modified_at AS modifiedAt FROM nexa_marketing_delivery_provider WHERE tenant_id=? AND service_id=? LIMIT 1');
        $statement->execute([$context->tenantId, $context->serviceId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        $row['monthlySendLimit'] = (int) $row['monthlySendLimit'];
        $row['hasCredential'] = $this->hasCredential($context);
        return $row;
    }

    /** @return array<int, array<string, mixed>> */
    private function domains(TenantContext $context): array
    {
        $statement = $this->entityManager->getPDO()->prepare('SELECT id,domain_name AS domainName,verification_token AS verificationToken,spf_include AS spfInclude,dkim_host AS dkimHost,dkim_value AS dkimValue,return_path_host AS returnPathHost,return_path_value AS returnPathValue,ownership_status AS ownershipStatus,spf_status AS spfStatus,dkim_status AS dkimStatus,dmarc_status AS dmarcStatus,return_path_status AS returnPathStatus,last_checked_at AS lastCheckedAt,verified_at AS verifiedAt FROM nexa_marketing_sending_domain WHERE tenant_id=? AND service_id=? ORDER BY domain_name');
        $statement->execute([$context->tenantId, $context->serviceId]);
        return array_map(static function (array $row): array { $row['ready'] = $row['ownershipStatus']==='verified' && $row['spfStatus']==='verified' && $row['dkimStatus']==='verified' && $row['dmarcStatus']==='verified' && $row['returnPathStatus']==='verified'; return $row; }, $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    private function hasCredential(TenantContext $context): bool
    {
        $statement = $this->entityManager->getPDO()->prepare('SELECT COUNT(*) FROM nexa_managed_secret WHERE tenant_id=? AND service_id=? AND purpose=? AND secret_name=?');
        $statement->execute([$context->tenantId,$context->serviceId,self::SECRET_PURPOSE,self::SECRET_NAME]);
        return (int) $statement->fetchColumn() === 1;
    }

    /** @return string[] */
    private function txtRecords(string $host): array
    {
        $rows = @dns_get_record($host, DNS_TXT);
        if (!is_array($rows)) return [];
        return array_values(array_filter(array_map(fn (array $row): string => $this->normalizeTxt((string) ($row['txt'] ?? implode('', $row['entries'] ?? []))), $rows)));
    }
    private function txtContains(string $host, string $expected): bool { $expected=$this->normalizeTxt($expected); foreach($this->txtRecords($host) as $record) if(str_contains($record,$expected)) return true; return false; }
    private function cnameMatches(string $host,string $expected):bool{$rows=@dns_get_record($host,DNS_CNAME);if(!is_array($rows))return false;$expected=rtrim(strtolower($expected),'.');foreach($rows as $row)if(rtrim(strtolower((string)($row['target']??'')),'.')===$expected)return true;return false;}
    private function normalizeTxt(string $value): string { return preg_replace('/\s+/', '', trim($value, " \t\n\r\0\x0B\"")) ?? ''; }
    private function domain(mixed $value):string{$value=rtrim(strtolower(trim((string)$value)),'.');if($value===''||strlen($value)>253||!filter_var($value,FILTER_VALIDATE_DOMAIN,FILTER_FLAG_HOSTNAME))throw new BadRequest('Enter a valid sending domain.');return $value;}
    private function host(mixed $value):?string{$value=rtrim(strtolower(trim((string)$value)),'.');if($value==='')return null;if(!preg_match('/^(?=.{1,253}$)(?:[a-z0-9_](?:[a-z0-9_-]{0,61}[a-z0-9_])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/',$value))throw new BadRequest('Enter a valid DNS hostname.');return $value;}
    private function text(mixed $value,int $length):?string{$value=trim((string)$value);return $value===''?null:mb_substr($value,0,$length);}
    private function requireAdmin():void{if(!$this->user->isAdmin())throw new Forbidden('Only tenant administrators can configure marketing delivery.');}
    private function uuid():string{$bytes=random_bytes(16);$bytes[6]=chr((ord($bytes[6])&15)|64);$bytes[8]=chr((ord($bytes[8])&63)|128);return vsprintf('%s%s-%s-%s-%s-%s%s%s',str_split(bin2hex($bytes),4));}
}
