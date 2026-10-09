<?php
declare(strict_types=1);

// Opt-in: Apache needs committed fixtures, unlike the rollback-only CLI suites.
if (!in_array('--allow-local-fixtures',$argv,true)) {
    fwrite(STDERR,"Run with --allow-local-fixtures against the local WAMP installation.\n");
    exit(2);
}
require dirname(__DIR__,2) . '/espocrm/bootstrap.php';

use Espo\Core\Application;
use Espo\Core\ORM\EntityManager;
use Espo\Core\Tenant\TenantContext;
use Espo\Core\Tenant\TenantContextStore;

$assert = static function (bool $condition,string $message): void {if (!$condition) throw new RuntimeException($message);};
$app = new Application(); $app->setupSystemUser(); $container = $app->getContainer();
$em = $container->getByClass(EntityManager::class); $pdo = $em->getPDO();
$store = $container->getByClass(TenantContextStore::class);
$base = rtrim((string) $container->getByClass(\Espo\Core\Utils\Config::class)->get('siteUrl'),'/');
$url = parse_url($base);
$assert(in_array($url['host'] ?? '',['localhost','127.0.0.1'],true) && in_array($url['scheme'] ?? '',['http','https'],true),'Only a loopback WAMP site URL is allowed.');
$assert(!$pdo->inTransaction(),'Live HTTP fixtures cannot run inside another transaction.');
$run = 'http-api-' . bin2hex(random_bytes(8)); $fixtures = [];
$request = static function (string $method,string $path,?string $key=null,?array $body=null) use ($base): array {
    $headers = ['Accept: application/json'];
    if ($key !== null) $headers[] = 'X-Api-Key: ' . $key;
    if ($body !== null) $headers[] = 'Content-Type: application/json';
    $curl = curl_init($base . '/api/v1/' . $path);
    curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>$headers,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>30,CURLOPT_PROXY=>'']);
    if ($body !== null) curl_setopt($curl,CURLOPT_POSTFIELDS,json_encode((object) $body,JSON_THROW_ON_ERROR));
    try {
        $bytes = curl_exec($curl);
        if ($bytes === false) throw new RuntimeException('Local HTTP request failed (curl error ' . curl_errno($curl) . ').');
        return ['status'=>(int) curl_getinfo($curl,CURLINFO_RESPONSE_CODE),'body'=>json_decode($bytes,true)];
    } finally {curl_close($curl);}
};
try {
    $assert($request('GET','App/user',bin2hex(random_bytes(20)))['status'] === 401,'Invalid API keys must return HTTP 401 before fixtures are created.');
    foreach (['30000000-0000-4000-8000-000000000001'=>'isolation-alpha','30000000-0000-4000-8000-000000000002'=>'isolation-beta'] as $tenantId=>$slug) {
        $tenant = new TenantContext($tenantId,$slug,'live-api-key-fixture');
        $fixture = ['tenant'=>$tenant,'userId'=>null,'roleId'=>null,'name'=>$run . '-' . $slug];
        $fixtures[] = $fixture;
        $fixtureIndex = count($fixtures) - 1;
        $pdo->beginTransaction();
        try {
            $store->runWith($tenant,function () use ($em,$pdo,&$fixture) {
                $role = $em->getNewEntity('Role'); $permissions = new \stdClass();
                foreach (['MassEmail','EmailTemplate','Contact','Attachment'] as $scope) $permissions->{$scope} = (object) ['read'=>'all','create'=>'no','edit'=>'no','delete'=>'no','stream'=>'no'];
                $role->set(['name'=>$fixture['name'],'data'=>$permissions]); $em->saveEntity($role); $fixture['roleId'] = $role->getId();
                $user = $em->getNewEntity('User');
                $user->set(['userName'=>$fixture['name'],'firstName'=>'Temporary','lastName'=>'HTTP fixture','type'=>'api','isActive'=>true]);
                $em->saveEntity($user); $fixture['userId'] = $user->getId();
                $em->getRDBRepository('User')->getRelation($user,'roles')->relate($role);
                $fixture['key'] = bin2hex(random_bytes(20));
                // Avoid native key-management hooks that persist config secrets.
                $pdo->prepare("UPDATE user SET api_key=?,auth_method='ApiKey' WHERE id=? AND tenant_id=? AND service_id=?")->execute([$fixture['key'],$user->getId(),$fixture['tenant']->tenantId,$fixture['tenant']->serviceId]);
            });
            $fixtures[$fixtureIndex] = $fixture;
            $pdo->commit();
        } catch (\Throwable $error) {if ($pdo->inTransaction()) $pdo->rollBack(); throw $error;}
        $authenticated = $request('GET','App/user',$fixture['key']);
        $assert($authenticated['status'] === 200,'Native authenticated App/user must return HTTP 200 for ' . $slug . '; received ' . $authenticated['status'] . '.');
        $assert(($authenticated['body']['user']['id'] ?? null) === $fixture['userId'] && ($authenticated['body']['user']['type'] ?? null) === 'api','The live request must authenticate its exact non-admin API actor.');
        $assert(empty($authenticated['body']['token']),'API-key authentication must not create a browser login token.');
        $baseline = ['contactId'=>'missing-contact','sourceVersion'=>1,'requestKey'=>'http-contract-' . $run];
        foreach ([
            [],
            array_replace($baseline,['sourceVersion'=>'1']),
            array_replace($baseline,['contactId'=>['missing-contact']]),
            array_replace($baseline,['requestKey'=>123]),
            $baseline + ['parameters'=>[]],
            $baseline + ['parameters'=>null],
            $baseline + ['subject'=>'Unapproved override'],
            $baseline + ['tenantId'=>$tenantId],
        ] as $invalid) {
            $response = $request('POST','Nexa/marketing-emails/missing/reusable-send',$fixture['key'],$invalid);
            $assert($response['status'] === 400,'Malformed reusable-send input must return HTTP 400, not a server error or redirect.');
        }
        $ledger = $pdo->prepare('SELECT COUNT(*) FROM nexa_marketing_send_request WHERE tenant_id=? AND service_id=? AND created_by_id=?');
        $ledger->execute([$tenantId,$tenant->serviceId,$fixture['userId']]);
        $assert((int) $ledger->fetchColumn() === 0,'Rejected HTTP payloads must create no reusable-send ledger entries.');
        $assert($request('GET','App/user',strtoupper($fixture['key']))['status'] === 401,'Case-changed API keys must fail live authentication.');
        foreach ([['POST','Nexa/marketing-emails',[]],['POST','Nexa/marketing-emails/missing/transition',['action'=>'approve']],['GET','Nexa/marketing-emails/missing/integration-grants',null],['POST','Contact',['firstName'=>'Must not create']]] as [$method,$path,$body]) {
            $assert($request($method,$path,$fixture['key'],$body)['status'] === 403,'A live read-only API user must be forbidden from ' . $path . '.');
        }
        $denied = $request('POST','Nexa/marketing-emails/missing/reusable-send',$fixture['key'],['contactId'=>'missing-contact','sourceVersion'=>1,'requestKey'=>'http-no-delivery-' . $run]);
        $assert(in_array($denied['status'],[403,404],true),'Missing source/grant must reject live reusable-send requests without creating a queue.');
        $ledger->execute([$tenantId,$tenant->serviceId,$fixture['userId']]);
        $assert((int) $ledger->fetchColumn() === 0,'Missing-source HTTP requests must leave no reusable-send ledger entries.');
        $pdo->prepare('UPDATE user SET is_active=0 WHERE id=? AND tenant_id=? AND service_id=?')->execute([$fixture['userId'],$tenantId,$tenant->serviceId]);
        $assert($request('GET','App/user',$fixture['key'])['status'] === 401,'Disabling a live API user must immediately block its key.');
        unset($fixture);
    }
    echo "Live localhost HTTP API-key authentication and read-only permission tests passed for both tenants.\n";
} finally {
    // Preserve append-only authentication audit; remove only this run's identities and keys.
    foreach ($fixtures as $fixture) {
        if ($fixture['userId']) $pdo->prepare('UPDATE user SET is_active=0,api_key=NULL WHERE id=? AND user_name=? AND tenant_id=? AND service_id=?')->execute([$fixture['userId'],$fixture['name'],$fixture['tenant']->tenantId,$fixture['tenant']->serviceId]);
    }
    foreach ($fixtures as $fixture) {
        $tenant = $fixture['tenant'];
        if ($fixture['userId']) {
            $pdo->prepare('DELETE FROM role_user WHERE user_id=? AND role_id=? AND tenant_id=? AND service_id=?')->execute([$fixture['userId'],$fixture['roleId'],$tenant->tenantId,$tenant->serviceId]);
            $pdo->prepare('DELETE FROM preferences WHERE id=? AND tenant_id=? AND service_id=?')->execute([$fixture['userId'],$tenant->tenantId,$tenant->serviceId]);
            $pdo->prepare('DELETE FROM user_data WHERE user_id=? AND tenant_id=? AND service_id=?')->execute([$fixture['userId'],$tenant->tenantId,$tenant->serviceId]);
            $pdo->prepare('DELETE FROM user WHERE id=? AND user_name=? AND tenant_id=? AND service_id=?')->execute([$fixture['userId'],$fixture['name'],$tenant->tenantId,$tenant->serviceId]);
        }
        if ($fixture['roleId']) $pdo->prepare('DELETE FROM role WHERE id=? AND name=? AND tenant_id=? AND service_id=?')->execute([$fixture['roleId'],$fixture['name'],$tenant->tenantId,$tenant->serviceId]);
    }
    $remaining = $pdo->prepare('SELECT COUNT(*) FROM user WHERE user_name LIKE ?'); $remaining->execute([$run . '%']);
    $assert((int) $remaining->fetchColumn() === 0,'Temporary HTTP API identities must not remain after cleanup.');
    $remaining = $pdo->prepare('SELECT COUNT(*) FROM role WHERE name LIKE ?'); $remaining->execute([$run . '%']);
    $assert((int) $remaining->fetchColumn() === 0,'Temporary HTTP role fixtures must not remain after cleanup.');
}
