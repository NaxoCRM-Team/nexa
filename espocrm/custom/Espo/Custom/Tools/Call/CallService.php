<?php

namespace Espo\Custom\Tools\Call;

use Espo\Core\Acl;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\ORM\EntityManager;
use Espo\Core\Tenant\ServiceEntitlementChecker;
use Espo\Core\Tenant\TenantContextStore;
use Espo\Entities\Notification;
use Espo\Entities\User;
use PDO;
use RuntimeException;

/**
 * Click-to-call business logic. `issueToken()` and `initiateCall()` run under
 * normal tenant-scoped request handling (TenantContextStore has a live
 * context). `handleTwiml()` and `handleStatusCallback()` are hit directly by
 * Twilio - no EspoCRM session, no tenant context (they run under
 * PlatformExecutionGateway via the TenantContextMiddleware allow-list) - so
 * they resolve everything from the nexa_call_session row via correlation_id,
 * never from tenant-context infrastructure.
 */
final class CallService
{
    private const SERVICE_KEY = 'voice.calling_minutes';

    private const TERMINAL_STATUSES = ['completed', 'no-answer', 'busy', 'failed', 'canceled'];

    public function __construct(
        private EntityManager $entityManager,
        private Acl $acl,
        private User $user,
        private TenantContextStore $tenantContextStore,
        private ServiceEntitlementChecker $entitlements,
        private TwilioClient $twilioClient,
        private CallMinutesLedger $ledger,
    ) {}

    /** @return array{token: string, identity: string, expiresAt: string} */
    public function issueToken(): array
    {
        if (!$this->entitlements->isEnabled(self::SERVICE_KEY)) {
            throw new Forbidden('Voice calling is not enabled for this workspace.');
        }

        $tenant = $this->tenantContextStore->require();
        $identity = $tenant->slug . ':' . $this->user->getId();

        $token = $this->twilioClient->generateAccessToken($identity);

        return [
            'token' => $token['token'],
            'identity' => $identity,
            'expiresAt' => $token['expiresAt'],
        ];
    }

    /**
     * Read-only minutes summary for the picker UI. "remaining" nets out both
     * committed usage (quantity) and minutes currently held by in-flight
     * calls (reserved_quantity) - an honest "how much could I reserve right
     * now" figure, matching what reserveForCall() would actually grant.
     *
     * @return array{remaining: ?int, limit: ?int, unlimited: bool, periodKey: string, perCallCapMinutes: int}
     */
    public function getMinutesSummary(): array
    {
        if (!$this->entitlements->isEnabled(self::SERVICE_KEY)) {
            throw new Forbidden('Voice calling is not enabled for this workspace.');
        }

        $tenant = $this->tenantContextStore->require();
        $serviceId = $this->resolveServiceId($tenant->tenantId);
        $periodKey = gmdate('Y-m');

        $limit = $this->ledger->effectiveLimit($tenant->tenantId, $serviceId, $periodKey);

        $usageStatement = $this->entityManager->getPDO()->prepare(
            'SELECT quantity, reserved_quantity FROM nexa_usage_counter ' .
            'WHERE tenant_id = :tenantId AND service_id = :serviceId AND period_key = :periodKey LIMIT 1'
        );
        $usageStatement->execute(['tenantId' => $tenant->tenantId, 'serviceId' => $serviceId, 'periodKey' => $periodKey]);
        $row = $usageStatement->fetch(PDO::FETCH_ASSOC);
        $used = $row ? (int) $row['quantity'] : 0;
        $reserved = $row ? (int) $row['reserved_quantity'] : 0;

        return [
            'remaining' => $limit === null ? null : max(0, $limit - $used - $reserved),
            'limit' => $limit,
            'unlimited' => $limit === null,
            'periodKey' => $periodKey,
            'perCallCapMinutes' => $this->ledger->perCallCapMinutes($tenant->tenantId, $serviceId),
        ];
    }

    /**
     * Tenant-admin-only: sets the per-call time limit (the ceiling Twilio is
     * told to auto-disconnect a single call at) away from the system default.
     * Bounded to a generous range purely to guard against typos - the pool's
     * own remaining balance already bounds any single call regardless of
     * this setting, so a high value here isn't itself a safety risk.
     */
    public function updateCallSettings(int $perCallCapMinutes): void
    {
        if (!$this->user->isAdmin()) {
            throw new Forbidden('Only a tenant administrator can change calling settings.');
        }

        if ($perCallCapMinutes < 1 || $perCallCapMinutes > CallMinutesLedger::MAX_PER_CALL_CAP_MINUTES) {
            throw new BadRequest(
                'Enter a per-call time limit between 1 and ' . CallMinutesLedger::MAX_PER_CALL_CAP_MINUTES . ' minutes.'
            );
        }

        $tenant = $this->tenantContextStore->require();
        $serviceId = $this->resolveServiceId($tenant->tenantId);

        $this->ledger->setPerCallCapMinutes($tenant->tenantId, $serviceId, $perCallCapMinutes);
    }

    private const CALLER_ID_VERIFICATION_TIMEOUT_SECONDS = 600;

    /**
     * Read-only caller ID status for the call button's gate and the global
     * banner - available to any tenant user (not just admins), since a
     * non-admin still needs to know why calling is blocked.
     *
     * @return array{status: string, callerNumber: ?string}
     */
    public function getCallerIdStatus(): array
    {
        $tenant = $this->tenantContextStore->require();
        $serviceId = $this->resolveServiceId($tenant->tenantId);

        $row = $this->fetchCallerIdRow($tenant->tenantId, $serviceId);

        return [
            'status' => $row['caller_number_status'],
            'callerNumber' => $row['caller_number_status'] === 'verified' ? $row['caller_number'] : null,
        ];
    }

    /**
     * Tenant-admin-only: starts Twilio's call-based verification for the
     * tenant's business phone number. Twilio calls the number immediately;
     * the admin completes it by answering and entering the code Twilio reads
     * out, then calling confirmCallerIdVerification(). If Twilio already has
     * this exact number verified from some earlier attempt, it's marked
     * verified immediately - there's no call left to place or wait on.
     *
     * @return array{status: string, validationCode: ?string}
     */
    public function startCallerIdVerification(string $phoneNumber): array
    {
        if (!$this->user->isAdmin()) {
            throw new Forbidden('Only a tenant administrator can verify a business phone number.');
        }

        $phoneNumber = trim($phoneNumber);

        if ($phoneNumber === '' || preg_match('/^\+[1-9][0-9]{6,14}$/', $phoneNumber) !== 1) {
            throw new BadRequest(
                'Enter the business phone number in international format, starting with "+" and the country code.'
            );
        }

        $tenant = $this->tenantContextStore->require();
        $serviceId = $this->resolveServiceId($tenant->tenantId);

        $result = $this->twilioClient->startCallerIdVerification($phoneNumber, $tenant->displayName ?: $tenant->slug);
        $status = $result['alreadyVerified'] ? 'verified' : 'pending';

        $statement = $this->entityManager->getPDO()->prepare(
            'UPDATE nexa_tenant_service SET caller_number = :callerNumber, caller_number_status = :status, ' .
            'caller_number_verification_started_at = NOW(6) WHERE tenant_id = :tenantId AND service_id = :serviceId'
        );
        $statement->execute([
            'callerNumber' => $phoneNumber,
            'status' => $status,
            'tenantId' => $tenant->tenantId,
            'serviceId' => $serviceId,
        ]);

        return ['status' => $status, 'validationCode' => $result['validationCode']];
    }

    /**
     * Tenant-admin-only: re-checks Twilio for whether the pending number has
     * actually been verified yet (code entered on the call). A stale pending
     * attempt (the admin never completed the call) resets to unverified so
     * they get a clean retry instead of being stuck.
     *
     * @return array{status: string, callerNumber: ?string}
     */
    public function confirmCallerIdVerification(): array
    {
        if (!$this->user->isAdmin()) {
            throw new Forbidden('Only a tenant administrator can verify a business phone number.');
        }

        $tenant = $this->tenantContextStore->require();
        $serviceId = $this->resolveServiceId($tenant->tenantId);
        $row = $this->fetchCallerIdRow($tenant->tenantId, $serviceId);

        if ($row['caller_number_status'] !== 'pending' || $row['caller_number'] === null) {
            return ['status' => $row['caller_number_status'], 'callerNumber' => null];
        }

        if ($this->twilioClient->isCallerIdVerified($row['caller_number'])) {
            $statement = $this->entityManager->getPDO()->prepare(
                'UPDATE nexa_tenant_service SET caller_number_status = \'verified\' ' .
                'WHERE tenant_id = :tenantId AND service_id = :serviceId'
            );
            $statement->execute(['tenantId' => $tenant->tenantId, 'serviceId' => $serviceId]);

            return ['status' => 'verified', 'callerNumber' => $row['caller_number']];
        }

        $startedAt = $row['caller_number_verification_started_at'];
        if ($startedAt !== null && (time() - strtotime($startedAt)) > self::CALLER_ID_VERIFICATION_TIMEOUT_SECONDS) {
            $statement = $this->entityManager->getPDO()->prepare(
                'UPDATE nexa_tenant_service SET caller_number_status = \'unverified\', caller_number = NULL, ' .
                'caller_number_verification_started_at = NULL WHERE tenant_id = :tenantId AND service_id = :serviceId'
            );
            $statement->execute(['tenantId' => $tenant->tenantId, 'serviceId' => $serviceId]);

            return ['status' => 'unverified', 'callerNumber' => null];
        }

        return ['status' => 'pending', 'callerNumber' => null];
    }

    /** @return array{caller_number: ?string, caller_number_status: string, caller_number_verification_started_at: ?string} */
    private function fetchCallerIdRow(string $tenantId, string $serviceId): array
    {
        $statement = $this->entityManager->getPDO()->prepare(
            'SELECT caller_number, caller_number_status, caller_number_verification_started_at ' .
            'FROM nexa_tenant_service WHERE tenant_id = :tenantId AND service_id = :serviceId LIMIT 1'
        );
        $statement->execute(['tenantId' => $tenantId, 'serviceId' => $serviceId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return [
            'caller_number' => $row['caller_number'] ?? null,
            'caller_number_status' => $row['caller_number_status'] ?? 'unverified',
            'caller_number_verification_started_at' => $row['caller_number_verification_started_at'] ?? null,
        ];
    }

    /** @return array{correlationId: string} */
    public function initiateCall(string $contactId, string $toNumber): array
    {
        if (!$this->entitlements->isEnabled(self::SERVICE_KEY)) {
            throw new Forbidden('Voice calling is not enabled for this workspace.');
        }

        $toNumber = trim($toNumber);

        if ($toNumber === '' || preg_match('/^\+[1-9][0-9]{6,14}$/', $toNumber) !== 1) {
            throw new BadRequest(
                'Enter the number to call in international format, starting with "+" and the country code.'
            );
        }

        $contact = $this->entityManager->getRDBRepository('Contact')->getById($contactId);

        if (!$contact || !$this->acl->checkEntityRead($contact)) {
            throw new NotFound('Contact not found.');
        }

        $tenant = $this->tenantContextStore->require();
        $serviceId = $this->resolveServiceId($tenant->tenantId);
        $periodKey = gmdate('Y-m');

        // Every tenant calls out on its own verified business number, never
        // a shared platform number - fail fast here, before ever touching
        // the minutes ledger, if that hasn't been set up yet.
        $callerIdRow = $this->fetchCallerIdRow($tenant->tenantId, $serviceId);
        if ($callerIdRow['caller_number_status'] !== 'verified' || $callerIdRow['caller_number'] === null) {
            throw new Forbidden('CALLER_ID_NOT_VERIFIED');
        }

        // Race-free admission control: this reserves a slice of the shared
        // pool atomically (see CallMinutesLedger) before the call is ever
        // allowed to ring, so no number of concurrent callers can over-admit
        // the tenant's allocation. Throws Forbidden('MINUTES_EXHAUSTED') if
        // there's no room left.
        $reservedMinutes = $this->ledger->reserveForCall($tenant->tenantId, $serviceId, $periodKey);

        $correlationId = $this->uuid();

        try {
            $statement = $this->entityManager->getPDO()->prepare(
                'INSERT INTO nexa_call_session ' .
                '(id, tenant_id, service_id, contact_id, initiated_by_user_id, correlation_id, from_number, to_number, direction, status, reserved_minutes) ' .
                'VALUES (:id, :tenantId, :serviceId, :contactId, :userId, :correlationId, :fromNumber, :toNumber, \'outbound\', \'initiated\', :reservedMinutes)'
            );
            $statement->execute([
                'id' => $this->uuid(),
                'tenantId' => $tenant->tenantId,
                'serviceId' => $serviceId,
                'contactId' => $contactId,
                'userId' => $this->user->getId(),
                'correlationId' => $correlationId,
                'fromNumber' => $callerIdRow['caller_number'],
                'toNumber' => $toNumber,
                'reservedMinutes' => $reservedMinutes,
            ]);
        } catch (\Throwable $e) {
            // The reservation already succeeded; if the session row can't be
            // written, release it immediately rather than leaking it until
            // the stale-cleanup job runs (it has no session row to find).
            $this->ledger->settleCall($tenant->tenantId, $serviceId, $periodKey, $reservedMinutes, 0);

            throw $e;
        }

        return ['correlationId' => $correlationId];
    }

    /**
     * Called from PostTwiml after signature verification. Returns TwiML XML.
     * No tenant context available - resolves everything via correlation_id.
     *
     * @param array<string, string> $params
     */
    public function handleTwiml(array $params): string
    {
        $correlationId = trim((string) ($params['correlationId'] ?? ''));
        $callSid = trim((string) ($params['CallSid'] ?? ''));

        if ($correlationId === '') {
            return $this->rejectTwiml();
        }

        $statement = $this->entityManager->getPDO()->prepare(
            'SELECT id, to_number, from_number, reserved_minutes FROM nexa_call_session ' .
            'WHERE correlation_id = :correlationId AND status = \'initiated\' LIMIT 1'
        );
        $statement->execute(['correlationId' => $correlationId]);
        $session = $statement->fetch(PDO::FETCH_ASSOC);

        if (!$session) {
            return $this->rejectTwiml();
        }

        if ($callSid !== '') {
            $update = $this->entityManager->getPDO()->prepare(
                'UPDATE nexa_call_session SET twilio_call_sid = :callSid, status = \'ringing\' ' .
                'WHERE id = :id AND twilio_call_sid IS NULL'
            );
            $update->execute(['callSid' => $callSid, 'id' => $session['id']]);
        }

        $toNumber = $this->xmlEscape((string) $session['to_number']);
        $callerId = $this->xmlEscape((string) $session['from_number']);
        $statusCallback = $this->xmlEscape($this->statusCallbackUrl($correlationId));
        // Twilio hangs the call up itself once this many seconds elapse -
        // the reservation ceiling from initiateCall(), enforced provider-side
        // with no custom live-monitoring needed.
        $timeLimitSeconds = ((int) ($session['reserved_minutes'] ?? 60)) * 60;

        return '<?xml version="1.0" encoding="UTF-8"?>' .
            '<Response><Dial callerId="' . $callerId . '" timeLimit="' . $timeLimitSeconds . '">' .
            '<Number statusCallback="' . $statusCallback . '" statusCallbackEvent="completed" statusCallbackMethod="POST">' .
            $toNumber . '</Number></Dial></Response>';
    }

    /**
     * Called from PostStatus after signature verification.
     *
     * @param array<string, string> $params
     */
    public function handleStatusCallback(string $correlationId, array $params): void
    {
        if ($correlationId === '') {
            return;
        }

        $select = $this->entityManager->getPDO()->prepare(
            'SELECT id FROM nexa_call_session WHERE correlation_id = :correlationId LIMIT 1'
        );
        $select->execute(['correlationId' => $correlationId]);
        $sessionId = $select->fetchColumn();

        if (!is_string($sessionId) || $sessionId === '') {
            return;
        }

        $twilioStatus = trim((string) ($params['CallStatus'] ?? ''));
        $durationRaw = $params['CallDuration'] ?? null;
        $duration = ($durationRaw !== null && $durationRaw !== '') ? (int) $durationRaw : null;
        $status = $this->mapTwilioStatus($twilioStatus);

        $this->finalizeCall($sessionId, $status, $duration);
    }

    /**
     * Force-fails a call session stuck in a non-terminal status (browser
     * crash mid-call, webhook never arrived) so its reservation isn't held
     * forever. Called by the ReleaseStaleCallReservations scheduled job.
     */
    public function forceFailStaleSession(string $sessionId): void
    {
        $this->finalizeCall($sessionId, 'failed', null);
    }

    /**
     * The single guarded finalize path shared by handleStatusCallback() (a
     * real Twilio webhook) and forceFailStaleSession() (the stale-cleanup
     * job). Both race for the same guarded UPDATE below - whichever one wins
     * it is the only one that ever touches the minutes ledger, closing a
     * double-release bug where a late webhook and the cleanup job could
     * otherwise both settle the same call.
     */
    private function finalizeCall(string $sessionId, string $status, ?int $durationSeconds): void
    {
        $terminalList = "'" . implode("','", self::TERMINAL_STATUSES) . "'";
        $pdo = $this->entityManager->getPDO();

        $update = $pdo->prepare(
            'UPDATE nexa_call_session SET status = :status, duration_seconds = :duration, ended_at = NOW(6) ' .
            "WHERE id = :id AND status NOT IN ({$terminalList})"
        );
        $update->execute([
            'status' => $status,
            'duration' => $durationSeconds,
            'id' => $sessionId,
        ]);

        if ($update->rowCount() !== 1) {
            // Lost the race - the other caller already settled the ledger.
            return;
        }

        $select = $pdo->prepare(
            'SELECT tenant_id, service_id, reserved_minutes, created_at FROM nexa_call_session WHERE id = :id LIMIT 1'
        );
        $select->execute(['id' => $sessionId]);
        $session = $select->fetch(PDO::FETCH_ASSOC);

        if (!$session || $session['reserved_minutes'] === null) {
            return;
        }

        $reservedMinutes = (int) $session['reserved_minutes'];
        $billedMinutes = $status === 'completed'
            ? min($reservedMinutes, max(1, (int) ceil((float) ($durationSeconds ?? 0) / 60)))
            : 0;
        // Settle against the SAME period the reservation was made under
        // (derived from the session's own created_at), not "now" - a call
        // that happens to span a month boundary must not release the wrong
        // period's counter row.
        $periodKey = gmdate('Y-m', strtotime((string) $session['created_at']));

        $this->ledger->settleCall(
            (string) $session['tenant_id'],
            (string) $session['service_id'],
            $periodKey,
            $reservedMinutes,
            $billedMinutes
        );

        // The guarded UPDATE above already won the idempotency race for this
        // session (rowCount() === 1, checked earlier) - no concurrent caller
        // can reach this point for the same id, so a plain follow-up UPDATE
        // is safe here without its own guard.
        $pdo->prepare('UPDATE nexa_call_session SET billed_minutes = :billedMinutes WHERE id = :id')
            ->execute(['billedMinutes' => $billedMinutes, 'id' => $sessionId]);

        // Outside the ledger's own transaction (settleCall() already
        // committed above) - safe to do the ORM Notification write here.
        if ($billedMinutes > 0) {
            $softLimitHit = $this->ledger->claimSoftLimitNotice(
                (string) $session['tenant_id'],
                (string) $session['service_id'],
                $periodKey
            );

            if ($softLimitHit !== null) {
                $this->notifySoftLimitReached(
                    (string) $session['tenant_id'],
                    (string) $session['service_id'],
                    $periodKey,
                    $softLimitHit['used']
                );
            }
        }
    }

    /**
     * Notifies every tenant admin once per period that calling usage has
     * crossed the plan's soft_limit warning threshold - a heads-up before
     * the hard cap is hit, not a block on anything.
     */
    private function notifySoftLimitReached(string $tenantId, string $serviceId, string $periodKey, int $used): void
    {
        $limit = $this->ledger->effectiveLimit($tenantId, $serviceId, $periodKey);
        $message = $limit !== null
            ? "Your team has used {$used} of {$limit} calling minutes this month - approaching the plan limit."
            : "Your team has used {$used} calling minutes this month - approaching the usual plan threshold.";

        $adminStatement = $this->entityManager->getPDO()->prepare(
            "SELECT id FROM `user` WHERE tenant_id = :tenantId AND type = 'admin' AND deleted = 0"
        );
        $adminStatement->execute(['tenantId' => $tenantId]);

        foreach ($adminStatement->fetchAll(PDO::FETCH_COLUMN) ?: [] as $adminId) {
            $this->entityManager->createEntity(Notification::ENTITY_TYPE, [
                'type' => Notification::TYPE_SYSTEM,
                'userId' => (string) $adminId,
                'data' => ['message' => $message],
            ]);
        }
    }

    private function mapTwilioStatus(string $twilioStatus): string
    {
        return match ($twilioStatus) {
            'completed', 'no-answer', 'busy', 'failed', 'canceled', 'in-progress', 'ringing' => $twilioStatus,
            default => 'in-progress',
        };
    }

    private function rejectTwiml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?><Response><Reject/></Response>';
    }

    private function statusCallbackUrl(string $correlationId): string
    {
        $base = $this->twilioClient->publicBaseUrl();

        if ($base === '') {
            throw new RuntimeException('No public base URL is configured for Twilio callbacks.');
        }

        return $base . $this->twilioClient->installPathPrefix() .
            '/api/v1/Nexa/call/status?correlationId=' . rawurlencode($correlationId);
    }

    /** Mirrors TenantDashboardService::resolveCrmServiceId(), for the voice service. */
    private function resolveServiceId(string $tenantId): string
    {
        $statement = $this->entityManager->getPDO()->prepare(
            'SELECT ts.service_id FROM nexa_tenant_service ts ' .
            'INNER JOIN nexa_service_definition sd ON sd.id = ts.service_id ' .
            'WHERE ts.tenant_id = :tenantId AND sd.service_key = :serviceKey AND ts.status = \'active\' LIMIT 1'
        );
        $statement->execute(['tenantId' => $tenantId, 'serviceKey' => self::SERVICE_KEY]);
        $serviceId = $statement->fetchColumn();

        if (!is_string($serviceId) || $serviceId === '') {
            throw new Forbidden('Voice calling is not enabled for this workspace.');
        }

        return $serviceId;
    }

    private function xmlEscape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
