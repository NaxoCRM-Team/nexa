<?php
declare(strict_types=1);
// The caller enforces CLI opt-in and uniquely named disposable tenant ownership.
$routing=$factory->createWith(\Espo\Custom\Tools\Conversation\ConversationRoutingService::class,['user'=>$user]);
if($command==='routing-seed'){
    $execute("UPDATE scheduled_job SET status='Inactive' WHERE tenant_id=? AND service_id=? AND job='RouteConversations'",[$tenant['id'],$tenant['serviceId']]);
    $routing->process((object)['op'=>'save','channel'=>'chat','teamId'=>null,'enabled'=>true,'capacity'=>1,'version'=>0]);
    $chat=$factory->createWith(\Espo\Custom\Tools\Conversation\ChatService::class,['user'=>$user,'acl'=>$container->getByClass(\Espo\Core\AclManager::class)->createUserAcl($user)]);
    $widget=$chat->process((object)['op'=>'widgetSave','name'=>'Routing acceptance','title'=>'Help','welcome'=>'Welcome','origins'=>['http://localhost:8099'],'color'=>'#147d70','position'=>'right','draggable'=>true,'enabled'=>true,'privacyUrl'=>'']);
    $store=$factory->create(\Espo\Custom\Tools\Conversation\ChatStore::class);$ids=[];
    for($i=0;$i<2;$i++){
        $root=$em->createEntity('NexaChatConversation',['name'=>'Queued visitor '.($i+1),'status'=>'Open','widgetId'=>$widget['id'],'origin'=>'http://localhost:8099','tokenHash'=>bin2hex(random_bytes(32)),'visitorKeyHash'=>bin2hex(random_bytes(32)),'startNonce'=>$store::uuid(),'expiresAt'=>gmdate('Y-m-d H:i:s',time()+3600),'handoffRequestedAt'=>gmdate('Y-m-d H:i:s')]);$ids[]=$root->getId();
    }
    return ['ids'=>$ids];
}
if($command==='routing-run')return ['assigned'=>$factory->create(\Espo\Custom\Tools\Conversation\ConversationRoutingService::class)->run()];
return ['list'=>$execute('SELECT id,assigned_user_id AS assignedUserId,routing_state AS routingState,handoff_answered_at AS answeredAt FROM nexa_chat_conversation WHERE tenant_id=? AND service_id=? AND deleted=0 ORDER BY id',[$tenant['id'],$tenant['serviceId']])->fetchAll(PDO::FETCH_ASSOC),
    'assignments'=>(int)$execute("SELECT COUNT(*) FROM nexa_audit_event WHERE tenant_id=? AND service_id=? AND action='routing.assigned'",[$tenant['id'],$tenant['serviceId']])->fetchColumn()];
