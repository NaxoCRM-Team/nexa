<?php
declare(strict_types=1);
namespace Espo\Custom\Tools\Conversation;
final class MessengerRecovery
{
    public function __construct(private ChatStore $store,private MessengerMediaService $media) {}
    public function run(): void
    {
        $this->store->atomic(function():void{
            $rows=$this->store->rows("SELECT id FROM nexa_messenger_reply WHERE tenant_id=? AND service_id=? AND status='submitting' AND modified_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 2 MINUTE) LIMIT 100 FOR UPDATE",$this->store->scope());
            foreach($rows as $row){$this->store->execute("UPDATE nexa_messenger_reply SET status='uncertain',modified_at=UTC_TIMESTAMP() WHERE tenant_id=? AND service_id=? AND id=?",[...$this->store->scope(),$row['id']]);
                $this->store->execute("INSERT INTO nexa_audit_event (id,tenant_id,service_id,actor_type,action,subject_type,subject_id,correlation_id,source,metadata_json,occurred_at) VALUES (?,?,?,'system','messenger.reply_interrupted','Messenger',?,?,'messenger-recovery','{}',UTC_TIMESTAMP(6))",[ChatStore::uuid(),...$this->store->scope(),$row['id'],ChatStore::uuid()]);}
        },true);
        $this->media->recover();
    }
}
