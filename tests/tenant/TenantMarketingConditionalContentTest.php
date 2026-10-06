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
    $assert((int) $em->getPDO()->query('SELECT COUNT(*) FROM email_queue_item')->fetchColumn()===$queueCount,'Composing and previewing must never enqueue mail.');
    echo "Native conditional content and tenant-scoped preview tests passed.\n";
} catch (Throwable $error) {
    fwrite(STDERR,get_class($error) . ': ' . $error->getMessage() . "\n" . ($error->getPrevious()?->getMessage() ?? '') . "\n" . $error->getTraceAsString() . "\n"); throw $error;
} finally {$tx->rollback();}
