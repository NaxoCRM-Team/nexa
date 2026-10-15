<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__, 2).'/espocrm/bootstrap.php';
set_exception_handler(static function (Throwable $error): void { fwrite(STDERR,$error->getMessage()."\n".$error->getTraceAsString()."\n"); exit(1); });

use Espo\Core\{Application, InjectableFactory};
use Espo\Core\Job\{Job, QueueProcessor, QueueUtil};
use Espo\Core\Job\Job\Data;
use Espo\Core\Job\QueueProcessor\Params;
use Espo\Core\ORM\EntityManager;
use Espo\Core\Tenant\{TenantContext, TenantContextStore, PlatformExecutionGateway};

final class QueueFairnessProbe implements Job
{
    public static array $runs = [];
    public static bool $fail = false;
    public function __construct(private TenantContextStore $contexts) {}
    public function run(Data $data): void
    {
        if (self::$fail) { throw new RuntimeException('Expected queue retry fixture failure.'); }
        $context = $this->contexts->require();
        self::$runs[] = ['tenant'=>$context->tenantId, 'service'=>$context->serviceId, 'ordinal'=>$data->get('ordinal')];
    }
}

$app = new Application(); $app->setupSystemUser(); $container = $app->getContainer();
$em = $container->getByClass(EntityManager::class); $factory = $container->getByClass(InjectableFactory::class);
$contexts = $container->getByClass(TenantContextStore::class); $platform = $container->getByClass(PlatformExecutionGateway::class);
$queue = $factory->create(QueueUtil::class); $processor = $factory->create(QueueProcessor::class);
$checks = 0; $assert = static function (bool $ok, string $message) use (&$checks): void { $checks++; if (!$ok) { throw new RuntimeException($message); } };
$global = fn ($fn) => $platform->run('Rollback-only queue fairness acceptance', $fn);
$pdo = $em->getPDO(); $tx = $em->getTransactionManager(); $tx->start();
$start = microtime(true);
try {
    $suffix = bin2hex(random_bytes(6)); $queueName = 'fair-'.$suffix; $tenants = []; $jobs = [];
    for ($index = 0; $index < 5; $index++) {
        $id = $pdo->query('SELECT UUID()')->fetchColumn();
        $pdo->prepare("INSERT INTO nexa_tenant (id,slug,display_name,status,region,timezone) VALUES (?,?,?,'active','local','UTC')")
            ->execute([$id,'queue-'.$suffix.'-'.$index,'Rollback queue fixture']);
        $pdo->prepare("INSERT INTO nexa_tenant_service (tenant_id,service_id,status) VALUES (?,?,'active')")
            ->execute([$id,TenantContext::CRM_SERVICE_ID]);
        $context = new TenantContext($id,'queue-'.$suffix.'-'.$index,'queue-fairness-test');
        $tenants[] = $context;
        for ($ordinal = 0; $ordinal < ($index === 0 ? 80 : 16); $ordinal++) {
            $jobs[$id][] = $contexts->runWith($context, fn () => $em->createEntity('Job',[
                'name'=>'Queue fixture','queue'=>$queueName,'status'=>'Pending','executeTime'=>gmdate('Y-m-d H:i:s',time()-60),
                'className'=>QueueFairnessProbe::class,'data'=>(object)['ordinal'=>$ordinal],
            ]))->getId();
        }
    }
    $make = fn ($changes) => $contexts->runWith($tenants[0], fn () => $em->createEntity('Job',array_replace([
        'name'=>'Queue exclusion fixture','queue'=>$queueName,'status'=>'Pending','executeTime'=>gmdate('Y-m-d H:i:s',time()-60),
        'className'=>QueueFairnessProbe::class,
    ],$changes)));
    $future = $make(['executeTime'=>gmdate('Y-m-d H:i:s',time()+3600)]);
    $grouped = $make(['group'=>'separate']); $otherQueue = $make(['queue'=>'other-'.$suffix]);
    $removed = $make(['deleted'=>true]);
    $params = Params::create()->withQueue($queueName)->withLimit(2);
    $seen = [];
    for ($round = 0; $round < 30; $round++) {
        $before = count(QueueFairnessProbe::$runs);
        $global(fn () => $processor->process($params));
        $batch = array_slice(QueueFairnessProbe::$runs,$before);
        $assert(count($batch) === 2,'Every bounded round must fill two eligible slots.');
        $assert(count(array_unique(array_column($batch,'tenant'))) === 2,'A busy tenant cannot consume both slots while peers wait.');
        foreach ($batch as $run) {
            $assert($run['service'] === TenantContext::CRM_SERVICE_ID,'Native execution retains service context.');
            $expected = count($seen[$run['tenant']] ?? []);
            $assert($run['ordinal'] === $expected,'Each tenant retains FIFO job ordering without duplicates.');
            $seen[$run['tenant']][] = $run['ordinal'];
        }
        if ($round === 2) { $assert(count($seen) === 5,'All five tenants must run within three two-slot rounds.'); }
    }
    foreach ($tenants as $context) { $assert(count($seen[$context->tenantId]) === 12,'Repeated selection distributes 60 slots equally across five nonempty tenants.'); }
    foreach ([$future,$grouped,$otherQueue,$removed] as $excluded) {
        $status = $pdo->prepare('SELECT status FROM job WHERE id=?');
        $status->execute([$excluded->getId()]);
        $assert($status->fetchColumn() === 'Pending',
            'Not-due, other-group and other-queue jobs must remain untouched.');
    }
    $scope = hash('sha256',json_encode([$queueName,null],JSON_THROW_ON_ERROR));
    $readCursor = static function () use ($pdo,$scope) { $statement=$pdo->prepare('SELECT last_tenant_id FROM nexa_job_dispatch_rotation WHERE scope_key=?');$statement->execute([$scope]);return $statement->fetchColumn(); };
    $cursor = $readCursor();
    $unbounded = $global(fn () => iterator_to_array($queue->getPendingJobList($params->withLimit(0))));
    $expected = [];
    foreach ($tenants as $context) { $expected = array_merge($expected,array_slice($jobs[$context->tenantId],12)); }
    $assert(array_map(fn ($job) => $job->getId(),$unbounded) === $expected,'Unlimited enumeration preserves native global FIFO and all pending rows.');
    $assert($readCursor() === $cursor,'Unlimited enumeration does not consume rotation turns.');
    $onlyGroup = $global(fn () => iterator_to_array($queue->getPendingJobList($params->withGroup('separate'))));
    $assert(count($onlyGroup) === 1 && $onlyGroup[0]->getId() === $grouped->getId(),'A group selects only its own pending rows.');
    $assert($readCursor() === $cursor,'Group rotation is independent of the main queue.');
    $local = $contexts->runWith($tenants[0],fn () => iterator_to_array($queue->getPendingJobList($params)));
    $assert(count($local) === 2 && count(array_filter($local,fn ($job) => $job->get('tenantId') !== $tenants[0]->tenantId)) === 0,'Tenant-local enumeration cannot read another tenant.');
    $assert($readCursor() === $cursor,'Tenant requests cannot change the global rotation cursor.');
    $helper = $factory->create(\Espo\Core\Job\TenantFairQueue::class);
    try {
        $global(fn () => $helper->select($params,static function () { throw new RuntimeException('Expected selection failure.'); }));
        throw new LogicException('Selection failure was swallowed.');
    } catch (RuntimeException $error) { $assert($error->getMessage() === 'Expected selection failure.','Selection errors propagate.'); }
    $assert($readCursor() === $cursor,'Failed selection rolls its cursor work back.');

    $retryQueue = 'retry-'.$suffix;
    $retry = $make(['queue'=>$retryQueue,'attempts'=>1]);
    QueueFairnessProbe::$fail = true;
    $global(fn () => $processor->process(Params::create()->withQueue($retryQueue)->withLimit(1)));
    $assert($global(fn () => $em->getEntityById('Job',$retry->getId()))->get('status') === 'Failed','Native failed-job status is retained.');
    $contexts->runWith($tenants[0],fn () => $queue->updateFailedJobAttempts());
    QueueFairnessProbe::$fail = false;
    $global(fn () => $processor->process(Params::create()->withQueue($retryQueue)->withLimit(1)));
    $assert($global(fn () => $em->getEntityById('Job',$retry->getId()))->get('status') === 'Success','Native retry re-enters fair selection and succeeds.');
    $assert($global(fn () => $em->getEntityById('Job',$retry->getId()))->get('failedAttempts') === 1,'Native retry attempt accounting is retained.');
    echo 'Queue fairness checks passed: '.$checks.'; five tenants, two slots, 30 native rounds; '.round(microtime(true)-$start,3)." seconds (rollback-only).\n";
} finally { QueueFairnessProbe::$fail=false; $tx->rollback(); }
