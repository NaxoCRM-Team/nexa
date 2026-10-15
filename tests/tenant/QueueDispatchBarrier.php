<?php
declare(strict_types=1);

use Espo\Core\Job\QueueUtil;
use Espo\Core\Job\QueueProcessor\Params;
use Espo\ORM\Collection;

/** CLI acceptance only: pause after real selection, before native claim/execution. */
final class QueueDispatchBarrier extends QueueUtil
{
    public function getPendingJobList(Params $params): Collection
    {
        if (PHP_SAPI !== 'cli' || getenv('NEXA_AUTOMATION_ACCEPTANCE') !== '1') {
            throw new RuntimeException('Explicit local acceptance is required.');
        }
        $list = parent::getPendingJobList($params);
        echo "READY\n"; flush();
        if (trim((string) fgets(STDIN)) !== 'GO') { throw new RuntimeException('Queue barrier was not released.'); }
        return $list;
    }
}
