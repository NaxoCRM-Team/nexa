<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/espocrm/bootstrap.php';
use Espo\Custom\Tools\Conversation\TwilioSmsTransport;
$checks=0;$assert=function(bool $value,string $why)use(&$checks){$checks++;if(!$value)throw new RuntimeException($why);};
$transport=new class extends TwilioSmsTransport {
    public array $response=['status'=>0,'data'=>null];public array $requests=[];
    protected function request(string $method,string $url,string $account,string $secret,?string $body=null):array{$this->requests[]=[$method,$url,$body];return $this->response;}
};
$connection=['account_sid'=>'AC'.str_repeat('1',32),'sender'=>'+14155550100'];$message=['recipient'=>'+14155550101','body'=>'Test <safe> & +'];$secret=str_repeat('2',32);$callback='https://callback.example/status';
$record=['sid'=>'SM'.str_repeat('3',32),'account_sid'=>$connection['account_sid'],'from'=>$connection['sender'],'to'=>$message['recipient'],'body'=>$message['body'],'status'=>'queued'];
foreach([0,500,502,503,504,302,200] as $code){$transport->response=['status'=>$code,'data'=>null];$assert($transport->send($connection,$secret,$message,$callback)['outcome']==='uncertain','Ambiguous transport/server responses cannot trigger automatic resubmission.');}
$transport->response=['status'=>429,'data'=>null];$assert($transport->send($connection,$secret,$message,$callback)['outcome']==='retry','Definite 429 rejection is the only automatically retryable POST.');
foreach([400,401,403,404,405,413,422] as $code){$transport->response=['status'=>$code,'data'=>null];$assert($transport->send($connection,$secret,$message,$callback)['outcome']==='failed','Definitive provider rejection fails without automatic retry.');}
$transport->response=['status'=>201,'data'=>$record];$assert($transport->send($connection,$secret,$message,$callback)['sid']===$record['sid'],'Validated creation response binds provider SID.');
parse_str(end($transport->requests)[2],$form);$assert($form['Body']===$message['body']&&$form['To']===$message['recipient']&&$form['StatusCallback']===$callback,'Fixed-host form transport preserves message and exact callback.');
foreach(['account_sid','from','to','body','sid'] as $field){$bad=$record;$bad[$field]='wrong';$transport->response=['status'=>201,'data'=>$bad];$assert($transport->send($connection,$secret,$message,$callback)['outcome']==='uncertain','Mismatched provider evidence cannot bind delivery.');}
$record['status']='delivered';$transport->response=['status'=>200,'data'=>$record];$assert($transport->inspect($connection,$secret,$record['sid'],$message)['status']==='delivered','Read-only lookup reconciles an exact known message.');
$assert(end($transport->requests)[0]==='GET'&&str_starts_with(end($transport->requests)[1],'https://api.twilio.com/2010-04-01/Accounts/'),'Inspection uses the fixed provider host and a GET, not another send.');
$assert($transport->inspect($connection,$secret,'https://attacker.example',$message)===null,'Inspection does not accept arbitrary resource URLs.');
fwrite(STDOUT,"SMS transport checks passed: {$checks} (fake HTTP; no provider calls).\n");
