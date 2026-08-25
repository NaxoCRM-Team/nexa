<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root . '/espocrm/bootstrap.php';

use Espo\Core\Application;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\InjectableFactory;
use Espo\Core\ORM\EntityManager;
use Espo\Core\Tenant\TenantContext;
use Espo\Core\Tenant\TenantContextStore;
use Espo\Custom\Tools\Customer\CustomerFoundationQueryService;
use Espo\Custom\Tools\Customization\CustomizationService;

$assert = static function (bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); };
$shortId = static fn(string $prefix): string => substr($prefix . bin2hex(random_bytes(8)), 0, 17);
$requiredValues = static function (CustomizationService $service, string $entityType, string $suffix): array {
    $values = [];
    foreach ($service->definitions($entityType)['fields'] as $field) {
        if (empty($field['is_required'])) continue;
        $values[$field['field_key']] = match ($field['data_type']) {
            'number', 'currency' => 1,
            'date' => '2026-01-01',
            'datetime' => '2026-01-01 10:00:00',
            'boolean' => true,
            'single_select' => $field['options'][0] ?? 'Test',
            'multi_select' => isset($field['options'][0]) ? [$field['options'][0]] : [],
            'email' => "customization-{$suffix}@example.test",
            'url' => 'https://example.test/' . $suffix,
            default => 'TEST-' . $suffix,
        };
    }
    return $values;
};
$suffix = strtolower(bin2hex(random_bytes(4)));
$tenantA = new TenantContext('30000000-0000-4000-8000-000000000001', 'isolation-alpha', 'tenant-customization-test');
$tenantB = new TenantContext('30000000-0000-4000-8000-000000000002', 'isolation-beta', 'tenant-customization-test');

$application = new Application();
$application->setupSystemUser();
$container = $application->getContainer();
$entityManager = $container->getByClass(EntityManager::class);
$store = $container->getByClass(TenantContextStore::class);
$factory = $container->getByClass(InjectableFactory::class);
$service = $factory->create(CustomizationService::class);
$customer = $factory->create(CustomerFoundationQueryService::class);
$pdo = $entityManager->getPDO();
$contactA = $shortId('nxcusta'); $contactB = $shortId('nxcustb');
$fieldKey = 'member_' . $suffix; $objectKey = 'asset_' . $suffix; $relationshipKey = 'contact_asset_' . $suffix;

$pdo->beginTransaction();
try {
    $store->runWith($tenantA, function () use ($entityManager,$service,$pdo,$shortId,$assert,$requiredValues,$suffix,$contactA,$fieldKey,$objectKey,$relationshipKey,&$recordA,&$relationship): void {
        $entityManager->createEntity('Contact',['id'=>$contactA,'firstName'=>'Tenant','lastName'=>'Alpha']);
        $service->saveDefinition('field',['entityType'=>'Contact','fieldKey'=>$fieldKey,'label'=>'Membership','dataType'=>'text','isRequired'=>true]);
        $service->saveValues('Contact',$contactA,[...$requiredValues($service,'Contact','alpha-'.$suffix),$fieldKey=>'ALPHA-001']);
        $service->saveDefinition('entity',['entityKey'=>$objectKey,'label'=>'Asset','pluralLabel'=>'Assets']);
        $service->saveDefinition('field',['entityType'=>$objectKey,'fieldKey'=>'serial_number','label'=>'Serial number','dataType'=>'text','isRequired'=>true,'isUnique'=>true]);
        $invalidId = $shortId('nxbad');
        $rejected = false;
        try {
            $service->saveRecord($objectKey,['id'=>$invalidId,'displayName'=>'Incomplete asset','values'=>[]]);
        } catch (BadRequest) {
            $rejected = true;
        }
        $assert($rejected,'A custom record without its required property was accepted.');
        $statement = $pdo->prepare('SELECT COUNT(*) FROM nexa_custom_record WHERE id=?');
        $statement->execute([$invalidId]);
        $assert((int)$statement->fetchColumn()===0,'A failed custom-record save left a partial row behind.');
        // Slim parses a nested JSON object as stdClass, so exercise the real HTTP payload shape.
        $recordA=$service->saveRecord($objectKey,['displayName'=>'Alpha asset','values'=>(object)['serial_number'=>'ASSET-A']]);
        $relationship=$service->saveDefinition('relationship',['relationshipKey'=>$relationshipKey,'label'=>'Assets','inverseLabel'=>'Contacts','sourceEntityType'=>'Contact','targetEntityType'=>$objectKey,'cardinality'=>'many_to_many']);
        $service->link(['relationshipDefinitionId'=>$relationship['id'],'sourceEntityId'=>$contactA,'targetEntityId'=>$recordA['id']]);
    });
    $store->runWith($tenantB, function () use ($entityManager,$service,$requiredValues,$suffix,$contactB,$fieldKey,$objectKey): void {
        $entityManager->createEntity('Contact',['id'=>$contactB,'firstName'=>'Tenant','lastName'=>'Beta']);
        $service->saveDefinition('field',['entityType'=>'Contact','fieldKey'=>$fieldKey,'label'=>'Membership','dataType'=>'text','isRequired'=>true]);
        $service->saveValues('Contact',$contactB,[...$requiredValues($service,'Contact','beta-'.$suffix),$fieldKey=>'BETA-001']);
        $service->saveDefinition('entity',['entityKey'=>$objectKey,'label'=>'Asset','pluralLabel'=>'Assets']);
    });

    $alpha=$store->runWith($tenantA,fn():array=>$service->values('Contact',$contactA));
    $beta=$store->runWith($tenantB,fn():array=>$service->values('Contact',$contactB));
    $assert($alpha['values'][$fieldKey]==='ALPHA-001','Tenant A custom value is incorrect.');
    $assert($beta['values'][$fieldKey]==='BETA-001','Tenant B custom value is incorrect.');
    $assert(count($store->runWith($tenantA,fn():array=>$service->definitions('Contact'))['fields'])>=1,'Tenant A field definition is missing.');
    $assert(count($store->runWith($tenantB,fn():array=>$service->definitions('Contact'))['fields'])>=1,'Tenant B field definition is missing.');

    $recordList = $store->runWith($tenantA, fn(): array => $service->records($objectKey, 0, 25, 'Alpha'));
    $assert($recordList['total'] === 1, 'The normal custom-object list did not find the tenant record.');
    $assert(($recordList['records'][0]['values']['serial_number'] ?? null) === 'ASSET-A', 'The normal list omitted configured custom values.');
    $recordWorkspace = $store->runWith($tenantA, fn(): array => $service->recordWorkspace($objectKey, $recordA['id']));
    $assert($recordWorkspace['record']['display_name'] === 'Alpha asset', 'The normal detail workspace loaded the wrong record.');
    $assert(($recordWorkspace['values']['serial_number'] ?? null) === 'ASSET-A', 'The normal detail workspace omitted custom values.');
    $foreignList = $store->runWith($tenantB, fn(): array => $service->records($objectKey, 0, 25));
    $assert($foreignList['total'] === 0, 'Tenant B listed Tenant A custom records.');

    $denied=false;
    try { $store->runWith($tenantA,fn():array=>$service->values('Contact',$contactB)); } catch (Forbidden) { $denied=true; }
    $assert($denied,'Tenant A could read Tenant B custom values.');
    $snapshot=$store->runWith($tenantA,fn():array=>$customer->getSnapshot('Contact',$contactA));
    $customLinks=array_filter($snapshot['relationships'],static fn(array $row):bool=>!empty($row['custom']));
    $assert(count($customLinks)===1,'Customer 360 omitted the tenant custom relationship.');
    $workspace=$store->runWith($tenantA,fn():array=>$service->relationshipWorkspace($relationship['id'],$objectKey,$recordA['id'],'Tenant'));
    $assert(count($workspace['links'])===1,'The association workspace omitted the connected Contact.');
    $assert($workspace['links'][0]['entityId']===$contactA,'The association workspace resolved the wrong Contact.');
    $candidateIds=array_column($workspace['candidates'],'id');
    $assert(!in_array($contactB,$candidateIds,true),'Tenant B Contact appeared in Tenant A association candidates.');

    $foreignDefinitionDenied=false;
    try {
        $store->runWith($tenantB,fn():array=>$service->relationshipWorkspace($relationship['id'],$objectKey,$recordA['id']));
    } catch (\Espo\Core\Exceptions\NotFound) {
        $foreignDefinitionDenied=true;
    }
    $assert($foreignDefinitionDenied,'Tenant B opened Tenant A relationship workspace.');

    $store->runWith($tenantA, function () use ($entityManager,$service,$contactA,$objectKey,$recordA,$suffix,$shortId,$assert): void {
        $secondContact = $shortId('nxcard');
        $entityManager->createEntity('Contact',['id'=>$secondContact,'firstName'=>'Cardinality','lastName'=>'Check']);
        $definition = $service->saveDefinition('relationship',[
            'relationshipKey'=>'owned_asset_'.$suffix,
            'label'=>'Owned assets',
            'inverseLabel'=>'Owner',
            'sourceEntityType'=>'Contact',
            'targetEntityType'=>$objectKey,
            'cardinality'=>'one_to_many',
        ]);
        $service->link(['relationshipDefinitionId'=>$definition['id'],'sourceEntityId'=>$contactA,'targetEntityId'=>$recordA['id']]);
        $rejected = false;
        try {
            $service->link(['relationshipDefinitionId'=>$definition['id'],'sourceEntityId'=>$secondContact,'targetEntityId'=>$recordA['id']]);
        } catch (BadRequest) {
            $rejected = true;
        }
        $assert($rejected,'One-to-many cardinality allowed the same target to be linked to two sources.');
    });

    $store->runWith($tenantA, function () use ($service,$relationship,$objectKey,$recordA,$assert): void {
        $workspace=$service->relationshipWorkspace($relationship['id'],$objectKey,$recordA['id']);
        $service->unlink($workspace['links'][0]['id']);
        $after=$service->relationshipWorkspace($relationship['id'],$objectKey,$recordA['id']);
        $assert($after['links']===[],'The removed association remained visible.');
    });

    echo "Tenant customization runtime isolation tests passed.\n";
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
}
