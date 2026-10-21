<?php
declare(strict_types=1);
namespace Espo\Custom\Tools\Conversation;
use Espo\Core\Utils\Config;
use Espo\Core\Exceptions\{BadRequest,Forbidden};

class MessengerClient
{
    public function __construct(private Config $config) {}
    public function verify(string $page,string $app,#[\SensitiveParameter] string $token,#[\SensitiveParameter] string $secret): void
    {
        MessengerContract::id($page);MessengerContract::id($app);
        $record=$this->request('GET','me?fields=id,name',$token,$secret);
        if($record['status']!==200||($record['data']['id']??null)!==$page)throw new Forbidden('Meta could not verify this Page access token.');
        $debug=$this->request('GET','debug_token?input_token='.rawurlencode($token),$app.'|'.$secret,$secret);$data=$debug['data']['data']??null;
        if($debug['status']!==200||!is_array($data)||($data['is_valid']??null)!==true||($data['app_id']??null)!==$app||!in_array('pages_messaging',$data['scopes']??[],true)||!is_int($data['expires_at']??null)||($data['expires_at']!==0&&$data['expires_at']<=time())||(isset($data['profile_id'])&&$data['profile_id']!==$page)||(isset($data['data_access_expires_at'])&&(!is_int($data['data_access_expires_at'])||($data['data_access_expires_at']!==0&&$data['data_access_expires_at']<=time()))))throw new Forbidden('The Page token is expired, missing messaging permission or belongs to another app.');
        $apps=$this->request('GET',$page.'/subscribed_apps?fields=id,subscribed_fields&limit=100',$token,$secret);
        if($apps['status']!==200||!is_array($apps['data']['data']??null))throw new Forbidden('The Page app subscription could not be verified.');
        foreach($apps['data']['data'] as $item)if(($item['id']??null)===$app&&is_array($item['subscribed_fields']??null)&&!array_diff(['messages','message_echoes','message_deliveries','message_reads'],$item['subscribed_fields']))return;
        throw new Forbidden('Subscribe this Meta app to Page messages, echoes, deliveries and reads before verifying.');
    }
    public function send(string $page,string $peer,string $body,#[\SensitiveParameter] string $token,#[\SensitiveParameter] string $secret): array
    {
        MessengerContract::id($page);MessengerContract::id($peer);MessengerContract::text($body);
        $r=$this->request('POST',$page.'/messages',$token,$secret,['recipient'=>['id'=>$peer],'messaging_type'=>'RESPONSE','message'=>['text'=>$body]]);
        $id=$r['data']['message_id']??null;
        if($r['status']===200&&($r['data']['recipient_id']??null)===$peer&&is_string($id)&&preg_match('/^[A-Za-z0-9_.:\-]{1,255}$/D',$id))return ['status'=>'accepted','messageId'=>$id];
        return ['status'=>in_array($r['status'],[400,401,403,404,429],true)?'failed':'uncertain'];
    }
    protected function request(string $method,#[\SensitiveParameter] string $path,#[\SensitiveParameter] string $token,#[\SensitiveParameter] string $secret,?array $body=null): array
    {
        $version=$this->config->get('nexaMessengerGraphVersion');
        if(!is_string($version)||!preg_match('/^v[0-9]{2}\.0$/D',$version))throw new BadRequest('The operator must configure the Messenger Graph API version.');
        if(!function_exists('curl_init'))throw new BadRequest('Messenger requires server cURL.');
        $url='https://graph.facebook.com/'.$version.'/'.$path.(str_contains($path,'?')?'&':'?').'appsecret_proof='.hash_hmac('sha256',$token,$secret);
        $curl=curl_init($url);$raw='';if(!$curl)return ['status'=>0,'data'=>null];
        $multipart=isset($body['filedata']);$headers=['Authorization: Bearer '.$token,'Accept: application/json'];if(!$multipart)$headers[]='Content-Type: application/json';
        curl_setopt_array($curl,[CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>20,CURLOPT_HTTPHEADER=>$headers,CURLOPT_WRITEFUNCTION=>static function($c,string $chunk)use(&$raw):int{if(strlen($raw)+strlen($chunk)>262144)return 0;$raw.=$chunk;return strlen($chunk);}]);
        if($method==='POST')curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$multipart?$body:json_encode($body,JSON_THROW_ON_ERROR)]);
        try{$ok=curl_exec($curl);$status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);$data=null;try{$data=json_decode($raw,true,32,JSON_THROW_ON_ERROR);}catch(\JsonException $e){}return ['status'=>$ok===false?0:$status,'data'=>$data];}finally{curl_close($curl);}
    }
    public function sendTracked(string $page,string $peer,string $body,string $nonce,#[\SensitiveParameter] string $token,#[\SensitiveParameter] string $secret,?array $media=null): array
    {
        MessengerContract::id($page);MessengerContract::id($peer);ChatStore::uuidValue($nonce);
        if($media){
            $info=MessengerMediaFetcher::inspect($media['bytes'],$media['kind']);
            $fields=['recipient'=>json_encode(['id'=>$peer],JSON_THROW_ON_ERROR),'messaging_type'=>'RESPONSE','message'=>json_encode(['metadata'=>'nexa:'.$nonce,'attachment'=>['type'=>$info['kind'],'payload'=>(object)[]]],JSON_THROW_ON_ERROR),'filedata'=>new \CURLStringFile($media['bytes'],$info['name'],$info['mimeType'])];
        }else{MessengerContract::text($body);$fields=['recipient'=>['id'=>$peer],'messaging_type'=>'RESPONSE','message'=>['text'=>$body,'metadata'=>'nexa:'.$nonce]];}
        $r=$this->request('POST',$page.'/messages',$token,$secret,$fields);$id=$r['data']['message_id']??null;
        if($r['status']===200&&($r['data']['recipient_id']??null)===$peer&&is_string($id)&&preg_match('/^[A-Za-z0-9_.:\-]{1,255}$/D',$id))return ['status'=>'accepted','messageId'=>$id];
        return ['status'=>in_array($r['status'],[400,401,403,404,429],true)?'failed':'uncertain'];
    }
}
