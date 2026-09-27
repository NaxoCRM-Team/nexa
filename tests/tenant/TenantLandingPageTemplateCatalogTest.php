<?php
declare(strict_types=1);

$root=dirname(__DIR__,2); require $root.'/espocrm/bootstrap.php';

use Espo\Core\Application;
use Espo\Core\InjectableFactory;
use Espo\Core\ORM\EntityManager;
use Espo\Core\Tenant\TenantContext;
use Espo\Core\Tenant\TenantContextStore;
use Espo\Custom\Tools\LandingPage\LandingPageRenderer;
use Espo\Custom\Tools\LandingPage\LandingPageService;

$assert=static function(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);};
$context=new TenantContext('30000000-0000-4000-8000-000000000001','isolation-alpha','landing-template-test');
$app=new Application(); $app->setupSystemUser(); $container=$app->getContainer();
$em=$container->getByClass(EntityManager::class); $store=$container->getByClass(TenantContextStore::class); $factory=$container->getByClass(InjectableFactory::class);
$tx=$em->getTransactionManager(); $tx->start();

try {
    $workspace=$store->runWith($context,fn():array=>$factory->create(LandingPageService::class)->getWorkspace());
    $assert(count($workspace['templates']??[])===4,'The workspace must expose four professional templates.');
    foreach($workspace['templates'] as $index=>$template){
        $config=$template['configuration']; $config['name'].=' Test '.($index+1); $config['slug'].='-test-'.($index+1);
        $created=$store->runWith($context,fn():array=>$factory->create(LandingPageService::class)->save((object)$config));
        $assert(!empty($created['id']),'A template could not be copied into a tenant draft.');
        $assert(count($config['blocks']??[])>=6,'A template is not a complete page.');
        if($index===0){
            $config['blocks']=array_values(array_filter($config['blocks'],static fn(array $block):bool=>$block['type']!=='form'));
            $store->runWith($context,fn():array=>$factory->create(LandingPageService::class)->save((object)$config,(string)$created['id']));
            $published=$store->runWith($context,fn():array=>$factory->create(LandingPageService::class)->publish((string)$created['id']));
            preg_match('#/p/([a-f0-9]{48})/([^/]+)$#',(string)$published['url'],$matches);
            $html=$store->runWith($context,fn():string=>$factory->create(LandingPageRenderer::class)->render($matches[1],$matches[2]));
            $assert(str_contains($html,'feature-grid')&&str_contains($html,'request-demo.jpg'),'Rich template sections or photography did not render.');
        }
    }
    $tx->rollback();
} catch(Throwable $e){if($tx->isStarted())$tx->rollback();throw $e;}

echo "Tenant landing page template catalogue tests passed.\n";
