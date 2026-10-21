<?php
declare(strict_types=1);
// CLI opt-in and synthetic workspace ownership have already been checked by the caller.
if($container->getByClass(\Espo\Core\Utils\Config::class)->get('nexaMessengerDispatchEnabled')===true)throw new RuntimeException('Messenger acceptance requires real sending to remain disabled.');
$config=new class extends \Espo\Core\Utils\Config {public function __construct(){}public function get(string $name,$default=null){return ['nexaMessengerDispatchEnabled'=>true,'nexaMessagingPublicBaseUrl'=>'https://messenger-acceptance.example/nexa'][$name]??$default;}};
$client=new class extends \Espo\Custom\Tools\Conversation\MessengerClient {public function __construct(){}public function verify(string $page,string $app,string $token,string $secret):void{}public function send(string $page,string $peer,string $body,string $token,string $secret):array{return ['status'=>'accepted','messageId'=>'mid.acceptance.'.bin2hex(random_bytes(8))];}public function sendTracked(string $page,string $peer,string $body,string $nonce,string $token,string $secret,?array $media=null):array{return $this->send($page,$peer,$body,$token,$secret);}};
$acl=$container->getByClass(\Espo\Core\AclManager::class)->createUserAcl($user);$settings=$factory->createWith(\Espo\Custom\Tools\Conversation\MessengerConnectionService::class,['user'=>$user,'acl'=>$acl,'config'=>$config,'client'=>$client]);$service=$factory->createWith(\Espo\Custom\Tools\Conversation\MessengerService::class,['user'=>$user,'acl'=>$acl,'connections'=>$settings,'client'=>$client]);
if($command==='messenger-seed'){
    $execute("UPDATE scheduled_job SET status='Inactive' WHERE tenant_id=? AND service_id=? AND job='RecoverMessengerReplies'",[$tenant['id'],$tenant['serviceId']]);
    $page=(string)random_int(100000000,999999999);$secret=bin2hex(random_bytes(16));$token=bin2hex(random_bytes(24));
    $saved=$settings->process((object)['op'=>'save','name'=>'Synthetic Messenger Page','pageId'=>$page,'appId'=>'999','pageToken'=>bin2hex(random_bytes(32)),'appSecret'=>$secret,'verifyToken'=>$token,'disabled'=>false]);$id=$saved['id'];$webhook=$factory->create(\Espo\Custom\Tools\Conversation\MessengerWebhookService::class);
    $challenge='hub.mode=subscribe&hub.verify_token='.$token.'&hub.challenge=123';$webhook->challenge($id,$challenge);$settings->process((object)['op'=>'verify','id'=>$id,'version'=>1]);$settings->process((object)['op'=>'activate','id'=>$id,'version'=>2,'enabled'=>true]);
    $payload=['object'=>'page','entry'=>[['id'=>$page,'messaging'=>[['sender'=>['id'=>'222'],'recipient'=>['id'=>$page],'timestamp'=>time()*1000,'message'=>['mid'=>'mid.'.bin2hex(random_bytes(10)),'text'=>'Customer asks <img src=x onerror="window.messengerXss=true">']]]]]];$raw=json_encode($payload);$signature='sha256='.hash_hmac('sha256',$raw,$secret);
    return ['connectionId'=>$id,'raw'=>$raw,'signature'=>$signature,'challenge'=>$challenge];
}
$thread=$service->process((object)['op'=>'thread','id'=>$input['rootId']??'']);
if($command==='messenger-enhance'){
    $root=$em->getEntityById('NexaMessagingConversation',$thread['conversation']['id']);$connection=$settings->connection($root->get('connectionId'));[$pageToken,$secret]=$settings->secrets($connection);$page=$connection['sender'];$store=$factory->create(\Espo\Custom\Tools\Conversation\ChatStore::class);
    $reply=$execute('SELECT id,provider_message_id FROM nexa_messenger_reply WHERE tenant_id=? AND service_id=? AND conversation_id=? ORDER BY created_at DESC LIMIT 1',[$tenant['id'],$tenant['serviceId'],$root->getId()])->fetch(PDO::FETCH_ASSOC);if(!$reply)throw new RuntimeException('Synthetic reply unavailable.');
    $at=time()*1000;$events=[['sender'=>['id'=>$page],'recipient'=>['id'=>'222'],'timestamp'=>$at,'message'=>['is_echo'=>true,'mid'=>$reply['provider_message_id'],'metadata'=>'nexa:'.$reply['id']]],['sender'=>['id'=>'222'],'recipient'=>['id'=>$page],'read'=>['watermark'=>$at]]];
    foreach([['mid'=>'mid.browser.media','attachments'=>[['type'=>'image','payload'=>['url'=>'https://scontent.xx.fbcdn.net/fixture.png']]]],['mid'=>'mid.browser.stop','text'=>'STOP'],['mid'=>'mid.browser.start','text'=>'START']] as $i=>$message)$events[]=['sender'=>['id'=>'222'],'recipient'=>['id'=>$page],'timestamp'=>$at+($i+1)*1000,'message'=>$message];
    $raw=json_encode(['object'=>'page','entry'=>[['id'=>$page,'messaging'=>$events]]]);$factory->create(\Espo\Custom\Tools\Conversation\MessengerWebhookService::class)->receive($connection['id'],$raw,'sha256='.hash_hmac('sha256',$raw,$secret));
    $fetcher=new class extends \Espo\Custom\Tools\Conversation\MessengerMediaFetcher {public function fetch(string $url):string{$image=imagecreatetruecolor(2,2);imagefill($image,0,0,imagecolorallocate($image,20,125,112));ob_start();imagepng($image);$bytes=ob_get_clean();imagedestroy($image);return $bytes;}};
    $media=$factory->createWith(\Espo\Custom\Tools\Conversation\MessengerMediaService::class,['fetcher'=>$fetcher,'storageName'=>'EspoUploadDir']);$media->recover();
    return ['ready'=>true];
}
return $service->process((object)['op'=>'reply','id'=>$thread['conversation']['id'],'version'=>$thread['conversation']['version'],'body'=>'Synthetic service response, no real delivery.','requestKey'=>$input['requestKey']??'']);
