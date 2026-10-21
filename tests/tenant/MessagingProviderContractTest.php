<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/espocrm/bootstrap.php';

use Espo\Core\Exceptions\{BadRequest,Forbidden};
use Espo\Core\Utils\Config;
use Espo\Custom\Tools\Call\TwilioClient;
use Espo\Custom\Tools\Conversation\{TwilioWebhookSignature,TwilioMessagingAdapter,MessagingDeliveryState};

$checks=0;
$assert=function(bool $value,string $message)use(&$checks):void{$checks++;if(!$value)throw new RuntimeException($message);};
$deny=function(callable $fn,string $message)use($assert):void{try{$fn();}catch(BadRequest|Forbidden $error){$assert(true,$message);return;}$assert(false,$message);};
// Published illustrative vector, not a real credential: twilio.com/docs/usage/security.
$url='https://example.com/myapp.php?foo=1&bar=2';
$params=['CallSid'=>'CA1234567890ABCDE','Caller'=>'+14158675310','Digits'=>'1234','From'=>'+14158675310','To'=>'+18005551212'];
$signature='L/OH5YylLD5NRKLltdqwSvS0BnU=';
$assert(TwilioWebhookSignature::verify($url,$params,$signature,'12345'),'Official Twilio signature vector matches.');
$assert(TwilioWebhookSignature::verify($url,array_reverse($params,true),$signature,'12345'),'Form key order is not signature identity.');
$assert(!TwilioWebhookSignature::verify($url.'x',$params,$signature,'12345'),'Exact callback URL is signed.');
$assert(!TwilioWebhookSignature::verify($url,$params,$signature,''),'An empty connection secret fails closed.');
$assert(!TwilioWebhookSignature::verify($url,$params,$signature,'another-secret'),'A different connection secret fails.');
$assert(!TwilioWebhookSignature::verify($url,$params+['FutureField'=>'new'],$signature,'12345'),'New provider fields must be included in verification.');
$assert(!TwilioWebhookSignature::verify($url,$params+['Nested'=>['bad']],$signature,'12345'),'Non-flat form input is rejected.');
$assert(!TwilioWebhookSignature::verify($url,$params,$signature."\n",'12345'),'Malformed header is rejected.');
$old=getenv('TWILIO_AUTH_TOKEN');putenv('TWILIO_AUTH_TOKEN');
try {
    $config=new class extends Config {public function __construct(){} public function get(string $name,$default=null){return $name==='nexaTwilioAuthToken'?'12345':$default;}};
    $voice=new TwilioClient($config);
    $assert($voice->verifySignature($url,$params,$signature),'Existing Voice verifier retains the official signature behavior.');
    $assert(!$voice->verifySignature($url,$params,''),'Existing Voice verifier rejects missing headers.');
} finally {if($old===false)putenv('TWILIO_AUTH_TOKEN');else putenv('TWILIO_AUTH_TOKEN='.$old);}

$adapter=new TwilioMessagingAdapter();$account='AC'.str_repeat('1',32);$id='SM'.str_repeat('2',32);
$sender='+14155550100';$recipient='+14155550101';$callback='https://tenant.example/nexa/api/provider/connection-1/status';$secret='fixture-only-connection-secret';
$sign=function(array $data,?string $target=null)use($secret,$callback):string{ksort($data,SORT_STRING);$source=$target??$callback;foreach($data as $key=>$value)$source.=$key.$value;return base64_encode(hash_hmac('sha1',$source,$secret,true));};
$inbound=['AccountSid'=>$account,'MessageSid'=>$id,'From'=>$recipient,'To'=>$sender,'Body'=>' Keep whitespace & + signs ','NumMedia'=>'0','FutureField'=>'forward compatible'];
$receive=fn(array $data,string $channel='sms',?string $boundSender=null,string $kind='inbound')=>$adapter->webhook($callback,$data,$sign($data),$secret,$account,$channel,$boundSender??$sender,$kind);
$message=$receive($inbound);
$assert($message['body']===$inbound['Body']&&$message['kind']==='inbound','Verified incoming text is normalized without trimming signed content.');
$assert(!isset($message['tenantId'])&&!isset($message['contactId']),'Provider payload does not invent tenant or CRM identity.');
foreach(['STOP','START','HELP'] as $preference){$data=$inbound;$data['OptOutType']=$preference;$assert($receive($data)['optOutType']===$preference,'Signed provider preference is retained.');}
$bad=$inbound;$bad['OptOutType']='UNKNOWN';$deny(fn()=>$receive($bad),'Unknown signed preference values fail closed.');
$tampered=$inbound;$tampered['OptOutType']='START';$deny(fn()=>$adapter->webhook($callback,$tampered,$sign($inbound),$secret,$account,'sms',$sender,'inbound'),'Unsigned opt-in metadata cannot be appended to an otherwise valid callback.');
foreach([' stop ','UNSUBSCRIBE','cancel'] as $word)$assert(\Espo\Custom\Tools\Conversation\SmsPreference::type(['kind'=>'inbound','channel'=>'sms','body'=>$word])==='STOP','Exact opt-out fallback blocks conservatively.');
$assert(\Espo\Custom\Tools\Conversation\SmsPreference::type(['kind'=>'inbound','channel'=>'sms','body'=>'please do not stop here'])===null,'Ordinary message text is not treated as an opt-out keyword.');
$assert(\Espo\Custom\Tools\Conversation\SmsPreference::type(['kind'=>'inbound','channel'=>'sms','body'=>'START'])===null,'Raw start text cannot confer consent or clear suppression.');
$deny(fn()=>$adapter->webhook($callback,$inbound,'bad',$secret,$account,'sms',$sender,'inbound'),'Invalid signatures cannot create incoming events.');
$bad=$inbound;$bad['AccountSid']='AC'.str_repeat('3',32);$deny(fn()=>$receive($bad),'Another account is rejected even with a valid signature.');
$bad=$inbound;$bad['To']=$recipient;$deny(fn()=>$receive($bad),'Another sender destination is rejected.');
$bad=$inbound;$bad['MessageSid']='not-a-message';$deny(fn()=>$receive($bad),'Invalid provider message identifiers are rejected.');
$bad=$inbound;$bad['SmsSid']='SM'.str_repeat('3',32);$deny(fn()=>$receive($bad),'Conflicting legacy message IDs are rejected.');
$bad=$inbound;$bad['Body']='';$deny(fn()=>$receive($bad),'Empty text without media is rejected.');
$bad=$inbound;$bad['Body']=str_repeat('x',1601);$deny(fn()=>$receive($bad),'Oversized text is rejected.');
$bad=$inbound;$bad['Body']="\xff";$deny(fn()=>$receive($bad),'Invalid UTF-8 message text is rejected without an encoding failure.');
$bad=$inbound;$bad['FutureField']=str_repeat('x',65536);$deny(fn()=>$receive($bad),'Total signed parameter bytes are bounded.');
$bad=$inbound;$bad['From']='whatsapp:'.$recipient;$deny(fn()=>$receive($bad),'Channels cannot be mixed.');
$wa=$inbound;$wa['From']='whatsapp:'.$recipient;$wa['To']='whatsapp:'.$sender;
$assert($receive($wa,'whatsapp','whatsapp:'.$sender)['channel']==='whatsapp','WhatsApp channel addresses normalize separately.');
$media=$inbound;$media['Body']='';$media['NumMedia']='1';$media['MediaUrl0']='https://api.twilio.com/2010-04-01/Accounts/'.$account.'/Messages/'.$id.'/Media/ME'.str_repeat('4',32);$media['MediaContentType0']='image/png';
$assert(count($receive($media)['media'])===1,'Media-only messages retain validated references without fetching.');
$bad=$media;$bad['MediaUrl0']='https://127.0.0.1/private';$deny(fn()=>$receive($bad),'Arbitrary media hosts are rejected.');
$bad=$media;$bad['MediaUrl0']=str_replace($account,'AC'.str_repeat('9',32),$media['MediaUrl0']);$deny(fn()=>$receive($bad),'Media must belong to the bound account and message.');
$bad=$media;$bad['NumMedia']='11';$deny(fn()=>$receive($bad),'Media references are bounded.');
$status=['AccountSid'=>$account,'MessageSid'=>$id,'MessageStatus'=>'delivered','From'=>$sender,'To'=>$recipient];
$assert($receive($status,'sms',$sender,'status')['status']==='delivered','Verified delivery callbacks normalize without guessing delivery.');
$bad=$status;$bad['MessageStatus']='read';$deny(fn()=>$receive($bad,'sms',$sender,'status'),'SMS cannot claim a WhatsApp read receipt.');
$waStatus=$status;$waStatus['From']='whatsapp:'.$sender;$waStatus['To']='whatsapp:'.$recipient;$waStatus['MessageStatus']='read';
$assert($receive($waStatus,'whatsapp','whatsapp:'.$sender,'status')['status']==='read','WhatsApp read receipts are recognized.');
$bad=$status;$bad['SmsStatus']='failed';$deny(fn()=>$receive($bad,'sms',$sender,'status'),'Conflicting status aliases are rejected.');
$request=$adapter->outbound($account,'sms',$sender,$recipient,'Hello & welcome + test',$callback);parse_str($request['body'],$out);
$assert($out['Body']==='Hello & welcome + test'&&$out['StatusCallback']===$callback,'Outbound form encoding round-trips text and canonical callback.');
$assert($request['url']==='https://api.twilio.com/2010-04-01/Accounts/'.$account.'/Messages.json','Provider request uses a fixed API host.');
$assert(!str_contains(json_encode($request),$secret),'Transport payloads do not expose connection secrets.');
$deny(fn()=>$adapter->outbound($account,'sms',$sender,$recipient,'Hello','http://tenant.example/callback'),'Outbound callbacks require HTTPS.');
$deny(fn()=>$adapter->outbound($account,'sms',$sender,$recipient,'Hello','https://user:pass@tenant.example/callback'),'Callback credentials are rejected.');
$deny(fn()=>$adapter->outbound($account,'whatsapp',$sender,$recipient,'Hello',$callback),'Outbound WhatsApp requires channel-specific addresses.');
foreach ([['sent','queued','sent'],['delivered','failed','delivered'],['read','delivered','read'],['failed','queued','failed'],['sent','undelivered','undelivered'],['failed','delivered','delivered'],['accepted','sent','sent'],['delivered','read','read']] as [$current,$incoming,$expected]) {
    $assert(MessagingDeliveryState::reconcile($current,$incoming)===$expected,'Delivery reconciliation preserves stronger evidence.');
}
$deny(fn()=>MessagingDeliveryState::reconcile('made-up','sent'),'Unknown delivery states are rejected.');
echo "Messaging provider contract checks passed: $checks (no network or database writes).\n";
