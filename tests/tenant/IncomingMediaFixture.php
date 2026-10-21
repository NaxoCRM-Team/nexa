<?php
declare(strict_types=1);
// Only called by local acceptance fixtures; no transport or provider verification calls.
return static function($em,$factory,$container,$user,string $channel):array{
    $store=$factory->create(\Espo\Custom\Tools\Conversation\ChatStore::class);$scope=$store->scope();
    $cipher=$factory->create(\Espo\Custom\Tools\Auth\ProviderSecretCipher::class);
    $id=\Espo\Custom\Tools\Conversation\ChatStore::uuid();$account='AC'.bin2hex(random_bytes(16));$message='MM'.bin2hex(random_bytes(16));$secret=bin2hex(random_bytes(16));
    $sender=($channel==='whatsapp'?'whatsapp:':'').'+1415'.random_int(1000000,9999999);$proof=($channel==='whatsapp'?'XE':'PN').bin2hex(random_bytes(16));
    $store->execute("INSERT INTO nexa_messaging_connection (id,tenant_id,service_id,name,channel,account_sid,sender,secret_encrypted,status,created_by_id,verified_at,provider_resource_sid) VALUES (?,?,?,'Incoming file acceptance',?,?,?,?,'verified',?,UTC_TIMESTAMP(),?)",[$id,...$scope,$channel,$account,$sender,$cipher->encryptFor($secret,...[...$scope,'messaging:'.$id]),$user->getId(),$proof]);
    $url='https://api.twilio.com/2010-04-01/Accounts/'.$account.'/Messages/'.$message.'/Media/ME'.bin2hex(random_bytes(16));
    $params=['AccountSid'=>$account,'MessageSid'=>$message,'From'=>($channel==='whatsapp'?'whatsapp:':'').'+14155550101','To'=>$sender,'Body'=>'Private received image','NumMedia'=>'1','MediaUrl0'=>$url,'MediaContentType0'=>'image/png'];
    $config=new class extends \Espo\Core\Utils\Config{public function __construct(){}public function get(string $name,$default=null){return $name==='nexaMessagingPublicBaseUrl'?'https://media-acceptance.example/nexa':$default;}};
    $callbacks=$factory->createWith(\Espo\Custom\Tools\Conversation\MessagingCallbackService::class,['config'=>$config]);ksort($params,SORT_STRING);$signed=$callbacks->callbackUrl($id,'inbound');foreach($params as $key=>$value)$signed.=$key.$value;
    $signature=base64_encode(hash_hmac('sha1',$signed,$secret,true));$body=http_build_query($params);
    if($callbacks->process($id,'inbound',$body,'application/x-www-form-urlencoded',$signature)['duplicate'])throw new RuntimeException('New receipt was duplicated.');
    if(!$callbacks->process($id,'inbound',$body,'application/x-www-form-urlencoded',$signature)['duplicate'])throw new RuntimeException('Receipt replay was not deduplicated.');
    $factory->create(\Espo\Custom\Tools\Conversation\MessagingConversationProjector::class)->recover();
    $row=$store->rows('SELECT m.id,m.receipt_id,m.conversation_id FROM nexa_messaging_media m JOIN nexa_messaging_receipt r ON r.id=m.receipt_id WHERE m.tenant_id=? AND m.service_id=? AND r.connection_id=?',[...$scope,$id])[0]??null;
    if(!$row)throw new RuntimeException('Incoming file was not indexed.');
    return ['mediaId'=>$row['id'],'receiptId'=>$row['receipt_id'],'rootId'=>$row['conversation_id'],'connectionId'=>$id,'url'=>$url,'account'=>$account,'message'=>$message];
};
