<?php

namespace Espo\Custom\Jobs;

use Espo\Core\Job\JobDataLess;
use Espo\Core\ORM\EntityManager;
use Espo\Core\Utils\Log;
use Espo\Custom\Tools\Call\CallService;
use PDO;
use Throwable;

/**
 * Safety net for the calling-minutes reservation system: a call session that
 * never reaches a terminal status (browser crash mid-call, Twilio webhook
 * never arrives) would otherwise hold its reserved minutes forever, slowly
 * shrinking the tenant's usable pool. Finds sessions stuck in a non-terminal
 * status well past any real call's duration and force-fails them via
 * CallService::forceFailStaleSession(), which shares the same guarded
 * finalize path as a real webhook - so a webhook that arrives late for a
 * session this job already closed is a no-op, never a double-release.
 *
 * @noinspection PhpUnused
 */
class ReleaseStaleCallReservations implements JobDataLess
{
    private const STALE_AFTER_HOURS = 2;

    public function __construct(
        private EntityManager $entityManager,
        private CallService $callService,
        private Log $log,
    ) {}

    public function run(): void
    {
        // STALE_AFTER_HOURS is a fixed internal constant, not user input - safe
        // to inline directly rather than rely on a bound parameter working
        // correctly inside an INTERVAL expression.
        $hours = self::STALE_AFTER_HOURS;
        $statement = $this->entityManager->getPDO()->prepare(
            "SELECT id FROM nexa_call_session " .
            "WHERE status NOT IN ('completed', 'no-answer', 'busy', 'failed', 'canceled') " .
            "AND created_at < (NOW(6) - INTERVAL {$hours} HOUR)"
        );
        $statement->execute();

        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $sessionId) {
            try {
                $this->callService->forceFailStaleSession((string) $sessionId);
            } catch (Throwable $e) {
                $this->log->error(
                    'Job ReleaseStaleCallReservations: session ' . $sessionId .
                    ': [' . $e->getCode() . '] ' . $e->getMessage()
                );
            }
        }
    }
}
