<?php
declare(strict_types=1);
namespace Espo\Custom\Tools\Conversation;

use Espo\Core\Exceptions\BadRequest;

/** Nexa reconciliation policy for duplicate and out-of-order provider receipts. */
final class MessagingDeliveryState
{
    public static function reconcile(string $current, string $incoming): string
    {
        $progress=['accepted'=>0,'scheduled'=>1,'queued'=>2,'sending'=>3,'sent'=>4,'delivered'=>5,'read'=>6];
        $failed=['failed','undelivered','canceled'];
        foreach ([$current,$incoming] as $state) if (!isset($progress[$state])&&!in_array($state,$failed,true)) throw new BadRequest('Unsupported delivery state.');
        if (in_array($incoming,['delivered','read'],true)) {
            return isset($progress[$current]) && $progress[$current]>$progress[$incoming] ? $current : $incoming;
        }
        if (in_array($current,['delivered','read'],true) || in_array($current,$failed,true)) return $current;
        if (in_array($incoming,$failed,true)) return $incoming;
        return $progress[$incoming]>$progress[$current] ? $incoming : $current;
    }
}
