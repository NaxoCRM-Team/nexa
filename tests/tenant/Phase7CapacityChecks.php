<?php
declare(strict_types=1);

// Loaded only by the opted-in CLI fixture, inside its disposable tenant context.
return static function ($em,$factory,$container,$user,array $tenant): array {
    $pdo=$em->getPDO();$checks=0;$timings=[];
    $assert=static function(bool $ok,string $message)use(&$checks):void{$checks++;if(!$ok)throw new RuntimeException($message);};
    $deny=static function(callable $fn,string $expected)use($assert):void{$message=null;try{$fn();}catch(\Espo\Core\Exceptions\BadRequest $error){$message=$error->getMessage();}$assert($message===$expected,'Capacity boundary did not reject with the expected error: '.$expected);};
    $acl=$container->getByClass(\Espo\Core\AclManager::class)->createUserAcl($user);
    $service=fn($class)=>$factory->createWith($class,['user'=>$user,'acl'=>$acl]);
    $abm=$service(\Espo\Custom\Tools\Abm\AbmService::class);
    $scoring=$service(\Espo\Custom\Tools\Scoring\ScoringService::class);
    $query=static function(string $sql,array $args=[])use($pdo){$q=$pdo->prepare($sql);$q->execute($args);return $q;};
    $scope=[$tenant['id'],$tenant['serviceId']];$id=fn()=>bin2hex(random_bytes(8));
    $tx=$em->getTransactionManager();$tx->start();
    try {
        fwrite(STDERR,"CAPACITY accounts started\n");$start=microtime(true);$accounts=[];
        // Bulk SQL creates representative volume; acceptance calls below use the real services.
        for($i=0;$i<5000;$i++){$account=$id();$accounts[]=$account;$query("INSERT INTO account (id,name,deleted,is_target_account,target_account_tier,assigned_user_id,tenant_id,service_id) VALUES (?, ?,0,1,'Tier1',?,?,?)",[$account,'Capacity company '.$i,$user->getId(),...$scope]);}
        $workspace=$abm->workspace();$assert(count($workspace['accounts'])===5000,'Exactly 5,000 target Accounts must be readable.');
        $assert($workspace['accounts'][0]['assignedUserId']===$user->getId() && !empty($workspace['accounts'][0]['assignedUserName']),'Bulk reads must preserve readable Account ownership.');
        $extra=$id();$query("INSERT INTO account (id,name,deleted,is_target_account,tenant_id,service_id) VALUES (?,'Overflow company',0,1,?,?)",[$extra,...$scope]);
        $deny(fn()=>$abm->workspace(),'This account workspace supports at most 5,000 target accounts.');
        $query('DELETE FROM account WHERE tenant_id=? AND service_id=? AND id=?',[...$scope,$extra]);
        $timings['accountsSeconds']=round(microtime(true)-$start,3);
        fwrite(STDERR,"CAPACITY accounts passed\n");
        $start=microtime(true);$company=$accounts[0];
        $query('UPDATE contact SET account_id=? WHERE tenant_id=? AND service_id=? AND id=?',[$company,...$scope,$tenant['contactId']]);
        for($i=0;$i<4999;$i++)$query("INSERT INTO contact (id,first_name,last_name,deleted,account_id,tenant_id,service_id) VALUES (?,'Capacity',?,0,?,?,?)",[$id(),(string)$i,$company,...$scope]);
        $assert($abm->accountSummary($company)['contactCount']===5000,'Account summary must count 5,000 related Contacts without truncation.');
        $audience=$abm->save((object)['name'=>'Capacity audience','type'=>'static','accountIds'=>[$company]]);
        $count=$query('SELECT COUNT(*) FROM contact_target_list WHERE tenant_id=? AND service_id=? AND target_list_id=? AND deleted=0',[...$scope,$audience['id']])->fetchColumn();
        $assert((int)$count===5000,'Account audience must project all 5,000 Contacts.');
        $extra=$id();$query("INSERT INTO contact (id,first_name,deleted,account_id,tenant_id,service_id) VALUES (?,'Overflow',0,?,?,?)",[$extra,$company,...$scope]);
        $deny(fn()=>$abm->accountSummary($company),'Account engagement supports up to 5,000 associated contacts.');
        $deny(fn()=>$abm->refresh($audience['id']),'This account audience supports at most 5,000 associated contacts.');
        $assert((int)$query('SELECT COUNT(*) FROM contact_target_list WHERE tenant_id=? AND service_id=? AND target_list_id=? AND deleted=0',[...$scope,$audience['id']])->fetchColumn()===5000,'Rejected refresh must preserve the previous audience.');
        $query('DELETE FROM contact WHERE tenant_id=? AND service_id=? AND id=?',[...$scope,$extra]);
        for($i=1;$i<200;$i++)$abm->save((object)['name'=>'Empty capacity list '.$i,'type'=>'static','accountIds'=>[]]);
        $assert(count($abm->workspace()['lists'])===200,'All 200 stored account lists must be readable.');
        $deny(fn()=>$abm->save((object)['name'=>'Overflow list','type'=>'static','accountIds'=>[]]),'This workspace supports up to 200 stored account lists.');
        $timings['audiencesSeconds']=round(microtime(true)-$start,3);
        fwrite(STDERR,"CAPACITY audiences passed\n");
        $start=microtime(true);
        $make=fn($name,$field='title')=>$scoring->save((object)['name'=>$name,'entityType'=>'Contact','definition'=>(object)['rules'=>[(object)['id'=>'fit','field'=>$field,'operator'=>'has_value','points'=>10]]]]);
        $model=$make('Capacity model');
        $behaviorId=null;for($i=1;$i<200;$i++){$draft=$make('Capacity model '.$i);if($i===1)$behaviorId=$draft['id'];}
        $assert(count($scoring->workspace()['models'])===200,'All 200 stored scoring models must be readable.');
        $deny(fn()=>$make('Overflow model'),'This workspace supports at most 200 stored models.');
        $scoring->control($model['id'],(object)['action'=>'publish']);
        $scoring->preview($model['id'],(object)['recordId'=>$tenant['contactId'],'requestKey'=>'capacity-initial-evaluation'],true);
        for($i=0;$i<24;$i++)$query('INSERT INTO nexa_scoring_evaluation (id,tenant_id,service_id,model_id,version_number,record_id,request_hash,score,inputs_json,result_json,input_hash,evaluated_by_id,evaluated_at) SELECT UUID(),tenant_id,service_id,model_id,version_number,record_id,SHA2(UUID(),256),score,inputs_json,result_json,input_hash,evaluated_by_id,evaluated_at FROM nexa_scoring_evaluation WHERE tenant_id=? AND service_id=? AND model_id=? LIMIT 1',[...$scope,$model['id']]);
        $history=$scoring->history($model['id'],$tenant['contactId']);
        $assert(count($history['evaluations'])===20 && $history['hasMoreEvaluations'],'Scoring history must declare overflow while retaining all evidence.');
        $assert((int)$query('SELECT COUNT(*) FROM nexa_scoring_evaluation WHERE tenant_id=? AND service_id=? AND model_id=?',[...$scope,$model['id']])->fetchColumn()===25,'History reads must not delete older evaluations.');
        $scoring->control($model['id'],(object)['action'=>'primary','enabled'=>true]);
        for($i=0;$i<51;$i++)$query("INSERT INTO nexa_scoring_adjustment (id,tenant_id,service_id,model_id,record_id,request_hash,request_json,delta,reason,created_by_id) VALUES (UUID(),?,?,?,?,SHA2(UUID(),256),JSON_OBJECT('delta',1,'reason','Capacity fixture'),1,'Capacity fixture',?)",[...$scope,$model['id'],$tenant['contactId'],$user->getId()]);
        $history=$scoring->history($model['id'],$tenant['contactId']);
        $assert(count($history['adjustments'])===50 && $history['hasMoreAdjustments'],'Adjustment history must declare its 50-row viewing bound.');
        $assert((int)$query('SELECT COUNT(*) FROM nexa_scoring_adjustment WHERE tenant_id=? AND service_id=? AND model_id=?',[...$scope,$model['id']])->fetchColumn()===51,'Reading adjustment history must preserve older entries.');
        $scoring->save((object)['id'=>$behaviorId,'name'=>'Capacity behavior','entityType'=>'Contact','definition'=>(object)['rules'=>[(object)['id'=>'views','field'=>'behavior_page_viewed_30','operator'=>'greater_than','value'=>'0','points'=>10]]]]);
        $eventSql="INSERT INTO nexa_timeline_event (id,tenant_id,service_id,contact_id,event_type,source_entity_type,source_entity_id,source_occurred_at,actor_type,visibility,summary) VALUES (UUID(),?,?,?,'page.viewed','BehaviorEvent',UUID(),UTC_TIMESTAMP(6),'system','internal','Capacity event')";
        for($i=0;$i<10000;$i++)$query($eventSql,[...$scope,$tenant['contactId']]);
        $behavior=$scoring->preview($behaviorId,(object)['recordId'=>$tenant['contactId']]);
        $assert($behavior['inputs']['behavior_page_viewed_30']===10000,'Scoring must count exactly 10,000 distinct events.');
        $assert($abm->accountSummary($company)['engagement']['pageViews']===10000,'Account engagement must count exactly 10,000 distinct events.');
        $query($eventSql,[...$scope,$tenant['contactId']]);
        $deny(fn()=>$scoring->preview($behaviorId,(object)['recordId'=>$tenant['contactId']]),'Narrow the behavior window to at most 10,000 distinct events.');
        $deny(fn()=>$abm->accountSummary($company),'Narrow account engagement to at most 10,000 distinct events.');
        $timings['scoringSeconds']=round(microtime(true)-$start,3);
        fwrite(STDERR,"CAPACITY scoring passed\n");
        $start=microtime(true);
        $metadata=json_encode(['version'=>1,'auditClock'=>'UTC','matchMode'=>'all','conditions'=>[['field'=>'leadStatus','operator'=>'equals','value'=>'New','matched'=>true]],'branch'=>'matching']);
        for($i=0;$i<2001;$i++)$query("INSERT INTO nexa_audit_event (id,tenant_id,service_id,actor_type,actor_user_id,action,subject_type,subject_id,correlation_id,source,metadata_json,occurred_at) VALUES (UUID(),?,?,'user',?,'personalization.evaluated','Contact',?,UUID(),'crm-record-hook',?,UTC_TIMESTAMP(6))",[...$scope,$user->getId(),$tenant['contactId'],$metadata]);
        $reader=$service(\Espo\Custom\Tools\MarketingEmail\PersonalizationHistoryService::class);$cursor=null;$seen=[];$pages=0;
        do{$page=$reader->history(['source'=>'email','search'=>$tenant['contactId'],'cursor'=>$cursor]);$assert(count($page['list'])<=100,'History responses must stay within their per-request bound.');foreach($page['list'] as $row){$assert(!isset($seen[$row['id']]),'History keysets must never repeat evidence.');$seen[$row['id']]=true;}$cursor=$page['cursor'];$pages++;if($pages>21)throw new RuntimeException('History cursor failed to terminate.');}while($cursor);
        $assert(count($seen)===2001 && $pages===21,'Native history must continue beyond the 2,000-row UI viewing cap without truncating stored evidence.');
        $timings['historySeconds']=round(microtime(true)-$start,3);
        fwrite(STDERR,"CAPACITY history passed\n");
        return ['checks'=>$checks,'timings'=>$timings,'accounts'=>5000,'audienceContacts'=>5000,'accountLists'=>200,'scoringModels'=>200,'historyRows'=>2001];
    } finally {if($tx->isStarted())$tx->rollback();}
};
