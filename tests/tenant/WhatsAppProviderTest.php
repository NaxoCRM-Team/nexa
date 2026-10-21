<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/espocrm/bootstrap.php';
use Espo\Core\Exceptions\{BadRequest,Forbidden};
use Espo\Custom\Tools\Conversation\{TwilioMessagingLookup,WhatsAppMessagePolicy,WhatsAppTemplateLookup,WhatsAppSessionLookup};
$checks=0;$assert=function(bool $ok,string $why)use(&$checks){$checks++;if(!$ok)throw new RuntimeException($why);};
$deny=function(callable $fn,string $why)use($assert){try{$fn();}catch(BadRequest|Forbidden $e){$assert(true,$why);return;}$assert(false,$why);};
$account='AC'.str_repeat('a',32);$secret=str_repeat('b',32);$sid='XE'.str_repeat('c',32);$sender='whatsapp:+14155550100';
$lookup=new class extends TwilioMessagingLookup {
    public array $sender=[];public array $paths=[];public string $status='active';
    protected function fetchAt(string $host,string $account,string $secret,string $path):array{$this->paths[]=[$host,$path];return $host==='api.twilio.com'?['sid'=>$account,'status'=>$this->status]:$this->sender;}
};
$lookup->sender=['sid'=>$sid,'sender_id'=>$sender,'status'=>'ONLINE'];
$assert($lookup->proveWhatsApp($account,$secret,$sender,$sid)===['phoneSid'=>$sid],'Only exact ONLINE sender proof verifies.');
$assert($lookup->paths[1]===['messaging.twilio.com','/v2/Channels/Senders/'.$sid],'Sender inspection uses fixed-host v2 API, not deprecated v1.');
foreach(['OFFLINE','TWILIO_REVIEW','PENDING_VERIFICATION','VERIFYING','ONLINE:UPDATING','DRAFT','STUBBED'] as $status){$lookup->sender['status']=$status;$deny(fn()=>$lookup->proveWhatsApp($account,$secret,$sender,$sid),'Non-ONLINE sender cannot verify.');}
$lookup->sender=['sid'=>$sid,'sender_id'=>$sender,'status'=>'ONLINE'];
$lookup->status='suspended';$deny(fn()=>$lookup->proveWhatsApp($account,$secret,$sender,$sid),'Suspended account cannot verify.');$lookup->status='active';
$lookup->sender['sender_id']='whatsapp:+14155550199';$deny(fn()=>$lookup->proveWhatsApp($account,$secret,$sender,$sid),'A different sender cannot provide proof.');
$lookup->sender=['sid'=>$sid,'senderId'=>$sender,'status'=>'ONLINE'];$deny(fn()=>$lookup->proveWhatsApp($account,$secret,$sender,$sid),'SDK camelCase is not accepted as a raw HTTP response contract.');
$before=count($lookup->paths);$deny(fn()=>$lookup->proveWhatsApp($account,$secret,$sender,'../bad'),'Path injection is rejected before transport.');$assert(count($lookup->paths)===$before,'Invalid sender SID never reaches provider.');
$hx='HX'.str_repeat('d',32);$content=['sid'=>$hx,'account_sid'=>$account,'language'=>'en_GB','types'=>['twilio/text'=>['body'=>'Hello {{1}}, your order {{2}} is ready.']]];
$approval=['sid'=>$hx,'account_sid'=>$account,'whatsapp'=>['status'=>'approved','category'=>'UTILITY','name'=>'order_ready']];$variables=['1'=>'Ada','2'=>'123'];
$rendered=WhatsAppMessagePolicy::approvedText($content,$approval,$account,$hx,$variables);$assert($rendered['body']==='Hello Ada, your order 123 is ready.','Only values, never approved template text, are substituted.');
foreach(['pending','rejected','paused','disabled','deleted'] as $status){$changed=$approval;$changed['whatsapp']['status']=$status;$deny(fn()=>WhatsAppMessagePolicy::approvedText($content,$changed,$account,$hx,$variables),'Unapproved or disabled template rejected.');}
$changed=$approval;$changed['account_sid']='AC'.str_repeat('e',32);$deny(fn()=>WhatsAppMessagePolicy::approvedText($content,$changed,$account,$hx,$variables),'Foreign account approval rejected.');
foreach([[],['1'=>'Ada'],['1'=>'Ada','2'=>'123','3'=>'extra'],['1'=>"Ada\nOther",'2'=>'123'],['1'=>'{{2}}','2'=>'123'],['1'=>['nested'],'2'=>'123']] as $values)$deny(fn()=>WhatsAppMessagePolicy::approvedText($content,$approval,$account,$hx,$values),'Missing, extra, nested and unsafe variable values rejected.');
$changed=$approval;$changed['whatsapp']['category']='AUTHENTICATION';$deny(fn()=>WhatsAppMessagePolicy::approvedText($content,$changed,$account,$hx,$variables),'Authentication templates require a separate purpose-specific workflow.');
$changed=$content;$changed['types']['twilio/media']=['body'=>'x'];$deny(fn()=>WhatsAppMessagePolicy::approvedText($changed,$approval,$account,$hx,$variables),'Unsupported rich templates fail closed.');
$now=new DateTimeImmutable('2026-10-18T12:00:00Z');
$assert(WhatsAppMessagePolicy::sessionOpen($now->modify('-1 hour'),$now),'Verified recent inbound evidence opens a session.');
foreach([null,$now->modify('+1 second'),$now->modify('-24 hours'),$now->modify('-86340 seconds')] as $at)$assert(!WhatsAppMessagePolicy::sessionOpen($at,$now),'Missing, future and expired evidence cannot open or extend a session.');
$templates=new class extends WhatsAppTemplateLookup {public array $content=[];public array $approval=[];public array $paths=[];protected function fetchAt(string $host,string $account,string $secret,string $path):array{$this->paths[]=[$host,$path];return str_ends_with($path,'/ApprovalRequests')?$this->approval:$this->content;}};
$templates->content=$content;$templates->approval=$approval;$template=$templates->inspect($account,$secret,$hx);
$assert($template['variableKeys']===['1','2']&&$template['body']===$content['types']['twilio/text']['body'],'Inspection preserves approved text and exposes required variable keys.');
$assert($templates->paths===[['content.twilio.com','/v1/Content/'.$hx],['content.twilio.com','/v1/Content/'.$hx.'/ApprovalRequests']],'Content and approval use fixed read-only paths, never provider links.');
$deny(fn()=>$templates->inspect($account,$secret,'https://elsewhere.example'),'Arbitrary template URLs cannot reach transport.');
$sessions=new class extends WhatsAppSessionLookup {public array $record=[];protected function fetchAt(string $host,string $account,string $secret,string $path):array{return $this->record;}};
$message='SM'.str_repeat('f',32);$peer='whatsapp:+14155550101';$valid=['sid'=>$message,'account_sid'=>$account,'from'=>$peer,'to'=>$sender,'direction'=>'inbound','status'=>'received','date_created'=>$now->modify('-1 hour')->format(DATE_RFC2822)];$sessions->record=$valid;
$assert($sessions->inspect($account,$secret,$sender,$peer,$message,$now)['open'],'Exact provider inbound message supplies trusted session time.');
foreach(['account_sid'=>'AC'.str_repeat('9',32),'from'=>$sender,'to'=>$peer,'direction'=>'outbound-api','status'=>'sent','date_created'=>'now'] as $key=>$value){$sessions->record=[$key=>$value]+$valid;$deny(fn()=>$sessions->inspect($account,$secret,$sender,$peer,$message,$now),'Foreign, outgoing or unparseable provider records do not prove a session.');}
$sessions->record=['date_created'=>$now->modify('-2 days')->format(DATE_RFC2822)]+$valid;
$assert(!$sessions->inspect($account,$secret,$sender,$peer,$message,$now)['open'],'Delayed delivery of an old inbound callback cannot reopen the window.');
$transport=new class extends \Espo\Custom\Tools\Conversation\TwilioSmsTransport {
    public array $response=[];public array $fields=[];
    protected function request(string $method,string $url,string $account,string $secret,?string $body=null):array{parse_str($body??'',$this->fields);return $this->response;}
};
$connection=['channel'=>'whatsapp','account_sid'=>$account,'sender'=>$sender];$message=['recipient'=>$peer,'body'=>'Hello Ada','contentSid'=>$hx,'variables'=>['1'=>'Ada']];
$transport->response=['status'=>201,'data'=>['sid'=>$messageSid='SM'.str_repeat('1',32),'account_sid'=>$account,'from'=>$sender,'to'=>$peer,'body'=>'Hello Ada','status'=>'queued']];
$assert($transport->send($connection,$secret,$message,'https://nexa.example/callback')['outcome']==='accepted','WhatsApp transport binds exact account, sender, recipient and rendered body.');
$assert(!isset($transport->fields['Body'])&&$transport->fields['ContentSid']===$hx&&json_decode($transport->fields['ContentVariables'],true)===['1'=>'Ada'],'Templates use ContentSid and ContentVariables, never a Body substitute.');
$transport->response['data']['to']='whatsapp:+14155550109';$assert($transport->send($connection,$secret,$message,'https://nexa.example/callback')['outcome']==='uncertain','Wrong-recipient provider response cannot bind acceptance.');
foreach([429=>'retry',500=>'uncertain',0=>'uncertain',403=>'failed'] as $code=>$outcome){$transport->response=['status'=>$code,'data'=>null];$assert($transport->send($connection,$secret,$message,'https://nexa.example/callback')['outcome']===$outcome,'Only a definite rate-limit rejection permits retry.');}
echo "WhatsApp provider/policy checks passed: {$checks} (fake HTTP; no delivery).\n";
