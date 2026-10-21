<?php
declare(strict_types=1);
namespace Espo\Custom\Tools\Conversation;

use Espo\Core\Exceptions\{BadRequest, Forbidden, NotFound};
use Espo\Core\ORM\EntityManager;
use Espo\Core\Tenant\{TenantContext, TenantContextStore};
use Espo\Custom\Tools\Event\PublicEventCollectorService;
use Espo\Custom\Tools\PublicAccess\PublicRequestLimiter;
use stdClass;

/** Bearer capabilities never accept browser-supplied CRM identity or ownership. */
final class PublicChatService
{
    public function __construct(private EntityManager $em, private TenantContextStore $contexts, private ChatStore $store, private PublicRequestLimiter $limiter, private ChatBotRuntime $bots) {}
    public function authorize(string $key, string $origin): array
    {
        if (!preg_match('/^[a-f0-9]{48}$/D', $key)) throw new NotFound('Chat unavailable.');
        $origin = PublicEventCollectorService::normalizeOrigin($origin);
        $row = $this->store->rows("SELECT w.* FROM nexa_chat_widget w INNER JOIN nexa_tenant t ON t.id=w.tenant_id AND t.status='active' INNER JOIN nexa_tenant_service ts ON ts.tenant_id=w.tenant_id AND ts.service_id=w.service_id AND ts.status='active' INNER JOIN nexa_service_definition d ON d.id=w.service_id AND d.status='active' WHERE w.public_key=? AND w.is_enabled=1", [$key])[0] ?? null;
        if (!$row || !in_array($origin, json_decode($row['allowed_origins_json'], true), true)) throw new Forbidden('Chat is not available for this website.');
        return $row;
    }
    public function process(string $key, string $origin, string $token, stdClass $input): array
    {
        $origin = PublicEventCollectorService::normalizeOrigin($origin);
        $widget = $this->authorize($key, $origin);
        if (strlen(json_encode($input, JSON_THROW_ON_ERROR)) > 20000 || array_diff(array_keys((array) $input), ['op','id','visitorKey','nonce','body','after','before','step','choice'])) throw new BadRequest('Unsupported chat input.');
        $op = $input->op ?? '';
        if (!in_array($op, ['config','start','resume','read','send','end','handoff','choose'], true)) throw new BadRequest('Choose a chat operation.');
        $this->limiter->enforce($widget['tenant_id'], $widget['service_id'], 'chat-'.$widget['id'], 120, 60);
        $context = new TenantContext($widget['tenant_id'], 'public', 'website-chat', '', $widget['service_id']);
        return $this->contexts->runWith($context, function () use ($widget, $origin, $token, $input, $op): array {
            if ($op === 'config') return array_intersect_key($widget, array_flip(['title','welcome','color','position','draggable','privacy_url']));
            if (!preg_match('/^[a-f0-9]{64}$/D', $token)) throw new Forbidden('Chat session unavailable.');
            if ($op === 'resume') {
                ChatStore::uuidValue($input->nonce ?? null);
                return $this->store->atomic(function () use($widget,$origin,$token,$input):array {
                    $old=$this->store->rows('SELECT id FROM nexa_chat_conversation WHERE tenant_id=? AND service_id=? AND widget_id=? AND start_nonce=? AND deleted=0',[...$this->store->scope(),$widget['id'],$input->nonce])[0]??null;
                    if (!$old) return ['id'=>null,'messages'=>[],'hasMore'=>false];
                    $root=$this->session($widget,$origin,$old['id'],$token);
                    if (!(bool)$widget['bot_enabled']) {$this->bots->stop($root,'stopped');$root=$this->store->lock($root->getId());}
                    return ['id'=>$root->getId(),'bot'=>$this->bots->state($root),'handoff'=>$this->store->handoffState($root)]+$this->store->history($root->getId());
                });
            }
            if ($op === 'start') {
                ChatStore::uuidValue($input->nonce ?? null); $visitor = ChatStore::uuidValue($input->visitorKey ?? null);
                $this->limiter->enforce($widget['tenant_id'], $widget['service_id'], 'chat-start-'.$widget['id'], 10, 3600);
                return $this->store->atomic(function () use ($widget, $origin, $token, $input, $visitor): array {
                    // Lock admission before creating a session, then use the same order for message admission.
                    $this->store->rows('SELECT tenant_id FROM nexa_tenant_service WHERE tenant_id=? AND service_id=? FOR UPDATE', $this->store->scope());
                    $nonceHash=hash('sha256',implode("\0",[...$this->store->scope(),$widget['id'],$input->nonce]));
                    if($this->store->rows('SELECT conversation_id FROM nexa_chat_erasure WHERE tenant_id=? AND service_id=? AND widget_id=? AND nonce_hash=?',[...$this->store->scope(),$widget['id'],$nonceHash])||$this->store->rows('SELECT id FROM nexa_chat_conversation WHERE tenant_id=? AND service_id=? AND widget_id=? AND start_nonce=? AND deleted=1',[...$this->store->scope(),$widget['id'],$input->nonce]))throw new NotFound('Chat session unavailable.');
                    $old = $this->store->rows('SELECT id FROM nexa_chat_conversation WHERE tenant_id=? AND service_id=? AND widget_id=? AND start_nonce=? AND deleted=0', [...$this->store->scope(), $widget['id'], $input->nonce])[0] ?? null;
                    if ($old) $root = $this->session($widget, $origin, $old['id'], $token);
                    else {
                        $daily = $this->store->rows('SELECT id FROM nexa_chat_conversation WHERE tenant_id=? AND service_id=? AND created_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 DAY) LIMIT 1000', $this->store->scope());
                        if (count($daily) >= 1000) throw new BadRequest('The workspace chat session limit has been reached.');
                        $c = $this->contexts->require();
                        $root = $this->em->createEntity('NexaChatConversation', ['name' => $widget['name'], 'status' => 'Open', 'widgetId' => $widget['id'], 'origin' => $origin,
                            'tokenHash' => hash('sha256', $token), 'startNonce' => $input->nonce, 'visitorKeyHash' => hash('sha256', $c->tenantId."\0".$c->serviceId."\0".$visitor),
                            'expiresAt' => gmdate('Y-m-d H:i:s', time()+86400)], ['skipCreatedBy'=>true,'skipModifiedBy'=>true]);
                    }
                    $this->store->append($root, $input->nonce, $input->body ?? null);
                    if (!$old) $this->bots->initialize($root,$widget);
                    $root=$this->store->lock($root->getId());
                    return ['id' => $root->getId(),'bot'=>$this->bots->state($root),'handoff'=>$this->store->handoffState($root)] + $this->store->history($root->getId());
                }, true);
            }
            $id = $input->id ?? '';
            if (!is_string($id)) throw new NotFound('Chat unavailable.');
            return $this->store->atomic(function () use ($widget, $origin, $id, $token, $input, $op): array {
                $root = $this->session($widget, $origin, $id, $token);
                if (!(bool)$widget['bot_enabled']) {$this->bots->stop($root,'stopped');$root=$this->store->lock($id);}
                if ($op === 'end') { $this->bots->stop($root); $root=$this->store->lock($id); $root->set('expiresAt',gmdate('Y-m-d H:i:s')); $this->em->saveEntity($root,['silent'=>true]); return ['ended'=>true]; }
                if ($op === 'send') $this->store->append($root, $input->nonce ?? null, $input->body ?? null);
                if ($op === 'choose') $this->bots->choose($root,$input);
                $root=$this->store->lock($id);
                $handoff=$op==='handoff' ? $this->store->handoff($root) : $this->store->handoffState($root);
                $root=$this->store->lock($id);
                return ['id' => $id, 'status' => $root->get('status'),'bot'=>$this->bots->state($root),'handoff'=>$handoff] + $this->store->history($id, $input->after ?? '', $input->before ?? '');
            }, in_array($op,['send','choose'],true));
        });
    }
    private function session(array $widget, string $origin, string $id, string $token): \Espo\ORM\Entity
    {
        $root = $this->store->lock($id);
        if ($root->get('widgetId') !== $widget['id'] || $root->get('origin') !== $origin || !hash_equals((string) $root->get('tokenHash'), hash('sha256', $token)) || $root->get('expiresAt') <= gmdate('Y-m-d H:i:s')) throw new NotFound('Chat session unavailable.');
        return $root;
    }
}
