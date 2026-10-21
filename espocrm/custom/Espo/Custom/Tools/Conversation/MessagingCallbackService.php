<?php
declare(strict_types=1);
namespace Espo\Custom\Tools\Conversation;

use Espo\Core\Exceptions\{BadRequest, Conflict, Forbidden, NotFound};
use Espo\Core\Tenant\{TenantContext, TenantContextStore};
use Espo\Core\Utils\Config;
use Espo\Custom\Tools\Auth\ProviderSecretCipher;
use Espo\Custom\Tools\PublicAccess\PublicRequestLimiter;

/** Public signature boundary; ambient CRM identity and request Host are never trusted. */
final class MessagingCallbackService
{
    public function __construct(private ChatStore $store, private TenantContextStore $contexts,
        private ProviderSecretCipher $cipher, private Config $config, private PublicRequestLimiter $limiter,private SmsDeliveryService $delivery) {}

    public function callbackUrl(string $id, string $kind,?string $attempt=null): string
    {
        ChatStore::uuidValue($id);
        if (!in_array($kind, ['inbound','status'], true)) throw new NotFound('Callback unavailable.');
        if($attempt!==null){ChatStore::uuidValue($attempt);if($kind!=='status')throw new NotFound('Callback unavailable.');}
        // Deployment-owned configuration, not a tenant-supplied URL or proxy header.
        $base = $this->config->get('nexaMessagingPublicBaseUrl');
        $parts = is_string($base) ? parse_url($base) : false;
        if (!$parts || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) ||
            isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']) ||
            preg_match('/[\x00-\x20\x7f\\\\]/', $base) || strlen($base) > 1500) {
            throw new Forbidden('Public messaging callbacks are not configured.');
        }
        return rtrim($base, '/').'/api/v1/Nexa/public/messaging/'.$id.'/'.$kind.($attempt!==null?'/'.$attempt:'');
    }

    public function process(string $id, string $kind, string $body, string $type, string $signature,?string $attempt=null): array
    {
        $url = $this->callbackUrl($id, $kind,$attempt);
        $params = TwilioWebhookForm::parse($body, $type);
        // This one global lookup is by server-generated connection identity only.
        $snapshot = $this->eligible($id);
        $secret = $this->cipher->decryptFor($snapshot['secret_encrypted'],$snapshot['tenant_id'],$snapshot['service_id'],'messaging:'.$id);
        $event = (new TwilioMessagingAdapter())->webhook($url,$params,$signature,$secret,$snapshot['account_sid'],$snapshot['channel'],$snapshot['sender'],$kind);
        // Unauthenticated traffic cannot consume the provider's admission bucket or lock a workspace.
        $this->limiter->enforce($snapshot['tenant_id'], $snapshot['service_id'], 'messaging-callback-'.$id, 120, 60);
        $context = new TenantContext($snapshot['tenant_id'], 'public', 'messaging-callback', '', $snapshot['service_id']);
        return $this->contexts->runWith($context, function () use ($snapshot,$id,$kind,$params,$event,$attempt): array {
            return $this->store->atomic(function () use ($snapshot,$id,$kind,$params,$event,$attempt): array {
                // Match settings-save lock order and reject revocation/rotation races.
                $this->store->rows('SELECT id FROM nexa_messaging_connection WHERE tenant_id=? AND service_id=? AND id=? FOR UPDATE', [...$this->store->scope(),$id]);
                $this->store->rows('SELECT id FROM nexa_tenant WHERE id=? FOR UPDATE', [$snapshot['tenant_id']]);
                $row = $this->eligible($id);
                if ([$row['tenant_id'],$row['service_id']] !== $this->store->scope() ||
                    $row['version_number'] !== $snapshot['version_number']) throw new Forbidden('Messaging connection changed.');
                ksort($params, SORT_STRING);
                $hash = hash('sha256', json_encode($params, JSON_THROW_ON_ERROR));
                // One inbound message identity; distinct status observations are retained separately.
                $key = hash('sha256', $kind."\0".$event['messageId'].($kind==='status'?"\0".$hash.($attempt!==null?"\0".$attempt:''):''));
                $old = $this->store->rows('SELECT id,payload_hash FROM nexa_messaging_receipt WHERE tenant_id=? AND service_id=? AND connection_id=? AND event_key=?', [...$this->store->scope(),$id,$key])[0] ?? null;
                if ($old) {
                    if (!hash_equals($old['payload_hash'],$hash)) throw new Conflict('Provider message identity has conflicting content.');
                    return ['duplicate'=>true];
                }
                $volume = $this->store->rows('SELECT id FROM nexa_messaging_receipt WHERE tenant_id=? AND service_id=? AND received_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 HOUR) LIMIT 10000', $this->store->scope());
                if (count($volume)>=10000) throw new BadRequest('Messaging receipt capacity reached.');
                $receipt = ChatStore::uuid();
                $disposition=$kind==='inbound'?'received':'unmatched_status';
                if($kind==='status'&&$attempt!==null&&$this->delivery->apply($attempt,$event['messageId'],$event['status'],$event['from'],$event['to'],$id))$disposition='applied_status';
                $encrypted = $this->cipher->encryptFor(json_encode($event,JSON_THROW_ON_ERROR),$row['tenant_id'],$row['service_id'],'messaging-receipt:'.$receipt);
                $this->store->execute('INSERT INTO nexa_messaging_receipt (id,tenant_id,service_id,connection_id,kind,provider_message_id,event_key,payload_hash,payload_encrypted,connection_version,disposition,preference_processed,peer_hash,opt_out_type,attempt_id,received_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,1,?,?,?,UTC_TIMESTAMP())',
                    [$receipt,...$this->store->scope(),$id,$kind,$event['messageId'],$key,$hash,$encrypted,$row['version_number'],$disposition,$kind==='inbound'?SmsPreference::peerHash($id,$event['from']):null,SmsPreference::type($event),$attempt]);
                return ['duplicate'=>false];
            }, true);
        });
    }

    private function eligible(string $id): array
    {
        $row = $this->store->rows("SELECT c.* FROM nexa_messaging_connection c INNER JOIN nexa_tenant t ON t.id=c.tenant_id AND t.status='active' INNER JOIN nexa_tenant_service ts ON ts.tenant_id=c.tenant_id AND ts.service_id=c.service_id AND ts.status='active' INNER JOIN nexa_service_definition d ON d.id=c.service_id AND d.status='active' WHERE c.id=? AND c.provider='twilio' AND c.channel IN ('sms','whatsapp') AND c.status='verified' AND c.verified_at IS NOT NULL AND c.provider_resource_sid IS NOT NULL", [$id])[0] ?? null;
        if (!$row) throw new NotFound('Messaging callback unavailable.');
        return $row;
    }
}
