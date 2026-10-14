<?php
declare(strict_types=1);

require dirname(__DIR__,2) . '/espocrm/bootstrap.php';

use Espo\Core\Application;
use Espo\Core\Api\RequestWrapper;
use Espo\Core\Authentication\Login\Data;
use Espo\Core\Authentication\Logins\ApiKey;
use Espo\Core\InjectableFactory;
use Espo\Core\ORM\EntityManager;
use Espo\Core\Tenant\Api\TenantContextMiddleware;
use Espo\Core\Tenant\TenantContext;
use Espo\Core\Tenant\TenantContextStore;
use Espo\Core\Tenant\TenantResolver;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

$assert = static function (bool $value,string $message): void {if (!$value) throw new RuntimeException($message);};
$app = new Application(); $app->setupSystemUser(); $container = $app->getContainer();
$em = $container->getByClass(EntityManager::class); $pdo = $em->getPDO();
$store = $container->getByClass(TenantContextStore::class);
$factory = $container->getByClass(InjectableFactory::class);
$resolver = $container->getByClass(TenantResolver::class);
$middleware = $factory->create(TenantContextMiddleware::class);
$tenants = [new TenantContext('30000000-0000-4000-8000-000000000001','isolation-alpha','api-key-fixture'),new TenantContext('30000000-0000-4000-8000-000000000002','isolation-beta','api-key-fixture')];
$transaction = $em->getTransactionManager(); $transaction->start();
try {
    $keys = []; $users = [];
    foreach ($tenants as $tenant) {
        $user = $store->runWith($tenant,function () use ($em) {
            $user = $em->getNewEntity('User');
            $user->set(['userName'=>'api-auth-' . bin2hex(random_bytes(8)),'type'=>'api','isActive'=>true]);
            $em->saveEntity($user); return $user;
        });
        $key = bin2hex(random_bytes(20));
        // Do not invoke the native key-management hook: it writes persistent config secrets.
        $pdo->prepare("UPDATE user SET api_key=?,auth_method='ApiKey' WHERE id=? AND tenant_id=? AND service_id=?")->execute([$key,$user->getId(),$tenant->tenantId,$tenant->serviceId]);
        $keys[] = $key; $users[] = $user;
    }
    $request = static fn (string $key,string $host='localhost') => (new ServerRequestFactory())->createServerRequest('POST','http://' . $host . '/nexa/api/v1/Nexa/marketing-emails/source/reusable-send')->withHeader('X-Api-Key',$key);
    foreach ($tenants as $index=>$tenant) {
        $calls = 0;
        $handler = new class($store,$factory,$assert,$tenant,$users[$index]->getId(),$calls) implements RequestHandlerInterface {
            public function __construct(private TenantContextStore $store,private InjectableFactory $factory,private Closure $assert,private TenantContext $tenant,private string $id,private int &$calls) {}
            public function handle(ServerRequestInterface $request): ResponseInterface {
                $this->calls++;
                ($this->assert)($this->store->require()->tenantId === $this->tenant->tenantId && $this->store->require()->serviceId === $this->tenant->serviceId,'API credentials must resolve their own tenant/service on a shared host.');
                $login = $this->factory->create(ApiKey::class);
                $result = $login->login(Data::createBuilder()->build(),new RequestWrapper($request,'',[]));
                ($this->assert)($result->isSuccess() && $result->getUser()?->getId() === $this->id,'Native API-key login must authenticate the same tenant-scoped API user.');
                $acl = $this->factory->createWith(\Espo\Core\Acl::class,['user'=>$result->getUser()]);
                ($this->assert)(is_bool($acl->checkScope('NexaSegments','read')),'The Segments workspace scope must support native non-admin permission checks.');
                $wrongCase = $login->login(Data::createBuilder()->build(),new RequestWrapper($request->withHeader('X-Api-Key',strtoupper($request->getHeaderLine('X-Api-Key'))),'',[]));
                ($this->assert)($wrongCase->isFail(),'Native key login must reject changed case even under a case-insensitive database collation.');
                return new Response(200);
            }
        };
        $assert($middleware->process($request($keys[$index]),$handler)->getStatusCode() === 200 && $calls === 1,'Valid API credentials must reach the protected handler.');
        $assert($store->current() === null,'Request tenant context must be restored after API authentication.');
        foreach (['',bin2hex(random_bytes(20)),strtoupper($keys[$index]),$keys[$index] . 'x'] as $invalid) {
            $assert($middleware->process($request($invalid),$handler)->getStatusCode() === 401 && $calls === 1,'Invalid or case-changed API keys must fail closed before the handler.');
        }
        $assert($resolver->resolveApiKey($keys[$index] . ' ') === null,'Raw keys containing whitespace must not resolve a tenant.');
        $assert($middleware->process($request($keys[$index])->withAddedHeader('X-Api-Key',$keys[$index]),$handler)->getStatusCode() === 401,'Ambiguous repeated credential headers must be rejected.');
        $pdo->prepare('UPDATE user SET is_active=0 WHERE id=?')->execute([$users[$index]->getId()]);
        $assert($middleware->process($request($keys[$index]),$handler)->getStatusCode() === 401,'Inactive API users must not resolve an authenticated workspace.');
        $pdo->prepare("UPDATE user SET is_active=1,auth_method='Hmac' WHERE id=?")->execute([$users[$index]->getId()]);
        $assert($middleware->process($request($keys[$index]),$handler)->getStatusCode() === 401,'An HMAC key must not be accepted as a plain API-key credential.');
        $pdo->prepare("UPDATE user SET auth_method='ApiKey',deleted=1 WHERE id=?")->execute([$users[$index]->getId()]);
        $assert($middleware->process($request($keys[$index]),$handler)->getStatusCode() === 401,'Deleted API users must not resolve a workspace.');
        $pdo->prepare('UPDATE user SET deleted=0 WHERE id=?')->execute([$users[$index]->getId()]);
    }
    $pdo->prepare('UPDATE user SET api_key=? WHERE id=?')->execute([$keys[0],$users[1]->getId()]);
    $assert($resolver->resolveApiKey($keys[0]) === null,'Duplicate API credentials across tenants must fail closed rather than select the first tenant.');
    echo "Native API-key login and shared-host tenant middleware tests passed.\n";
} finally {$transaction->rollback();}
