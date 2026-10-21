<?php
declare(strict_types=1);
// The caller verifies CLI opt-in and disposable tenant ownership.
if($command==='privacy-seed'){
    $execute("UPDATE scheduled_job SET status='Inactive' WHERE tenant_id=? AND service_id=? AND job IN ('PurgeChatHistory','RouteConversations')",[$tenant['id'],$tenant['serviceId']]);
    $chat=$factory->createWith(\Espo\Custom\Tools\Conversation\ChatService::class,['user'=>$user,'acl'=>$container->getByClass(\Espo\Core\AclManager::class)->createUserAcl($user)]);
    $widget=$chat->process((object)['op'=>'widgetSave','name'=>'Privacy test website','title'=>'Support','welcome'=>'Welcome','origins'=>['http://127.0.0.1:8099'],'color'=>'#147d70','position'=>'right','draggable'=>true,'enabled'=>true,'privacyUrl'=>'']);
    return ['publicKey'=>$execute('SELECT public_key FROM nexa_chat_widget WHERE tenant_id=? AND service_id=? AND id=?',[$tenant['id'],$tenant['serviceId'],$widget['id']])->fetchColumn()];
}
$id=$input['rootId']??'';if(!is_string($id)||!preg_match('/^[A-Za-z0-9]{1,17}$/D',$id))throw new RuntimeException('Invalid synthetic chat ID.');
return ['roots'=>(int)$execute('SELECT COUNT(*) FROM nexa_chat_conversation WHERE tenant_id=? AND service_id=? AND id=?',[$tenant['id'],$tenant['serviceId'],$id])->fetchColumn(),
    'messages'=>(int)$execute('SELECT COUNT(*) FROM nexa_chat_message WHERE tenant_id=? AND service_id=? AND conversation_id=?',[$tenant['id'],$tenant['serviceId'],$id])->fetchColumn(),
    'completed'=>(bool)$execute('SELECT completed_at FROM nexa_chat_erasure WHERE tenant_id=? AND service_id=? AND conversation_id=?',[$tenant['id'],$tenant['serviceId'],$id])->fetchColumn()];
