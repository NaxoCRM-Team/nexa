<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/espocrm/bootstrap.php';
use Espo\Custom\Tools\MarketingEmail\Delivery\PostalInspectionEvidence;
use Espo\Core\Exceptions\BadRequest;

$data=['id'=>42,'details'=>['direction'=>'outgoing','rcpt_to'=>'recipient@example.test','mail_from'=>'sender@example.test','message_id'=>'unique-message@example.test'],
    'headers'=>['X-Nexa-Queue-Item'=>['queue-42']]];
$proof=PostalInspectionEvidence::verify($data,42,'queue-42','recipient@example.test','sender@example.test');
if(strlen($proof['evidenceHash'])!==64||$proof['providerMessageId']!==$data['details']['message_id'])throw new RuntimeException('Verified Postal evidence is incomplete.');
foreach([
    ['id'=>43],
    ['details'=>['direction'=>'incoming',...array_diff_key($data['details'],['direction'=>true])]],
    ['details'=>[...$data['details'],'rcpt_to'=>'another@example.test']],
    ['details'=>[...$data['details'],'mail_from'=>'another@example.test']],
    ['details'=>[...$data['details'],'message_id'=>"unsafe\r\nheader"]],
    ['headers'=>['X-Nexa-Queue-Item'=>['another-queue']]],
    ['headers'=>['X-Nexa-Queue-Item'=>['queue-42','queue-42']]],
    ['headers'=>[]],
] as $change){
    $blocked=false;
    try{PostalInspectionEvidence::verify(array_replace($data,$change),42,'queue-42','recipient@example.test','sender@example.test');}catch(BadRequest){$blocked=true;}
    if(!$blocked)throw new RuntimeException('Mismatched Postal evidence must not authorize recovery.');
}
echo "Postal exact-message inspection evidence tests passed.\n";
