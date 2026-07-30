<?php

namespace Espo\Custom\Tools\Signup;

use DateTimeImmutable;
use Espo\Core\Authentication\AuthToken\Data as AuthTokenData;
use Espo\Core\Authentication\AuthToken\Manager as AuthTokenManager;
use Espo\Core\Tenant\TenantContext;
use Espo\Core\Tenant\TenantContextStore;
use Espo\Core\Utils\Config;
use Espo\ORM\EntityManager;
use PDO;
use PDOException;
use stdClass;
use Throwable;

/** Owns pending identity proof and atomic workspace provisioning. */
final class SignupService
{
    private const CRM_SERVICE_ID = '20000000-0000-4000-8000-000000000001';

    public function __construct(
        private EntityManager $entityManager,
        private Config $config,
        private SignupValidator $validator,
        private SignupMailer $mailer,
        private AuthTokenManager $authTokenManager,
        private TenantContextStore $tenantContextStore,
    ) {}

    /** @return array<string,mixed> */
    public function startEmail(stdClass $input, string $fingerprint): array
    {
        $data = $this->validator->validateStart($input);
        $pdo = $this->entityManager->getPDO();
        $this->enforceRateLimit($pdo, $fingerprint, 'register', 5);
        $this->assertEmailAvailable($pdo, $data['email']);
        if ($data['plan'] !== null) $this->findPlan($pdo, $data['plan']);
        $token = $this->storeAttempt($pdo, 'email', $data['email'], $data['plan']);
        return ['status' => 'profile_required', 'attemptToken' => $token, 'email' => $this->maskEmail($data['email']), 'plan' => $data['plan']];
    }

    /** @param array{subject:string,email:string,firstName:string,lastName:string,picture:string} $profile @return array<string,mixed> */
    public function beginSocial(string $provider, array $profile, ?string $plan): array
    {
        $pdo = $this->entityManager->getPDO();
        $this->assertEmailAvailable($pdo, $profile['email']);
        if ($plan !== null && $plan !== '') $this->findPlan($pdo, $plan);
        $token = $this->storeAttempt($pdo, 'social', $profile['email'], $plan ?: null, $provider, $profile);
        return ['status' => 'profile_required', 'attemptToken' => $token];
    }

    /** @return array<string,mixed> */
    public function complete(stdClass $input, string $fingerprint): array
    {
        $token = trim((string) ($input->attemptToken ?? ''));
        if ($token === '') throw new SignupProblem(422, 'invalid_attempt', 'Start signup again to continue.');
        $pdo = $this->entityManager->getPDO();
        $this->enforceRateLimit($pdo, $fingerprint, 'complete', 8);
        $pdo->beginTransaction();
        try {
            $attempt = $this->lockedAttempt($pdo, $token);
            $profile = json_decode((string) ($attempt['profile_json'] ?? '{}'), true) ?: [];
            $data = $this->validator->validateCompletion($input, $attempt['method'], $profile);
            $this->findPlan($pdo, $data['plan']);
            $passwordHash = $attempt['method'] === 'email' ? password_hash($data['password'], PASSWORD_BCRYPT) : null;
            $code = $attempt['method'] === 'email' ? $this->verificationCode() : null;
            $this->execute($pdo,
                'UPDATE nexa_signup_attempt SET first_name=?, last_name=?, password_hash=?, company_name=?, selected_plan_key=?, timezone=?, terms_accepted_at=CURRENT_TIMESTAMP(6), verification_code_hash=?, verification_expires_at=?, status=? WHERE id=?',
                [$data['firstName'], $data['lastName'], $passwordHash, $data['company'], $data['plan'], $data['timezone'],
                    $code ? $this->codeHash($attempt['normalized_email'], $code) : null,
                    $code ? (new DateTimeImmutable('+15 minutes'))->format('Y-m-d H:i:s.u') : null,
                    $attempt['method'] === 'email' ? 'verification_pending' : 'ready', $attempt['id']]
            );
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }

        if ($attempt['method'] === 'social') return $this->provision($token);
        $sent = $this->mailer->sendVerification($data['company'], $data['firstName'], $attempt['email'], (string) $code);
        return ['status' => 'verification_pending', 'email' => $this->maskEmail($attempt['email']), 'emailSent' => $sent];
    }

    /** @return array<string,mixed> */
    public function verify(string $token, string $code, string $fingerprint): array
    {
        $token = trim($token); $code = trim($code);
        if ($token === '' || !preg_match('/^\d{8}$/', $code)) throw new SignupProblem(400, 'invalid_code', 'Enter the valid eight-digit verification code.');
        $pdo = $this->entityManager->getPDO();
        $this->enforceRateLimit($pdo, $fingerprint, 'verify', 8);
        $pdo->beginTransaction();
        try {
            $attempt = $this->lockedAttempt($pdo, $token);
            if ($attempt['method'] !== 'email' || !hash_equals((string) $attempt['verification_code_hash'], $this->codeHash($attempt['normalized_email'], $code))) {
                throw new SignupProblem(400, 'invalid_code', 'The verification code is invalid.');
            }
            if (!$attempt['verification_expires_at'] || new DateTimeImmutable($attempt['verification_expires_at']) < new DateTimeImmutable()) {
                throw new SignupProblem(410, 'code_expired', 'This verification code has expired. Request a new code.');
            }
            $this->execute($pdo, 'UPDATE nexa_signup_attempt SET email_verified_at=CURRENT_TIMESTAMP(6), verification_code_hash=NULL, verification_expires_at=NULL, status=\'ready\' WHERE id=?', [$attempt['id']]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        return $this->provision($token);
    }

    /** @return array<string,mixed> */
    public function resend(string $token, string $fingerprint): array
    {
        $pdo = $this->entityManager->getPDO();
        $this->enforceRateLimit($pdo, $fingerprint, 'resend', 3);
        $attempt = $this->attempt($pdo, $token);
        if (!$attempt || $attempt['method'] !== 'email' || $attempt['status'] !== 'verification_pending') return ['status' => 'accepted', 'message' => 'If the signup is pending, a new verification code has been sent.'];
        $code = $this->verificationCode();
        $this->execute($pdo, 'UPDATE nexa_signup_attempt SET verification_code_hash=?, verification_expires_at=DATE_ADD(CURRENT_TIMESTAMP(6), INTERVAL 15 MINUTE) WHERE id=?', [$this->codeHash($attempt['normalized_email'], $code), $attempt['id']]);
        $this->mailer->sendVerification($attempt['company_name'], $attempt['first_name'], $attempt['email'], $code);
        return ['status' => 'accepted', 'message' => 'If the signup is pending, a new verification code has been sent.'];
    }

    /** @return array<string,mixed> */
    private function provision(string $token): array
    {
        $pdo = $this->entityManager->getPDO();
        $pdo->beginTransaction();
        try {
            $attempt = $this->lockedAttempt($pdo, $token);
            if ($attempt['status'] === 'completed') {
                $identity = $this->identityByEmail($pdo, $attempt['normalized_email']);
                $pdo->commit();
                return ['status' => 'active', 'session' => $this->sessionData($identity)];
            }
            if ($attempt['status'] !== 'ready' || !$attempt['email_verified_at'] || !$attempt['company_name'] || !$attempt['selected_plan_key']) {
                throw new SignupProblem(409, 'signup_incomplete', 'Complete and verify signup before creating a workspace.');
            }
            $this->assertEmailAvailable($pdo, $attempt['normalized_email']);
            $plan = $this->findPlan($pdo, $attempt['selected_plan_key']);
            $tenantId=$this->uuid(); $userId=$this->entityId(); $ownerId=$this->uuid(); $emailId=$this->entityId();
            $slug=$this->slug($attempt['company_name']); $now=(new DateTimeImmutable())->format('Y-m-d H:i:s.u'); $trialEnd=(new DateTimeImmutable('+14 days'))->format('Y-m-d H:i:s.u');
            $passwordHash=$attempt['password_hash'] ?: password_hash(bin2hex(random_bytes(32)), PASSWORD_BCRYPT);
            $this->execute($pdo, 'INSERT INTO nexa_tenant (id,slug,display_name,status,timezone) VALUES (?,?,?,\'active\',?)', [$tenantId,$slug,$attempt['company_name'],$attempt['timezone']]);
            $this->execute($pdo, 'INSERT INTO nexa_tenant_subscription (id,tenant_id,plan_id,status,period_starts_at,trial_ends_at) VALUES (?,?,?,\'trialing\',?,?)', [$this->uuid(),$tenantId,$plan['id'],$now,$trialEnd]);
            $this->execute($pdo, 'INSERT INTO nexa_tenant_service (tenant_id,service_id,status,soft_limit_override,hard_limit_override,configuration_json,starts_at) SELECT ?,service_id,IF(is_enabled=1,\'active\',\'disabled\'),soft_limit,hard_limit,configuration_json,? FROM nexa_plan_service WHERE plan_id=?', [$tenantId,$now,$plan['id']]);
            $this->execute($pdo, 'INSERT INTO `user` (id,deleted,user_name,type,password,first_name,last_name,is_active,created_at,modified_at,delete_id,tenant_id,service_id) VALUES (?,0,?,\'admin\',?,?,?,1,?,?,\'0\',?,?)', [$userId,$attempt['normalized_email'],$passwordHash,$attempt['first_name'],$attempt['last_name'],$now,$now,$tenantId,self::CRM_SERVICE_ID]);
            $this->execute($pdo, 'INSERT INTO email_address (id,name,deleted,`lower`,invalid,opt_out,tenant_id,service_id) VALUES (?,?,0,?,0,0,?,?)', [$emailId,$attempt['email'],$attempt['normalized_email'],$tenantId,self::CRM_SERVICE_ID]);
            $this->execute($pdo, 'INSERT INTO entity_email_address (entity_id,email_address_id,entity_type,`primary`,deleted,tenant_id,service_id) VALUES (?,?,\'User\',1,0,?,?)', [$userId,$emailId,$tenantId,self::CRM_SERVICE_ID]);
            $this->execute($pdo, 'INSERT INTO nexa_tenant_owner_identity (id,tenant_id,owner_user_id,email,normalized_email,status,verified_at) VALUES (?,?,?,?,?,\'active\',CURRENT_TIMESTAMP(6))', [$ownerId,$tenantId,$userId,$attempt['email'],$attempt['normalized_email']]);
            $this->execute($pdo, 'INSERT INTO nexa_provisioning_operation (id,tenant_id,operation_type,status,idempotency_key,attempt_count,completed_at) VALUES (?,?,\'signup\',\'completed\',?,1,CURRENT_TIMESTAMP(6))', [$this->uuid(),$tenantId,'signup-attempt:'.$attempt['id']]);
            if ($attempt['provider']) $this->execute($pdo, 'INSERT INTO nexa_external_identity (id,tenant_id,user_id,provider,provider_subject,normalized_email,profile_json,last_login_at) VALUES (?,?,?,?,?,?,?,CURRENT_TIMESTAMP(6))', [$this->uuid(),$tenantId,$userId,$attempt['provider'],$attempt['provider_subject'],$attempt['normalized_email'],$attempt['profile_json'] ?: '{}']);
            $this->audit($pdo,$tenantId,$userId,'signup.completed');
            $this->execute($pdo, 'UPDATE nexa_signup_attempt SET status=\'completed\',completed_at=CURRENT_TIMESTAMP(6) WHERE id=?', [$attempt['id']]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if ($e instanceof PDOException && $e->getCode() === '23000') throw new SignupProblem(409, 'email_in_use', 'An account already uses this email address.');
            throw $e;
        }
        $identity=['tenant_id'=>$tenantId,'slug'=>$slug,'display_name'=>$attempt['company_name'],'user_id'=>$userId,'user_name'=>$attempt['normalized_email']];
        return ['status'=>'active','trialEndsAt'=>$trialEnd,'session'=>$this->sessionData($identity)];
    }

    /** @param array<string,string> $profile */
    private function storeAttempt(PDO $pdo, string $method, string $email, ?string $plan, ?string $provider=null, array $profile=[]): string
    {
        $token=$this->randomToken(); $id=$this->uuid(); $profileJson=json_encode($profile ?: (object) [], JSON_THROW_ON_ERROR);
        $this->execute($pdo, 'INSERT INTO nexa_signup_attempt (id,public_token_hash,method,provider,provider_subject,email,normalized_email,first_name,last_name,profile_json,selected_plan_key,email_verified_at,status,expires_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,\'profile_pending\',DATE_ADD(CURRENT_TIMESTAMP(6),INTERVAL 30 MINUTE)) ON DUPLICATE KEY UPDATE id=VALUES(id),public_token_hash=VALUES(public_token_hash),method=VALUES(method),provider=VALUES(provider),provider_subject=VALUES(provider_subject),email=VALUES(email),first_name=VALUES(first_name),last_name=VALUES(last_name),profile_json=VALUES(profile_json),selected_plan_key=VALUES(selected_plan_key),password_hash=NULL,company_name=NULL,terms_accepted_at=NULL,email_verified_at=VALUES(email_verified_at),verification_code_hash=NULL,verification_expires_at=NULL,status=\'profile_pending\',expires_at=VALUES(expires_at),completed_at=NULL',
            [$id,$this->tokenHash($token),$method,$provider,$profile['subject']??null,$email,$email,$profile['firstName']??null,$profile['lastName']??null,$profileJson,$plan,$method==='social'?(new DateTimeImmutable())->format('Y-m-d H:i:s.u'):null]);
        return $token;
    }

    /** @return array<string,mixed> */
    private function lockedAttempt(PDO $pdo, string $token): array
    {
        $statement=$pdo->prepare('SELECT * FROM nexa_signup_attempt WHERE public_token_hash=? FOR UPDATE'); $statement->execute([$this->tokenHash($token)]); $row=$statement->fetch(PDO::FETCH_ASSOC);
        if (!$row || new DateTimeImmutable($row['expires_at']) < new DateTimeImmutable()) throw new SignupProblem(410,'attempt_expired','This signup session expired. Start again.');
        return $row;
    }

    /** @return array<string,mixed>|null */
    private function attempt(PDO $pdo, string $token): ?array
    {
        if (trim($token)==='') return null; $statement=$pdo->prepare('SELECT * FROM nexa_signup_attempt WHERE public_token_hash=? AND expires_at>CURRENT_TIMESTAMP(6)'); $statement->execute([$this->tokenHash($token)]); return $statement->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** @return array{id:string,plan_key:string} */
    private function findPlan(PDO $pdo,string $key): array { $s=$pdo->prepare('SELECT id,plan_key FROM nexa_plan_definition WHERE plan_key=? AND status=\'active\''); $s->execute([$key]); $p=$s->fetch(PDO::FETCH_ASSOC); if(!$p) throw new SignupProblem(422,'plan_unavailable','The selected plan is unavailable.'); return $p; }

    private function assertEmailAvailable(PDO $pdo,string $email): void
    {
        $s=$pdo->prepare('SELECT 1 FROM nexa_tenant_owner_identity WHERE normalized_email=? UNION SELECT 1 FROM `user` WHERE LOWER(user_name)=? AND deleted=0 UNION SELECT 1 FROM email_address WHERE `lower`=? AND deleted=0 UNION SELECT 1 FROM nexa_external_identity WHERE normalized_email=? LIMIT 1'); $s->execute([$email,$email,$email,$email]);
        if($s->fetchColumn()) throw new SignupProblem(409,'email_in_use','An account already uses this email address.');
    }

    /** @return array<string,string> */
    private function identityByEmail(PDO $pdo,string $email): array { $s=$pdo->prepare('SELECT i.tenant_id,i.owner_user_id user_id,t.slug,t.display_name,u.user_name FROM nexa_tenant_owner_identity i JOIN nexa_tenant t ON t.id=i.tenant_id JOIN `user` u ON u.id=i.owner_user_id AND u.tenant_id=i.tenant_id WHERE i.normalized_email=? AND i.status=\'active\''); $s->execute([$email]); return $s->fetch(PDO::FETCH_ASSOC) ?: throw new SignupProblem(409,'signup_incomplete','Workspace identity is unavailable.'); }

    /**
     * @param array<string,string> $identity
     * @return array{userName:string,token:string}
     */
    private function sessionData(array $identity): array
    {
        $context=new TenantContext($identity['tenant_id'],$identity['slug'],'signup-complete',$identity['display_name']);
        $token=$this->tenantContextStore->runWith($context,fn()=>$this->authTokenManager->create(AuthTokenData::create(['userId'=>$identity['user_id']])));

        // The browser stores this short-lived handoff through the same keys used
        // by Espo's normal login flow; it is never placed in a URL or server log.
        return ['userName'=>$identity['user_name'],'token'=>$token->getToken()];
    }

    private function enforceRateLimit(PDO $pdo,string $fingerprint,string $action,int $limit): void
    {
        $key=hash_hmac('sha256',$fingerprint,(string)$this->config->get('hashSecretKey','nexa')); $pdo->beginTransaction();
        try { $s=$pdo->prepare('SELECT * FROM nexa_signup_rate_limit WHERE fingerprint_hash=? AND action_key=? FOR UPDATE'); $s->execute([$key,$action]); $row=$s->fetch(PDO::FETCH_ASSOC); $now=new DateTimeImmutable();
            if($row&&$row['blocked_until']&&new DateTimeImmutable($row['blocked_until'])>$now) throw new SignupProblem(429,'rate_limited','Too many attempts. Try again later.');
            if(!$row||new DateTimeImmutable($row['window_started_at'])<$now->modify('-15 minutes')) $this->execute($pdo,'INSERT INTO nexa_signup_rate_limit (fingerprint_hash,action_key,window_started_at,attempt_count,blocked_until) VALUES (?,?,CURRENT_TIMESTAMP(6),1,NULL) ON DUPLICATE KEY UPDATE window_started_at=VALUES(window_started_at),attempt_count=1,blocked_until=NULL',[$key,$action]);
            else { $count=(int)$row['attempt_count']+1; $blocked=$count>$limit?$now->modify('+30 minutes')->format('Y-m-d H:i:s.u'):null; $this->execute($pdo,'UPDATE nexa_signup_rate_limit SET attempt_count=?,blocked_until=? WHERE fingerprint_hash=? AND action_key=?',[$count,$blocked,$key,$action]); if($blocked){$pdo->commit();throw new SignupProblem(429,'rate_limited','Too many attempts. Try again later.');} }
            if($pdo->inTransaction())$pdo->commit();
        } catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    }

    private function audit(PDO $pdo,string $tenantId,string $userId,string $action): void { $this->execute($pdo,'INSERT INTO nexa_audit_event (id,tenant_id,service_id,actor_type,actor_user_id,action,subject_type,subject_id,source,metadata_json) VALUES (?,?,?,\'user\',?,?,\'tenant\',?,\'self-service-signup\',JSON_OBJECT())',[$this->uuid(),$tenantId,self::CRM_SERVICE_ID,$userId,$action,$tenantId]); }
    /** @param list<mixed> $params */ private function execute(PDO $pdo,string $sql,array $params): void { $s=$pdo->prepare($sql);$s->execute($params); }
    private function codeHash(string $email,string $code): string { return hash_hmac('sha256',strtolower($email).':'.$code,(string)$this->config->get('hashSecretKey','nexa')); }
    private function tokenHash(string $token): string { return hash_hmac('sha256',$token,(string)$this->config->get('hashSecretKey','nexa')); }
    private function verificationCode(): string { return str_pad((string)random_int(0, 99999999),8,'0',STR_PAD_LEFT); }
    private function randomToken(): string { return rtrim(strtr(base64_encode(random_bytes(32)),'+/','-_'),'='); }
    private function uuid(): string { $b=random_bytes(16);$b[6]=chr((ord($b[6])&0x0f)|0x40);$b[8]=chr((ord($b[8])&0x3f)|0x80);return vsprintf('%s%s-%s-%s-%s-%s%s%s',str_split(bin2hex($b),4)); }
    private function entityId(): string { return substr(bin2hex(random_bytes(9)),0,17); }
    private function slug(string $company): string { $base=strtolower(trim((string)preg_replace('/[^a-z0-9]+/i','-',$company),'-'))?:'workspace';return substr($base,0,54).'-'.substr(bin2hex(random_bytes(4)),0,8); }
    private function maskEmail(string $email): string { [$local,$domain]=explode('@',$email,2);return substr($local,0,1).str_repeat('*',max(2,strlen($local)-1)).'@'.$domain; }
}