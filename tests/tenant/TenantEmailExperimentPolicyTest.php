<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/espocrm/bootstrap.php';
use Espo\Custom\Tools\EmailExperiment\ExperimentAssignment;
use Espo\Custom\Tools\Automation\{WorkflowAbRouting,WorkflowDefinition};
use Espo\Core\Exceptions\BadRequest;
$checks=0;$assert=static function(bool$condition,string$message)use(&$checks):void{++$checks;if(!$condition)throw new RuntimeException($message);};
$audience=[];for($index=0;$index<1000;$index++)$audience[]=['contact_id'=>'contact-'.$index,'email'=>'contact-'.$index.'@example.test'];
$original=ExperimentAssignment::assign($audience,'tenant-a-service-a-experiment',20,10);
shuffle($audience);$repeat=ExperimentAssignment::assign($audience,'tenant-a-service-a-experiment',20,10);
$assert($original===$repeat,'Database row ordering must not rerandomize assignments.');
$counts=array_count_values(array_column($original,'cohort'));
$assert($counts['test']===200&&$counts['holdout']===100&&$counts['rollout']===700,'Audience percentages must give exact bounded sizes.');
$tests=array_values(array_filter($original,fn(array$row):bool=>$row['cohort']==='test'));
$variants=array_count_values(array_column($tests,'variant'));$assert($variants['A']===100&&$variants['B']===100,'Test variants must be balanced.');
$duplicated=$audience;$duplicated[]=['contact_id'=>'duplicate','email'=>' CONTACT-0@EXAMPLE.TEST '];$duplicated[]=['contact_id'=>'invalid','email'=>'not-an-email'];
$assert(count(ExperimentAssignment::assign($duplicated,'tenant-a-service-a-experiment',20,10))===1000,'Normalized identical email recipients and invalid emails must not enter twice.');
$other=ExperimentAssignment::assign($audience,'tenant-b-service-a-experiment',20,10);$assert($other!==$original,'Experiments have independent tenant-owned seeds.');
foreach([[0,0],[101,0],[90,20],[20,-1]]as[$test,$holdout]){$blocked=false;try{ExperimentAssignment::assign($audience,'seed',$test,$holdout);}catch(BadRequest){$blocked=true;}$assert($blocked,'Invalid cohort sizes must be rejected.');}
$small=ExperimentAssignment::assign([['contact_id'=>'a','email'=>'a@example.test'],['contact_id'=>'b','email'=>'b@example.test']],'seed',2,98);$assert(count(array_filter($small,fn(array$row):bool=>$row['cohort']==='test'))===2,'The smallest audience must still expose one recipient per variant.');
$graph=['schemaVersion'=>1,'trigger'=>['event'=>'manual','reenrollment'=>'after_completion'],'entry'=>'split','nodes'=>[['id'=>'split','type'=>'ab_split','weightA'=>35,'yes'=>'a','no'=>'b'],['id'=>'a','type'=>'end'],['id'=>'b','type'=>'end']]];
$validator=new WorkflowDefinition();$publication=$validator->validate($graph);$hash=$publication['fingerprint'];
$aCount=0;
for($index=0;$index<1000;$index++){
    $identity=['tenant-a','service-a','workflow-a',1,'split','contact-'.$index];
    $choice=WorkflowAbRouting::assignment($identity,$hash,35);$assert($choice===WorkflowAbRouting::assignment($identity,$hash,35),'Routing must be sticky across worker replay and reenrollment.');
    $assert(in_array($choice['branch'],['A','B'],true),'Every Contact must take exactly one branch.');
    if($choice['branch']==='A')$aCount++;
}
$assert($aCount>250&&$aCount<450,'Weighted deterministic routing must distribute a representative audience.');
$assert(WorkflowAbRouting::assignment(['tenant-a','service-a','workflow-a',1,'split','contact-a'],$hash,35)['hash']!==WorkflowAbRouting::assignment(['tenant-b','service-a','workflow-a',1,'split','contact-a'],$hash,35)['hash'],'Tenant identity must participate in routing.');
foreach([0,100,'50',50.5]as$weight){$invalid=$graph;$invalid['nodes'][0]['weightA']=$weight;$blocked=false;try{$validator->validate($invalid);}catch(BadRequest){$blocked=true;}$assert($blocked,'A/B weights must be integer percentages from1 to99.');}
$invalid=$graph;$invalid['nodes'][0]['no']='a';$blocked=false;try{$validator->validate($invalid);}catch(BadRequest){$blocked=true;}$assert($blocked,'An A/B router cannot silently route both variants to the same step.');
$invalid=$graph;$invalid['nodes'][0]['tenantId']='forged';$blocked=false;try{$validator->validate($invalid);}catch(BadRequest){$blocked=true;}$assert($blocked,'Router graphs cannot carry caller tenant overrides.');
echo 'Email experiment/routing policy checks passed: '.$checks." (stateless).\n";
