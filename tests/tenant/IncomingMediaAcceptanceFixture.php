<?php
declare(strict_types=1);
// The CLI wrapper enforces unique disposable tenants and explicit local opt-in.
if($command==='incoming-media-seed'){
    $make=require __DIR__.'/IncomingMediaFixture.php';$result=[];
    foreach(['sms','whatsapp'] as $channel){$f=$make($em,$factory,$container,$user,$channel);$result[$channel]=array_intersect_key($f,array_flip(['rootId','mediaId','receiptId']));}
    return $result;
}
$fetcher=new class extends \Espo\Custom\Tools\Conversation\TwilioMediaFetcher{
    public int $calls=0;
    public function fetch(string $url,string $account,string $message,string $secret):string{
        self::url($url,$account,$message);$this->calls++;$image=imagecreatetruecolor(64,40);imagefill($image,0,0,imagecolorallocate($image,20,125,112));ob_start();imagepng($image);$bytes=ob_get_clean();imagedestroy($image);return $bytes;
    }
};
$media=$factory->createWith(\Espo\Custom\Tools\Conversation\IncomingMediaService::class,['fetcher'=>$fetcher,'storageName'=>'EspoUploadDir']);
$factory->createWith(\Espo\Custom\Jobs\RecoverMessagingConversations::class,['media'=>$media])->run();
return ['fetched'=>$fetcher->calls,'ready'=>(int)$execute("SELECT COUNT(*) FROM nexa_messaging_media WHERE tenant_id=? AND service_id=? AND status='ready'",[$tenant['id'],$tenant['serviceId']])->fetchColumn()];
