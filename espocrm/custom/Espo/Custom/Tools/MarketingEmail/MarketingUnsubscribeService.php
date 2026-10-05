<?php

declare(strict_types=1);

namespace Espo\Custom\Tools\MarketingEmail;

use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\ORM\EntityManager;
use Espo\Core\Tenant\TenantContext;
use Espo\Core\Tenant\TenantContextStore;
use Espo\Core\Utils\Config;
use Espo\Custom\Tools\PublicAccess\PublicRequestLimiter;
use Espo\Modules\Crm\Tools\MassEmail\UnsubscribeService;
use PDO;

/** Signed public links enter the original queue item's tenant before native opt-out. */
final class MarketingUnsubscribeService
{
    public function __construct(
        private EntityManager $entityManager,
        private TenantContextStore $tenantContextStore,
        private Config $config,
        private PublicRequestLimiter $limiter,
        private UnsubscribeService $native,
        private MarketingTimelineService $timeline,
    ) {}

    public function token(string $queueItemId): string
    {
        $key = (string) $this->config->get('hashSecretKey');
        if ($key === '') throw new \LogicException('The application signing key is missing.');
        return hash_hmac('sha256', 'nexa-marketing-unsubscribe:' . $queueItemId, $key);
    }

    /** @return array<string, mixed> */
    public function inspect(string $id, string $token): array
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token) || !hash_equals($this->token($id), $token)) {
            throw new Forbidden('This unsubscribe link is invalid.');
        }
        $find = $this->entityManager->getPDO()->prepare(
            'SELECT d.tenant_id,d.service_id,d.recipient,d.profile_id,d.provider_message_id FROM nexa_marketing_delivery_message d ' .
            'INNER JOIN email_queue_item q ON q.id=d.email_queue_item_id AND q.tenant_id=d.tenant_id AND q.service_id=d.service_id ' .
            "WHERE d.email_queue_item_id=? AND q.deleted=0 AND q.target_type='Contact' LIMIT 1"
        );
        $find->execute([$id]);
        $row = $find->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new NotFound('This unsubscribe link is unavailable.');
        $this->limiter->enforce($row['tenant_id'], $row['service_id'], 'marketing-unsubscribe', 60, 60);
        return $row;
    }

    public function unsubscribe(string $id, string $token): void
    {
        $row = $this->inspect($id, $token);
        $context = new TenantContext($row['tenant_id'], 'public', 'marketing-unsubscribe', '', $row['service_id']);
        $this->tenantContextStore->runWith($context, function () use ($id, $row, $context): void {
            $pdo = $this->entityManager->getPDO();
            $ownsTransaction = !$pdo->inTransaction();
            if ($ownsTransaction) $pdo->beginTransaction();
            try {
                $this->native->unsubscribe($id);
                $pdo->prepare(
                    "INSERT INTO nexa_marketing_suppression (id,tenant_id,service_id,email_address,reason,source) " .
                    "VALUES (UUID(),?,?,?,'unsubscribe','preference_center') " .
                    "ON DUPLICATE KEY UPDATE is_active=1,released_at=NULL,released_by_id=NULL"
                )->execute([$context->tenantId, $context->serviceId, strtolower($row['recipient'])]);
                $eventId = $this->uuid();
                $event = $pdo->prepare(
                    'INSERT IGNORE INTO nexa_marketing_delivery_event ' .
                    '(id,tenant_id,service_id,profile_id,provider_key,provider_event_id,provider_message_id,recipient,event_type,payload_json,occurred_at) ' .
                    "VALUES (?,?,?,?,'postal',?,?,?,'unsubscribed','{}',NOW(6))"
                );
                $event->execute([$eventId, $context->tenantId, $context->serviceId, $row['profile_id'],
                    hash('sha256', 'unsubscribe:' . $id), $row['provider_message_id'], $row['recipient']]);
                if ($event->rowCount() > 0) $this->timeline->project($eventId);
                if ($ownsTransaction) $pdo->commit();
            } catch (\Throwable $exception) {
                if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
                throw $exception;
            }
        });
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 15) | 64);
        $bytes[8] = chr((ord($bytes[8]) & 63) | 128);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
