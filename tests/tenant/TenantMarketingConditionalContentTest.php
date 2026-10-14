<?php
declare(strict_types=1);
require dirname(__DIR__,2) . '/espocrm/bootstrap.php';
use Espo\Core\Application;
use Espo\Core\Exceptions\{BadRequest,Forbidden,NotFound};
use Espo\Core\InjectableFactory;
use Espo\Core\ORM\EntityManager;
use Espo\Core\Tenant\{TenantContext,TenantContextStore};
use Espo\Custom\Tools\MarketingEmail\{MarketingConditionalContentService,MarketingPreviewService};
use Espo\Entities\{EmailTemplate,User};
use Espo\Tools\EmailTemplate\{Data,Params,Processor};

$assert = static function (bool $value,string $message): void {if (!$value) throw new RuntimeException($message);};
$blocked = static function (callable $call,string $message) use ($assert): void {
    $denied=false; try {$call();} catch (BadRequest|Forbidden|NotFound) {$denied=true;} $assert($denied,$message);
};
$app=new Application(); $app->setupSystemUser(); $container=$app->getContainer();
$em=$container->getByClass(EntityManager::class); $factory=$container->getByClass(InjectableFactory::class);
$store=$container->getByClass(TenantContextStore::class); $user=$container->getByClass(User::class);
$a=new TenantContext('30000000-0000-4000-8000-000000000001','isolation-alpha','conditional-email-test');
$b=new TenantContext('30000000-0000-4000-8000-000000000002','isolation-beta','conditional-email-test');
$otherService=new TenantContext($a->tenantId,$a->slug,'conditional-email-test','','20000000-0000-4000-8000-000000000099');
$tx=$em->getTransactionManager(); $tx->start();
try {
    $conditional=$store->runWith($a,fn ()=>$factory->create(MarketingConditionalContentService::class));
    $preview=$store->runWith($a,fn ()=>$factory->create(MarketingPreviewService::class));
    $catalog=$store->runWith($a,fn ()=>$conditional->catalog());
    $fields=array_column($catalog['fields'],'name');
    $assert(in_array('leadStatus',$fields,true) && !in_array('tenantId',$fields,true),'Only supported readable Contact properties must appear.');
    $input=(object) ['field'=>'leadStatus','operator'=>'equals','value'=>'New','matchingText'=>'Welcome {Contact.firstName}','fallbackText'=>'Existing customer'];
    $body=$store->runWith($a,fn ()=>$conditional->compose($input))['bodyHtml'];
    $assert(str_contains($body,'{{#ifEqual leadStatus_RAW (nexaEmailValue "TmV3")}}'),'Conditions must use native helpers with unlocalized raw values.');
    $contacts=[];
    foreach (['New','Connected'] as $status) $contacts[]=$store->runWith($a,function () use ($em,$status) {
        $contact=$em->getNewEntity('Contact'); $contact->set(['firstName'=>'Conditional','lastName'=>'Customer','leadStatus'=>$status]); $em->saveEntity($contact); return $contact;
    });
    $queueCount=(int) $em->getPDO()->query('SELECT COUNT(*) FROM email_queue_item')->fetchColumn();
    $render=fn ($contact,$html)=>$store->runWith($a,fn ()=>$preview->preview((object) ['contactId'=>$contact->getId(),'subject'=>'Hello {Contact.firstName}','bodyHtml'=>$html]));
    $behaviorInsert=$em->getPDO()->prepare("INSERT INTO nexa_timeline_event (id,tenant_id,service_id,contact_id,event_type,source_entity_type,source_entity_id,source_occurred_at,actor_type,visibility,summary,metadata_json) VALUES (UUID(),?,?,?,?,?,UUID(),?,'system',?,'Conditional behavior fixture','{}')");
    foreach ([['page.viewed','BehaviorEvent'],['email.opened','MarketingDeliveryEvent'],['email.clicked','MarketingDeliveryEvent'],['email.replied','BehaviorEvent']] as [$event,$source]) {
        $behaviorInsert->execute([$a->tenantId,$a->serviceId,$contacts[0]->getId(),$event,$source,gmdate('Y-m-d H:i:s',time()-86400),'internal']);
        foreach ([[time()+86400,'internal'],[time()-100*86400,'internal'],[time()-86400,'restricted']] as [$when,$visibility]) $behaviorInsert->execute([$a->tenantId,$a->serviceId,$contacts[1]->getId(),$event,$source,gmdate('Y-m-d H:i:s',$when),$visibility]);
        $rule=(object) ['field'=>'behavior:' . $event,'operator'=>'within_days','value'=>'30','matchingText'=>'Behavior matched','fallbackText'=>'Behavior fallback'];
        $html=$store->runWith($a,fn ()=>$conditional->compose($rule))['bodyHtml'];
        $assert(str_contains($render($contacts[0],$html)['bodyHtml'],'Behavior matched'),'Supported recent behavior must match: ' . $event);
        $assert(str_contains($render($contacts[1],$html)['bodyHtml'],'Behavior fallback'),'Old, future and restricted behavior must not match: ' . $event);
        $rule->operator='not_within_days';
        $html=$store->runWith($a,fn ()=>$conditional->compose($rule))['bodyHtml'];
        $assert(str_contains($render($contacts[1],$html)['bodyHtml'],'Behavior matched'),'Absent eligible recent evidence must satisfy the inverse condition.');
    }
    foreach (['0','366','1.5','-1','1e2'] as $invalid) {
        $rule->value=$invalid;
        $blocked(fn ()=>$store->runWith($a,fn ()=>$conditional->compose($rule)),'Behavioral windows must be whole days from 1 through 365.');
    }
    foreach ([['count_at_least',1,true],['count_at_least',2,false],['count_equals',1,true],['count_at_most',0,false]] as [$operator,$threshold,$expected]) {
        $countRule=(object)['field'=>'behavior:page.viewed','operator'=>$operator,'value'=>'30','count'=>$threshold,'matchingHtml'=>'<strong>Count matched</strong>','fallbackHtml'=>'<em>Count fallback</em>'];
        $countHtml=$store->runWith($a,fn ()=>$conditional->compose($countRule))['bodyHtml'];
        $assert(str_contains($render($contacts[0],$countHtml)['bodyHtml'],$expected?'Count matched':'Count fallback'),'Native rich rendering must compare the scoped behavior count.');
    }
    $countRule->operator='count_equals';$countRule->count=0;
    $countHtml=$store->runWith($a,fn ()=>$conditional->compose($countRule))['bodyHtml'];
    $assert(str_contains($render($contacts[1],$countHtml)['bodyHtml'],'Count matched'),'Expired, future and restricted events must not count.');
    foreach ([-1,10001,1.5,'1',null] as $count) {$countRule->count=$count;$blocked(fn ()=>$store->runWith($a,fn ()=>$conditional->compose($countRule)),'Event count input must be a bounded integer.');}
    $blocked(fn ()=>$store->runWith($a,fn ()=>$conditional->validate('','{{#nexaEmailBehavior id "page.viewed" "count_equals" "30"}}yes{{else}}no{{/nexaEmailBehavior}}')),'Handwritten count helpers cannot omit thresholds.');
    $blocked(fn ()=>$store->runWith($a,fn ()=>$conditional->validate('','{{#nexaEmailBehavior id "page.viewed" "within_days" "30" "0"}}yes{{else}}no{{/nexaEmailBehavior}}')),'Presence helpers cannot smuggle count arguments.');
    $rich=(object) ['field'=>'leadStatus','operator'=>'equals','value'=>'New','matchingHtml'=>'<h2>Welcome {Contact.firstName}</h2><p><strong>Premium offer</strong> <a href="https://example.test/offer">View offer</a></p>','fallbackHtml'=>'<p><em>Standard offer</em></p>'];
    $richHtml=$store->runWith($a,fn ()=>$conditional->compose($rich))['bodyHtml'];
    $assert(str_contains($render($contacts[0],$richHtml)['bodyHtml'],'<strong>Premium offer</strong>') && str_contains($render($contacts[1],$richHtml)['bodyHtml'],'<em>Standard offer</em>'),'Rich conditional branches must preserve formatting and select one recipient branch.');
    $unsafe=clone $rich; $unsafe->matchingHtml='<p onclick="bad()">Safe text<script>bad()</script><a href="javascript:bad()">Link</a></p>';
    $clean=$store->runWith($a,fn ()=>$conditional->compose($unsafe))['bodyHtml'];
    $assert(!str_contains($clean,'onclick') && !str_contains($clean,'<script') && !str_contains($clean,'javascript:'),'Rich branches must remove scripts, event attributes and unsafe link schemes.');
    $mixed=clone $rich; $mixed->matchingText='Ambiguous';
    $blocked(fn ()=>$store->runWith($a,fn ()=>$conditional->compose($mixed)),'Mixed rich and text branch formats must be rejected.');
    $empty=clone $rich; $empty->fallbackHtml='<p><br></p>';
    $blocked(fn ()=>$store->runWith($a,fn ()=>$conditional->compose($empty)),'Visually empty rich fallback content must be rejected.');
    $richGroup=clone $rich; unset($richGroup->field,$richGroup->operator,$richGroup->value);
    $richGroup->conditions=[(object) ['field'=>'leadStatus','operator'=>'equals','value'=>'New']]; $richGroup->matchMode='all';
    $assert(str_contains($render($contacts[0],$store->runWith($a,fn ()=>$conditional->compose($richGroup))['bodyHtml'])['bodyHtml'],'<strong>Premium offer</strong>'),'Grouped conditions must preserve rich branch content.');
    $customization=$factory->create(\Espo\Custom\Tools\Customization\CustomizationService::class);
    $customKey='email_condition_' . bin2hex(random_bytes(4));
    $custom=$store->runWith($a,fn ()=>$customization->saveDefinition('field',['entityType'=>'Contact','fieldKey'=>$customKey,'label'=>'Email condition ' . $customKey,'dataType'=>'single_select','options'=>['Premium','Standard']]));
    // Seed only this optional fixture; installed required properties remain untouched.
    $customValue=$em->getPDO()->prepare('INSERT INTO nexa_custom_field_value (id,tenant_id,service_id,field_definition_id,entity_type,entity_id,value_text,created_by_id,updated_by_id) VALUES (UUID(),?,?,?,?,?,?,?,?)');
    $customValue->execute([$a->tenantId,$a->serviceId,$custom['id'],'Contact',$contacts[0]->getId(),'Premium',$user->getId(),$user->getId()]);
    $customRule=(object) ['field'=>'custom:' . $customKey,'operator'=>'equals','value'=>'Premium','matchingText'=>'Premium welcome','fallbackText'=>'Standard welcome'];
    $customHtml=$store->runWith($a,fn ()=>$conditional->compose($customRule))['bodyHtml'];
    $assert(str_contains($render($contacts[0],$customHtml)['bodyHtml'],'Premium welcome'),'Custom Contact conditions must render stored tenant property values.');
    $assert(str_contains($render($contacts[1],$customHtml)['bodyHtml'],'Standard welcome'),'Missing custom values must render the fallback.');
    $blocked(fn ()=>$store->runWith($b,fn ()=>$conditional->compose($customRule)),'Another tenant must not compose a foreign custom property.');
    $blocked(fn ()=>$store->runWith($otherService,fn ()=>$conditional->compose($customRule)),'Another service must not compose a foreign custom property.');
    $badCustom=clone $customRule; $badCustom->value='Unknown';
    $blocked(fn ()=>$store->runWith($a,fn ()=>$conditional->compose($badCustom)),'Custom select comparisons must use declared options.');
    $customTemplate=$em->getNewEntity(EmailTemplate::ENTITY_TYPE); $customTemplate->set(['subject'=>'Custom condition','body'=>$customHtml,'isHtml'=>true]);
    $customResult=$store->runWith($a,fn ()=>$factory->create(Processor::class)->process($customTemplate,Params::create()->withApplyAcl(false)->withCopyAttachments(false),Data::create()->withParent($contacts[0])));
    $assert(str_contains($customResult->getBody(),'Premium welcome'),'The delivery processor must resolve custom conditions like preview.');
    $store->runWith($a,fn ()=>$customization->saveDefinition('propertyPreference',['entityType'=>'Contact','fieldKey'=>$customKey,'isEnabled'=>false]));
    $blocked(fn ()=>$render($contacts[0],$customHtml),'Disabled custom properties must not be rendered from retained values.');
    $store->runWith($a,fn ()=>$customization->saveDefinition('propertyPreference',['entityType'=>'Contact','fieldKey'=>$customKey,'isEnabled'=>true]));
    $assert(str_contains($render($contacts[0],$customHtml)['bodyHtml'],'Premium welcome'),'Re-enabling a property must restore access to its retained value.');
    $blocked(fn ()=>$store->runWith($a,fn ()=>$conditional->validate('',str_replace('nexaEmailCustomProperty id','nexaEmailCustomProperty accountId',$customHtml))),'Handwritten helpers must not redirect custom-property lookups to another record.');
    $store->runWith($a,fn ()=>$customization->archive('field',$custom['id']));
    $blocked(fn ()=>$render($contacts[0],$customHtml),'Archiving a referenced custom property must fail closed in preview.');
    $booleanKey='email_boolean_' . bin2hex(random_bytes(4));
    $boolean=$store->runWith($a,fn ()=>$customization->saveDefinition('field',['entityType'=>'Contact','fieldKey'=>$booleanKey,'label'=>'Email boolean ' . $booleanKey,'dataType'=>'boolean']));
    $booleanValue=$em->getPDO()->prepare('INSERT INTO nexa_custom_field_value (id,tenant_id,service_id,field_definition_id,entity_type,entity_id,value_boolean,created_by_id,updated_by_id) VALUES (UUID(),?,?,?,?,?,?,?,?)');
    $booleanValue->execute([$a->tenantId,$a->serviceId,$boolean['id'],'Contact',$contacts[0]->getId(),0,$user->getId(),$user->getId()]);
    $booleanRule=(object) ['field'=>'custom:' . $booleanKey,'operator'=>'equals','value'=>'false','matchingText'=>'Boolean matched','fallbackText'=>'Boolean fallback'];
    foreach (['equals','not_equals','has_value','no_value'] as $operator) {
        $booleanRule->operator=$operator;
        $html=$store->runWith($a,fn ()=>$conditional->compose($booleanRule))['bodyHtml'];
        $expected=in_array($operator,['equals','has_value'],true) ? 'Boolean matched' : 'Boolean fallback';
        $assert(str_contains($render($contacts[0],$html)['bodyHtml'],$expected),'Recorded false must be distinct from missing for ' . $operator);
        $missing=in_array($operator,['not_equals','no_value'],true) ? 'Boolean matched' : 'Boolean fallback';
        $assert(str_contains($render($contacts[1],$html)['bodyHtml'],$missing),'Missing boolean must remain distinct from true and false for ' . $operator);
    }
    $booleanRule->operator='equals'; $booleanRule->value='true';
    $em->getPDO()->prepare('UPDATE nexa_custom_field_value SET value_boolean=1 WHERE field_definition_id=? AND tenant_id=? AND service_id=?')->execute([$boolean['id'],$a->tenantId,$a->serviceId]);
    $trueHtml=$store->runWith($a,fn ()=>$conditional->compose($booleanRule))['bodyHtml'];
    $assert(str_contains($render($contacts[0],$trueHtml)['bodyHtml'],'Boolean matched'),'Recorded true must satisfy a true comparison.');
    $booleanRule->value='yes';
    $blocked(fn ()=>$store->runWith($a,fn ()=>$conditional->compose($booleanRule)),'Boolean comparison values must be exactly true or false.');
    $numberKey='email_number_' . bin2hex(random_bytes(4));
    $number=$store->runWith($a,fn ()=>$customization->saveDefinition('field',['entityType'=>'Contact','fieldKey'=>$numberKey,'label'=>'Email number ' . $numberKey,'dataType'=>'number']));
    $numberValue=$em->getPDO()->prepare('INSERT INTO nexa_custom_field_value (id,tenant_id,service_id,field_definition_id,entity_type,entity_id,value_number,created_by_id,updated_by_id) VALUES (UUID(),?,?,?,?,?,?,?,?)');
    $numberValue->execute([$a->tenantId,$a->serviceId,$number['id'],'Contact',$contacts[0]->getId(),'9007199254740993.12345678',$user->getId(),$user->getId()]);
    $numberRule=(object) ['field'=>'custom:' . $numberKey,'operator'=>'greater_than','value'=>'9007199254740993.12345677','matchingText'=>'Decimal matched','fallbackText'=>'Decimal fallback'];
    $numberHtml=$store->runWith($a,fn ()=>$conditional->compose($numberRule))['bodyHtml'];
    $assert(str_contains($render($contacts[0],$numberHtml)['bodyHtml'],'Decimal matched'),'Decimal conditions must preserve precision beyond the floating-point integer boundary.');
    $assert(str_contains($render($contacts[1],$numberHtml)['bodyHtml'],'Decimal fallback'),'Missing numeric custom values must not satisfy comparisons.');
    $em->getPDO()->prepare('UPDATE nexa_custom_field_value SET value_number=? WHERE field_definition_id=? AND tenant_id=? AND service_id=?')->execute(['-0.12345678',$number['id'],$a->tenantId,$a->serviceId]);
    $numberRule->operator='less_than'; $numberRule->value='-0.12345677';
    $numberHtml=$store->runWith($a,fn ()=>$conditional->compose($numberRule))['bodyHtml'];
    $assert(str_contains($render($contacts[0],$numberHtml)['bodyHtml'],'Decimal matched'),'Negative decimal comparisons must preserve sign and eight-place precision.');
    foreach (['1e2','NaN','1.123456789','12345678901234567890123'] as $invalid) {
        $numberRule->value=$invalid;
        $blocked(fn ()=>$store->runWith($a,fn ()=>$conditional->compose($numberRule)),'Out-of-storage-range and exponent numeric thresholds must be rejected.');
    }
    $dateKey='email_date_' . bin2hex(random_bytes(4));
    $dateField=$store->runWith($a,fn ()=>$customization->saveDefinition('field',['entityType'=>'Contact','fieldKey'=>$dateKey,'label'=>'Renewal date','dataType'=>'date']));
    $em->getPDO()->prepare('INSERT INTO nexa_custom_field_value (id,tenant_id,service_id,field_definition_id,entity_type,entity_id,value_date,created_by_id,updated_by_id) VALUES (UUID(),?,?,?,?,?,?,?,?)')->execute([$a->tenantId,$a->serviceId,$dateField['id'],'Contact',$contacts[0]->getId(),'2028-02-29',$user->getId(),$user->getId()]);
    $dateRule=(object) ['field'=>'custom:' . $dateKey,'operator'=>'on_or_before','value'=>'2028-02-29','matchingHtml'=>'<p><strong>Renewal due</strong></p>','fallbackHtml'=>'<p>No renewal date match</p>'];
    $dateHtml=$store->runWith($a,fn ()=>$conditional->compose($dateRule))['bodyHtml'];
    $assert(str_contains($render($contacts[0],$dateHtml)['bodyHtml'],'<strong>Renewal due</strong>'),'Date comparisons must preserve rich matched content through the native renderer.');
    $assert(str_contains($render($contacts[1],$dateHtml)['bodyHtml'],'No renewal date match'),'Missing calendar dates must use the fallback branch.');
    foreach (['equals'=>true,'not_equals'=>false,'before'=>false,'after'=>false,'on_or_before'=>true,'on_or_after'=>true,'has_value'=>true,'no_value'=>false] as $operator=>$matches) {
        $dateRule->operator=$operator;
        $dateHtml=$store->runWith($a,fn ()=>$conditional->compose($dateRule))['bodyHtml'];
        $assert(str_contains($render($contacts[0],$dateHtml)['bodyHtml'],$matches ? 'Renewal due' : 'No renewal date match'),'Calendar-date equality and presence boundary must be correct for ' . $operator);
        $assert(str_contains($render($contacts[1],$dateHtml)['bodyHtml'],$operator==='no_value' ? 'Renewal due' : 'No renewal date match'),'Missing dates must not be treated as known unequal dates for ' . $operator);
    }
    $dateRule->operator='before'; $dateRule->value='2028-03-01';
    $dateHtml=$store->runWith($a,fn ()=>$conditional->compose($dateRule))['bodyHtml'];
    $dateTemplate=$em->getNewEntity(EmailTemplate::ENTITY_TYPE); $dateTemplate->set(['subject'=>'Renewal date','body'=>$dateHtml,'isHtml'=>true]);
    $dateResult=$store->runWith($a,fn ()=>$factory->create(Processor::class)->process($dateTemplate,Params::create()->withApplyAcl(false)->withCopyAttachments(false),Data::create()->withParent($contacts[0])));
    $assert(str_contains($dateResult->getBody(),'Renewal due'),'Native delivery processing must use the same date semantics as preview.');
    $blocked(fn ()=>$store->runWith($b,fn ()=>$conditional->compose($dateRule)),'Date properties must not cross tenants.');
    $blocked(fn ()=>$store->runWith($otherService,fn ()=>$conditional->compose($dateRule)),'Date properties must not cross services.');
    foreach (['2027-02-29','2028-02-30','2028-13-01','2028-03-01T00:00:00Z','03/01/2028'] as $invalid) {
        $badDate=clone $dateRule; $badDate->value=$invalid;
        $blocked(fn ()=>$store->runWith($a,fn ()=>$conditional->compose($badDate)),'Invalid or timestamp comparison values must not be silently normalized as dates.');
    }
    $em->getPDO()->prepare('UPDATE nexa_custom_field_value SET value_date=? WHERE tenant_id=? AND service_id=? AND field_definition_id=? AND entity_id=?')->execute([gmdate('Y-m-d'),$a->tenantId,$a->serviceId,$dateField['id'],$contacts[0]->getId()]);
    $relativeDate=clone $dateRule; $relativeDate->operator='within_next_days'; $relativeDate->value='0';
    $relativeHtml=$store->runWith($a,fn ()=>$conditional->compose($relativeDate))['bodyHtml'];
    $assert(str_contains($render($contacts[0],$relativeHtml)['bodyHtml'],'Renewal due'),'Relative calendar rules must match today in native preview.');
    $assert(str_contains($render($contacts[1],$relativeHtml)['bodyHtml'],'No renewal date match'),'Missing relative calendar dates must fall back.');
    foreach (['-1','366','01','1.5'] as $invalid) {
        $badRelative=clone $relativeDate; $badRelative->value=$invalid;
        $blocked(fn ()=>$store->runWith($a,fn ()=>$conditional->compose($badRelative)),'Relative windows must reject invalid day values.');
    }
    $store->runWith($a,fn ()=>$customization->saveDefinition('propertyPreference',['entityType'=>'Contact','fieldKey'=>$dateKey,'isEnabled'=>false]));
    $blocked(fn ()=>$render($contacts[0],$dateHtml),'Disabled date properties must fail closed in retained templates.');
    $store->runWith($a,fn ()=>$customization->saveDefinition('propertyPreference',['entityType'=>'Contact','fieldKey'=>$dateKey,'isEnabled'=>true]));
    $store->runWith($a,fn ()=>$customization->archive('field',$dateField['id']));
    $blocked(fn ()=>$render($contacts[0],$dateHtml),'Archived date properties must not render retained values.');
    $timeKey='email_time_' . bin2hex(random_bytes(4));
    $timeField=$store->runWith($a,fn ()=>$customization->saveDefinition('field',['entityType'=>'Contact','fieldKey'=>$timeKey,'label'=>'Appointment time','dataType'=>'datetime']));
    $em->getPDO()->prepare('INSERT INTO nexa_custom_field_value (id,tenant_id,service_id,field_definition_id,entity_type,entity_id,value_datetime,created_by_id,updated_by_id) VALUES (UUID(),?,?,?,?,?,?,?,?)')->execute([$a->tenantId,$a->serviceId,$timeField['id'],'Contact',$contacts[0]->getId(),'2028-02-29 09:00:00',$user->getId(),$user->getId()]);
    $timeRule=(object) ['field'=>'custom:' . $timeKey,'operator'=>'equals','value'=>'2028-02-29T10:00:00+01:00','matchingHtml'=>'<p><strong>Appointment matched</strong></p>','fallbackHtml'=>'<p>No appointment match</p>'];
    $timeHtml=$store->runWith($a,fn ()=>$conditional->compose($timeRule))['bodyHtml'];
    $assert(str_contains($timeHtml,'2028-02-29T09:00:00Z'),'Composed timestamps must be normalized to UTC.');
    $timePreview=$render($contacts[0],$timeHtml)['bodyHtml'];
    $assert(str_contains($timePreview,'<strong>Appointment matched</strong>'),'Timestamp rules must preserve rich branches.');
    $assert(str_contains($render($contacts[1],$timeHtml)['bodyHtml'],'No appointment match'),'Missing timestamp uses fallback.');
    $timeTemplate=$em->getNewEntity(EmailTemplate::ENTITY_TYPE); $timeTemplate->set(['subject'=>'Appointment','body'=>$timeHtml,'isHtml'=>true]);
    $timeResult=$store->runWith($a,fn ()=>$factory->create(Processor::class)->process($timeTemplate,Params::create()->withApplyAcl(false)->withCopyAttachments(false),Data::create()->withParent($contacts[0])));
    $assert(str_contains($timeResult->getBody(),'Appointment matched'),'Native delivery rendering must use UTC timestamp comparisons.');
    $blocked(fn ()=>$store->runWith($b,fn ()=>$conditional->compose($timeRule)),'Timestamp fields cannot cross tenants.');
    $blocked(fn ()=>$store->runWith($otherService,fn ()=>$conditional->compose($timeRule)),'Timestamp fields cannot cross services.');
    foreach (['2028-02-29','2028-02-29T09:00:00','2028-02-30T09:00:00Z'] as $invalid) {
        $badTime=clone $timeRule; $badTime->value=$invalid;
        $blocked(fn ()=>$store->runWith($a,fn ()=>$conditional->compose($badTime)),'Ambiguous or invalid timestamp inputs must be rejected.');
    }
    $timeGroup=$store->runWith($a,fn ()=>$conditional->compose((object) ['matchMode'=>'all','conditions'=>[$timeRule,(object) ['field'=>'leadStatus','operator'=>'equals','value'=>'New']],'matchingHtml'=>'<p>Appointment group matched</p>','fallbackHtml'=>'<p>Appointment group fallback</p>']))['bodyHtml'];
    $assert(str_contains($render($contacts[0],$timeGroup)['bodyHtml'],'Appointment group matched'),'Timestamps must work in grouped conditions.');
    $em->getPDO()->prepare('UPDATE nexa_custom_field_value SET value_datetime=DATE_SUB(UTC_TIMESTAMP(6),INTERVAL 1 DAY) WHERE tenant_id=? AND service_id=? AND field_definition_id=? AND entity_id=?')->execute([$a->tenantId,$a->serviceId,$timeField['id'],$contacts[0]->getId()]);
    $relativeTime=clone $timeRule; $relativeTime->operator='within_last_days'; $relativeTime->value='2';
    $relativeTimeHtml=$store->runWith($a,fn ()=>$conditional->compose($relativeTime))['bodyHtml'];
    $assert(str_contains($render($contacts[0],$relativeTimeHtml)['bodyHtml'],'Appointment matched'),'Elapsed relative timestamp rules must match recent stored times.');
    $relativeTime->operator='within_next_days';
    $futureTimeHtml=$store->runWith($a,fn ()=>$conditional->compose($relativeTime))['bodyHtml'];
    $assert(str_contains($render($contacts[0],$futureTimeHtml)['bodyHtml'],'No appointment match'),'Past timestamps must not satisfy future windows.');
    $timeTemplate->set('body',$relativeTimeHtml);
    $relativeResult=$store->runWith($a,fn ()=>$factory->create(Processor::class)->process($timeTemplate,Params::create()->withApplyAcl(false)->withCopyAttachments(false),Data::create()->withParent($contacts[0])));
    $assert(str_contains($relativeResult->getBody(),'Appointment matched'),'Delivery processing must retain relative timestamp rules.');
    $blocked(fn ()=>$store->runWith($b,fn ()=>$conditional->compose($relativeTime)),'Relative properties cannot cross tenants.');
    $store->runWith($a,fn ()=>$customization->saveDefinition('propertyPreference',['entityType'=>'Contact','fieldKey'=>$timeKey,'isEnabled'=>false]));
    $blocked(fn ()=>$render($contacts[0],$timeHtml),'Disabled timestamp properties must fail closed.');
    $store->runWith($a,fn ()=>$customization->saveDefinition('propertyPreference',['entityType'=>'Contact','fieldKey'=>$timeKey,'isEnabled'=>true]));
    $store->runWith($a,fn ()=>$customization->archive('field',$timeField['id']));
    $blocked(fn ()=>$render($contacts[0],$timeHtml),'Archived timestamp properties must fail closed.');
    $assert(in_array('leadScore',$fields,true),'Readable native lead score must be available for conditional rules.');
    $numeric = (object) ['field'=>'leadScore','operator'=>'at_least','value'=>'50','matchingText'=>'Score match','fallbackText'=>'Score fallback'];
    foreach ([0,49,50,51,null] as $score) {
        $store->runWith($a,function () use ($em,$contacts,$score): void {$contacts[0]->set('leadScore',$score); $em->saveEntity($contacts[0]);});
        foreach (['equals','not_equals','greater_than','at_least','less_than','at_most','has_value','no_value'] as $operator) {
            $numeric->operator = $operator;
            $html = $store->runWith($a,fn ()=>$conditional->compose($numeric))['bodyHtml'];
            $expected = match ($operator) {
                'equals'=>$score !== null && $score === 50, 'not_equals'=>$score !== null && $score !== 50,
                'greater_than'=>$score !== null && $score > 50, 'at_least'=>$score !== null && $score >= 50,
                'less_than'=>$score !== null && $score < 50, 'at_most'=>$score !== null && $score <= 50,
                'has_value'=>$score !== null, 'no_value'=>$score === null,
            };
            $assert(str_contains($render($contacts[0],$html)['bodyHtml'],$expected ? 'Score match' : 'Score fallback'),'Numeric condition must respect boundaries, missing values and zero: ' . $operator);
        }
    }
    $numeric->operator = 'at_least';
    $store->runWith($a,function () use ($em,$contacts): void {$contacts[0]->set('leadScore',75); $em->saveEntity($contacts[0]);});
    $mixedGroup = (object) ['matchMode'=>'all','conditions'=>[(object) ['field'=>'firstName','operator'=>'equals','value'=>'Conditional'],(object) ['field'=>'leadScore','operator'=>'at_least','value'=>'50']], 'matchingText'=>'Mixed match','fallbackText'=>'Mixed fallback'];
    $mixedHtml = $store->runWith($a,fn ()=>$conditional->compose($mixedGroup))['bodyHtml'];
    $assert(str_contains($render($contacts[0],$mixedHtml)['bodyHtml'],'Mixed match') && str_contains($render($contacts[1],$mixedHtml)['bodyHtml'],'Mixed fallback'),'Scalar and numeric conditions must compose and render together in a native group.');
    foreach (['-1','1.5','NaN','1e2','2147483648','{{leadScore}}'] as $invalid) {
        $numeric->value = $invalid;
        $blocked(fn ()=>$store->runWith($a,fn ()=>$conditional->compose($numeric)),'Invalid numeric thresholds must fail composition.');
    }
    $matching=$render($contacts[0],$body); $fallback=$render($contacts[1],$body);
    $group = (object) ['matchMode'=>'all','conditions'=>[(object) ['field'=>'leadStatus','operator'=>'equals','value'=>'New'],(object) ['field'=>'department','operator'=>'has_value']], 'matchingText'=>'Group matched','fallbackText'=>'Group fallback'];
    foreach (['all','any'] as $mode) {
        $group->matchMode = $mode;
        $groupBody = $store->runWith($a,fn ()=>$conditional->compose($group))['bodyHtml'];
        $newResult = $render($contacts[0],$groupBody)['bodyHtml'];
        $assert(str_contains($newResult,$mode === 'all' ? 'Group fallback' : 'Group matched'), 'Grouped conditions must implement all/any semantics with native helpers.');
        $assert(substr_count($newResult,'Group matched') + substr_count($newResult,'Group fallback') === 1, 'A group must render exactly one content branch.');
        $assert(str_contains($render($contacts[1],$groupBody)['bodyHtml'],'Group fallback'), 'A recipient matching no rules must receive the group fallback.');
    }
    foreach ([[], array_fill(0,7,$group->conditions[0]), [(object) ['conditions'=>[]]], [(object) ['field'=>'tenantId','operator'=>'has_value']]] as $invalid) {
        $bad = clone $group; $bad->conditions = $invalid;
        $blocked(fn ()=>$store->runWith($a,fn ()=>$conditional->compose($bad)), 'Invalid, nested, excessive or unauthorized grouped conditions must be rejected.');
    }
    $store->runWith($a,function () use ($em,$contacts): void {$contacts[0]->set('department','Sales'); $contacts[1]->set('department','Sales'); foreach ($contacts as $contact) $em->saveEntity($contact);});
    foreach (['all','any'] as $mode) {
        $group->matchMode = $mode;
        $html = $store->runWith($a,fn ()=>$conditional->compose($group))['bodyHtml'];
        $assert(str_contains($render($contacts[0],$html)['bodyHtml'],'Group matched'),'Matching every condition must select the positive branch in either mode.');
        $assert(str_contains($render($contacts[1],$html)['bodyHtml'],$mode === 'all' ? 'Group fallback' : 'Group matched'),'Any must consider later rules, while all must reject the first mismatch.');
    }
    $store->runWith($a,function () use ($em,$contacts): void {foreach ($contacts as $contact) {$contact->set('department',null); $em->saveEntity($contact);}});
    $assert(str_contains($matching['bodyHtml'],'Welcome Conditional') && !str_contains($matching['bodyHtml'],'Existing customer'),'Matching recipients must see only their branch with native personalization.');
    $assert(str_contains($fallback['bodyHtml'],'Existing customer') && !str_contains($fallback['bodyHtml'],'Welcome Conditional'),'Non-matching recipients must receive the fallback only.');
    $input->operator='not_equals'; $body=$store->runWith($a,fn ()=>$conditional->compose($input))['bodyHtml'];
    $assert(str_contains($render($contacts[1],$body)['bodyHtml'],'Welcome Conditional'),'Native not-equal conditions must select the inverse branch.');
    foreach (['has_value','no_value'] as $operator) {
        $input->field='department'; $input->operator=$operator; $body=$store->runWith($a,fn ()=>$conditional->compose($input))['bodyHtml'];
        $assert(str_contains($render($contacts[0],$body)['bodyHtml'],$operator==='no_value' ? 'Welcome Conditional' : 'Existing customer'),'Missing property values must choose the deterministic fallback or no-value branch.');
    }
    $input->field='firstName'; $input->operator='equals'; $input->value='Conditional'; $input->matchingText='<script>unsafe()</script> & {Contact.firstName}';
    $body=$store->runWith($a,fn ()=>$conditional->compose($input))['bodyHtml'];
    $assert(!str_contains($body,'<script>') && str_contains($render($contacts[0],$body)['bodyHtml'],'&lt;script&gt;'),'Composer text must not turn into executable markup.');
    $input->value='A "quoted" \\ value & more';
    $body=$store->runWith($a,fn ()=>$conditional->compose($input))['bodyHtml'];
    $store->runWith($a,function () use ($em,$contacts,$input): void {$contacts[0]->set('firstName',$input->value); $em->saveEntity($contacts[0]);});
    $assert(!str_contains($render($contacts[0],$body)['bodyHtml'],'Existing customer'),'Quoted/backslash comparison values must select the matching branch without changing their meaning.');
    $blocked(fn ()=>$store->runWith($a,fn ()=>$conditional->compose((object) ['field'=>'tenantId','operator'=>'equals','value'=>$a->tenantId,'matchingText'=>'yes','fallbackText'=>'no'])),'Ownership fields must not be conditional inputs.');
    $blocked(fn ()=>$store->runWith($a,fn ()=>$conditional->compose((object) ['field'=>'leadStatus','operator'=>'equals','value'=>'Invented','matchingText'=>'yes','fallbackText'=>'no'])),'Unknown controlled values must be rejected.');
    $blocked(fn ()=>$store->runWith($a,fn ()=>$conditional->compose((object) ['field'=>'firstName','operator'=>'equals','value'=>'{{else}}','matchingText'=>'yes','fallbackText'=>'no'])),'Comparison values must not inject template control markers.');
    $blocked(fn ()=>$store->runWith($a,fn ()=>$conditional->validate('','{{#ifEqual leadStatus_RAW "New"}}<p>Unclosed</p>')),'Malformed native helpers must fail before save or preview.');
    $blocked(fn ()=>$store->runWith($a,fn ()=>$conditional->validate('','{{#if firstName')),'Unclosed expressions without closing braces must also fail validation.');
    $blocked(fn ()=>$store->runWith($a,fn ()=>$conditional->validate('',str_repeat('{{firstName}}',129))),'Template expressions must be bounded.');
    $blocked(fn ()=>$store->runWith($b,fn ()=>$preview->preview((object) ['contactId'=>$contacts[0]->getId(),'bodyHtml'=>$body])),'Conditional previews must not read another tenant Contact.');
    $blocked(fn ()=>$store->runWith($otherService,fn ()=>$preview->preview((object) ['contactId'=>$contacts[0]->getId(),'bodyHtml'=>$body])),'Conditional previews must not read another service Contact.');
    $originalType=$user->get('type');
    try {$user->set('type','regular'); $blocked(fn ()=>$store->runWith($a,fn ()=>$conditional->catalog()),'Non-administrators must not configure conditional marketing content.');}
    finally {$user->set('type',$originalType);}
    $template=$em->getNewEntity(EmailTemplate::ENTITY_TYPE); $template->set(['subject'=>'Native conditional delivery','body'=>'{{#ifEqual leadStatus_RAW "New"}}<p>New lead</p>{{else}}<p>Established</p>{{/ifEqual}}','isHtml'=>true]);
    $result=$store->runWith($a,fn ()=>$factory->create(Processor::class)->process($template,Params::create()->withApplyAcl(false)->withCopyAttachments(false),Data::create()->withParent($contacts[0])));
    $assert(str_contains($result->getBody(),'New lead') && !str_contains($result->getBody(),'Established'),'The production worker native processor must use the same condition semantics.');
    $deliveryCount=(object)['field'=>'behavior:page.viewed','operator'=>'count_equals','value'=>'30','count'=>1,'matchingHtml'=>'<strong>Count worker matched</strong>','fallbackHtml'=>'<em>Count worker fallback</em>'];
    $deliveryCountHtml=$store->runWith($a,fn ()=>$conditional->compose($deliveryCount))['bodyHtml'];
    $template->set('body',$deliveryCountHtml);
    $countResult=$store->runWith($a,fn ()=>$factory->create(Processor::class)->process($template,Params::create()->withApplyAcl(false)->withCopyAttachments(false),Data::create()->withParent($contacts[0])));
    $assert(str_contains($countResult->getBody(),'<strong>Count worker matched</strong>') && !str_contains($countResult->getBody(),'Count worker fallback'),'The production processor must render the counted rich branch.');
    $foreignResult=$store->runWith($b,fn ()=>$factory->create(Processor::class)->process($template,Params::create()->withApplyAcl(false)->withCopyAttachments(false),Data::create()->withParent($contacts[0])));
    $assert(!str_contains($foreignResult->getBody(),'Count worker matched'),'Rendering under another tenant must not expose the foreign activity count.');
    $helperData=new \Espo\Core\Htmlizer\Helper\Data('nexaEmailBehavior',[$contacts[0]->getId(),'page.viewed','count_equals','30','1'],new stdClass(),null,[],static fn ()=>'matched',static fn ()=>'fallback');
    $blocked(fn ()=>$store->runWith($b,fn ()=>$factory->create(\Espo\Custom\Tools\MarketingEmail\TemplateHelpers\BehaviorCondition::class)->render($helperData)),'An explicit foreign Contact ID must fail closed at the helper boundary.');
    $group=(object)['conditions'=>[(object)['field'=>'behavior:page.viewed','operator'=>'count_equals','value'=>'30','count'=>1],(object)['field'=>'leadStatus','operator'=>'equals','value'=>'New']],'matchMode'=>'all','matchingText'=>'Count group matched','fallbackText'=>'Count group fallback'];
    $groupHtml=$store->runWith($a,fn ()=>$conditional->compose($group))['bodyHtml'];
    $assert(str_contains($render($contacts[0],$groupHtml)['bodyHtml'],'Count group matched'),'Grouped conditions must preserve event thresholds.');
    $pageContact=$store->runWith($a,fn ()=>$em->createEntity('Contact',['firstName'=>'Exact page fixture']));
    $pageEvents=$factory->create(\Espo\Custom\Tools\Event\BehaviorEventService::class);
    foreach (['https://example.test/Pricing','https://example.test/pricing','https://example.test/Pricing?offer=1','https://example.test/Pricing'] as $index=>$url) {
        $eventInput=(object)['eventType'=>'page.viewed','source'=>'conditional-page-test','idempotencyKey'=>'page-' . $pageContact->getId() . '-' . $index,'contactId'=>$pageContact->getId(),'pageUrl'=>$url,'identityEvidence'=>(object)['type'=>'authenticated_session','verified'=>true,'reference'=>$pageContact->getId()],'consent'=>(object)['analytics'=>'granted']];
        $store->runWith($a,fn ()=>$pageEvents->ingest($eventInput));
    }
    $pageRule=(object)['field'=>'behavior:page.viewed','operator'=>'count_equals','value'=>'30','count'=>2,'pageUrl'=>'https://example.test/Pricing','matchingHtml'=>'<p><strong>Page count matched</strong></p>','fallbackHtml'=>'<p>Page count fallback</p>'];
    $pageHtml=$store->runWith($a,fn ()=>$conditional->compose($pageRule))['bodyHtml'];
    $assert(str_contains($render($pageContact,$pageHtml)['bodyHtml'],'Page count matched'),'Exact page counts must exclude different case and query strings.');
    $pageTemplate=$em->getNewEntity(EmailTemplate::ENTITY_TYPE); $pageTemplate->set(['subject'=>'Exact page','body'=>$pageHtml,'isHtml'=>true]);
    $pageResult=$store->runWith($a,fn ()=>$factory->create(Processor::class)->process($pageTemplate,Params::create()->withApplyAcl(false)->withCopyAttachments(false),Data::create()->withParent($pageContact)));
    $assert(str_contains($pageResult->getBody(),'Page count matched'),'Native delivery rendering must retain the page filter.');
    unset($pageRule->count); $pageRule->operator='within_days'; $pageRule->pageUrl='https://example.test/Pricing?offer=1';
    $pageHtml=$store->runWith($a,fn ()=>$conditional->compose($pageRule))['bodyHtml'];
    $assert(str_contains($render($pageContact,$pageHtml)['bodyHtml'],'Page count matched'),'Presence rules must support exact page filters without a count.');
    $pageRule->pageUrl='https://example.test/Pricing?offer=2';
    $pageHtml=$store->runWith($a,fn ()=>$conditional->compose($pageRule))['bodyHtml'];
    $assert(str_contains($render($pageContact,$pageHtml)['bodyHtml'],'Page count fallback'),'Unrecorded page URLs must not match other page history.');
    foreach (['https://user:secret@example.test/','https://example.test/#fragment','file:///tmp/page','https://example.test/a b',''] as $invalid) {$badPage=clone $pageRule; $badPage->pageUrl=$invalid; $blocked(fn ()=>$store->runWith($a,fn ()=>$conditional->compose($badPage)),'Invalid page filters must be rejected.');}
    $pageRule->field='behavior:email.opened';
    $blocked(fn ()=>$store->runWith($a,fn ()=>$conditional->compose($pageRule)),'Email events cannot accept website page filters.');
    $assert((int) $em->getPDO()->query('SELECT COUNT(*) FROM email_queue_item')->fetchColumn()===$queueCount,'Composing and previewing must never enqueue mail.');
    echo "Native conditional content and tenant-scoped preview tests passed.\n";
} catch (Throwable $error) {
    fwrite(STDERR,get_class($error) . ': ' . $error->getMessage() . "\n" . ($error->getPrevious()?->getMessage() ?? '') . "\n" . $error->getTraceAsString() . "\n"); throw $error;
} finally {$tx->rollback();}
