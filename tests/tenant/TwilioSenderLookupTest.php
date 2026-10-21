<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/espocrm/bootstrap.php';
use Espo\Core\Exceptions\{BadRequest,Forbidden};
use Espo\Custom\Tools\Conversation\TwilioMessagingLookup;

$account='AC'.str_repeat('1',32);$phone='PN'.str_repeat('2',32);$secret=str_repeat('3',32);$sender='+14155550100';$checks=0;
$assert=function(bool $ok,string $why)use(&$checks):void{$checks++;if(!$ok)throw new RuntimeException($why);};
$lookup=new class extends TwilioMessagingLookup {
    public array $account=[];public array $numbers=[];public array $paths=[];
    protected function fetch(string $account,string $secret,string $path): array {$this->paths[]=$path;return str_contains($path,'IncomingPhoneNumbers')?['incoming_phone_numbers'=>$this->numbers]:$this->account;}
};
$lookup->account=['sid'=>$account,'status'=>'active'];$valid=['sid'=>$phone,'account_sid'=>$account,'phone_number'=>$sender,'capabilities'=>['sms'=>true]];$lookup->numbers=[$valid];
$assert($lookup->proveSms($account,$secret,$sender)===['phoneSid'=>$phone],'An exact active account and SMS-capable number produce scoped evidence.');
$assert(count($lookup->paths)===2&&str_contains($lookup->paths[1],'PhoneNumber=%2B14155550100')&&str_contains($lookup->paths[1],'PageSize=2'),'Only account and bounded phone-number GET paths are requested.');
$deny=function(callable $fn,string $why)use($assert):void{try{$fn();}catch(BadRequest|Forbidden $error){$assert(true,$why);return;}$assert(false,$why);};
$lookup->account['status']='suspended';$deny(fn()=>$lookup->proveSms($account,$secret,$sender),'Suspended accounts are rejected.');
$lookup->account=['sid'=>'AC'.str_repeat('9',32),'status'=>'active'];$deny(fn()=>$lookup->proveSms($account,$secret,$sender),'Provider account identity must match.');$lookup->account=['sid'=>$account,'status'=>'active'];
foreach ([[],[['sid'=>$phone,'account_sid'=>$account,'phone_number'=>'+14155550199','capabilities'=>['sms'=>true]]],[array_replace($valid,['capabilities'=>['sms'=>false]])],[array_replace($valid,['account_sid'=>'AC'.str_repeat('9',32)])],[array_replace($valid,['sid'=>'not-a-number'])],[array_replace($valid,['capabilities'=>['sms'=>'true']])]] as $numbers) {
    $lookup->numbers=$numbers;$deny(fn()=>$lookup->proveSms($account,$secret,$sender),'Missing, mismatched or unsupported numbers cannot verify.');
}
$lookup->numbers=[$valid];$before=count($lookup->paths);
$deny(fn()=>$lookup->proveSms('../bad',$secret,$sender),'Account paths cannot be injected.');
$deny(fn()=>$lookup->proveSms($account,"bad\r\nsecret",$sender),'Invalid credentials cannot reach the transport.');
$deny(fn()=>$lookup->proveSms($account,$secret,'whatsapp:'.$sender),'SMS proof cannot verify a WhatsApp sender.');
$assert(count($lookup->paths)===$before,'Invalid settings fail before network I/O.');
echo "SMS sender lookup checks passed: $checks (fake transport; no provider calls).\n";
