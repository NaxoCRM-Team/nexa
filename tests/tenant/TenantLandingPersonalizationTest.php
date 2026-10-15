<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/espocrm/bootstrap.php';

use Espo\Core\{Application, InjectableFactory};
use Espo\Core\ORM\EntityManager;
use Espo\Core\Tenant\{TenantContext, TenantContextStore};
use Espo\Core\Exceptions\{BadRequest, Forbidden, NotFound};
use Espo\Custom\Tools\LandingPage\{LandingPageService, LandingPageRenderer, LandingPagePersonalization};

$checks = 0;
$assert = function (bool $ok, string $message) use (&$checks): void { $checks++; if (!$ok) throw new RuntimeException($message); };
$deny = function (callable $fn, string $message) use ($assert): void {
    $denied = false; try {$fn();} catch (BadRequest|Forbidden|NotFound) {$denied = true;} $assert($denied, $message);
};
$rule = ['field'=>'utm_source', 'value'=>'newsletter', 'heading'=>'Newsletter offer', 'text'=>'Welcome newsletter readers.'];
$block = ['id'=>'hero', 'type'=>'hero', 'heading'=>'Default offer', 'text'=>'Everyone is welcome.', 'personalization'=>$rule];
$assert(LandingPagePersonalization::decide($block, [])['evidence']['branch'] === 'default', 'Missing context must use default content.');
$assert(LandingPagePersonalization::decide($block, ['utm_source'=>'newsletter'])['evidence']['matched'], 'Exact contextual values must match.');
$assert(!LandingPagePersonalization::decide($block, ['utm_source'=>['newsletter']])['evidence']['matched'], 'Array query inputs must fail closed.');
$assert(!LandingPagePersonalization::decide($block, ['utm_source'=>str_repeat('n',101)])['evidence']['matched'], 'Oversized context must not be evaluated.');
$assert(!LandingPagePersonalization::decide($block, ['utm_source'=>'newsletter'], true)['evidence']['matched'], 'GPC must suppress campaign context variants.');
$assert(!LandingPagePersonalization::decide($block, ['contactId'=>'newsletter'])['evidence']['matched'], 'CRM identity parameters must not select content.');
foreach (['contactId','email','tenantId','leadScore'] as $field) $deny(fn()=>LandingPagePersonalization::normalize(['field'=>$field]+array_diff_key($rule,['field'=>true]),'hero'),'CRM fields are not public contexts.');
$deny(fn()=>LandingPagePersonalization::normalize($rule,'form'),'Form bindings cannot be changed by contextual variants.');
$deny(fn()=>LandingPagePersonalization::normalize($rule+['buttonUrl'=>'https://example.test'],'hero'),'Contextual variants cannot add redirect destinations.');
$deny(fn()=>LandingPagePersonalization::normalize(['field'=>'utm_source','value'=>'','heading'=>'Offer','text'=>''],'hero'),'Comparison values must not be empty.');
$customerBlock = ['id'=>'customer-hero','type'=>'hero','heading'=>'Public offer','text'=>'Default content.',
    'personalization'=>['field'=>'customer:leadScore','operator'=>'greater_than','value'=>'50','heading'=>'Customer offer','text'=>'Private variant.']];
$assert(!LandingPagePersonalization::decide($customerBlock,['customer:leadScore'=>100])['evidence']['matched'], 'Public contexts cannot spoof customer profile values.');
$assert(!LandingPagePersonalization::customerDecision($customerBlock,100,false)['evidence']['matched'], 'Ineligible customer proof must fail closed.');
$assert(LandingPagePersonalization::customerDecision($customerBlock,51,true)['evidence']['matched'], 'Eligible numeric customer rules must use the configured comparison.');
$assert(!LandingPagePersonalization::customerDecision($customerBlock,50,true)['evidence']['matched'], 'Numeric comparison boundaries must remain exact.');
$visits=$customerBlock;$visits['personalization']['field']='customer:visits30';
$assert(LandingPagePersonalization::customerDecision($visits,null,true,true)['evidence']['matched'], 'Trusted native behavior outcomes can select a variant.');
$assert(!LandingPagePersonalization::customerDecision($visits,null,false,true)['evidence']['matched'], 'Behavior outcomes cannot bypass eligibility.');
$invalid=$customerBlock['personalization'];$invalid['value']='-1';
$deny(fn()=>LandingPagePersonalization::normalize($invalid,'hero'),'Negative customer thresholds must be rejected.');
$invalid['value']='1.5';
$deny(fn()=>LandingPagePersonalization::normalize($invalid,'hero'),'Fractional customer thresholds must be rejected.');
$invalid['field']='customer:visits30';$invalid['value']='10000';
$deny(fn()=>LandingPagePersonalization::normalize($invalid,'hero'),'Behavior thresholds must stay within the native counter bound.');

$app = new Application(); $app->setupSystemUser(); $container = $app->getContainer();
$em = $container->getByClass(EntityManager::class); $factory = $container->getByClass(InjectableFactory::class);
$contexts = $container->getByClass(TenantContextStore::class);
$a = new TenantContext('30000000-0000-4000-8000-000000000001','isolation-alpha','landing-personalization-test');
$b = new TenantContext('30000000-0000-4000-8000-000000000002','isolation-beta','landing-personalization-test');
$call = fn($fn)=>$contexts->runWith($a,$fn); $tx = $em->getTransactionManager(); $tx->start();
try {
    $service = $factory->create(LandingPageService::class); $renderer = $factory->create(LandingPageRenderer::class);
    $input = (object)['name'=>'Context test','slug'=>'context-'.bin2hex(random_bytes(5)), 'locale'=>'en', 'seoTitle'=>'Context test', 'blocks'=>[(object)$block]];
    $created = $call(fn()=>$service->save($input)); $publication = $call(fn()=>$service->publish($created['id']));
    preg_match('#/p/([a-f0-9]{48})/([^/]+)$#',$publication['url'],$url);
    $render = fn($context=[], $gpc=false)=>$call(fn()=>$renderer->render($url[1],$url[2],$context,$gpc));
    $assert(str_contains($render(),'Default offer'), 'Anonymous pages retain default content.');
    $variant = $render(['utm_source'=>'newsletter']);
    $assert(str_contains($variant,'Newsletter offer') && !str_contains($variant,'Default offer'), 'Published variants must replace only configured content.');
    $assert(str_contains($render(['utm_source'=>'newsletter'],true),'Default offer'), 'Published rendering must honor GPC.');
    $assert(str_contains($render(['contactId'=>'newsletter']),'Default offer'), 'Public CRM identity spoofing must not change output.');
    $input->blocks[0]->personalization['heading'] = '<script>alert(1)</script>';
    $call(fn()=>$service->save($input,$created['id']));
    $assert(str_contains($render(['utm_source'=>'newsletter']),'Newsletter offer'), 'Draft changes must not rewrite the published snapshot.');
    $call(fn()=>$service->publish($created['id']));
    $html = $render(['utm_source'=>'newsletter']);
    $assert(str_contains($html,'&lt;script&gt;') && !str_contains($html,'<script>alert(1)</script>'), 'Variant content must be escaped by the existing renderer.');
    $query = $em->getPDO()->prepare("SELECT metadata_json FROM nexa_audit_event WHERE tenant_id=? AND service_id=? AND subject_id=? AND action='personalization.rendered'");
    $query->execute([$a->tenantId,$a->serviceId,$created['id']]); $audit = array_map(fn($json)=>json_decode($json,true),$query->fetchAll(PDO::FETCH_COLUMN));
    $assert(count($audit)===6 && count(array_filter($audit,fn($item)=>$item['pageVersion']===2))===1, 'Decisions must retain the exact published version.');
    $assert(!array_key_exists('actual',$audit[0]['decisions'][0]), 'Audits must not retain arbitrary raw visitor query values.');
    $page = $call(fn()=>$service->published($url[1],$url[2]))['page'];
    $deny(fn()=>$contexts->runWith($b,fn()=>$service->recordPersonalization($page,[])), 'Foreign workspaces cannot append page decision evidence.');
    $deny(fn()=>$contexts->runWith($b,fn()=>$service->save($input,$created['id'])), 'Foreign tenants cannot alter the page variant.');
    $call(fn()=>$service->archive($created['id']));
    $deny(fn()=>$render(['utm_source'=>'newsletter']), 'Archived pages cannot deliver variants.');
    echo "Landing personalization checks passed: $checks (rollback-only).\n";
} catch (Throwable $error) {
    fwrite(STDERR, get_class($error) . ': ' . $error->getMessage() . "\n" . $error->getTraceAsString() . "\n");
    throw $error;
} finally {$tx->rollback();}
