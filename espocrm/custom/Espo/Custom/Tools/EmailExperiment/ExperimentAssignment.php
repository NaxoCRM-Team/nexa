<?php
declare(strict_types=1);
namespace Espo\Custom\Tools\EmailExperiment;

use Espo\Core\Exceptions\BadRequest;

/** Exact-size, stable cohorts; normalize and deduplicate before ranking. */
final class ExperimentAssignment
{
    public static function assign(array $audience, string $seed, int $testPercent, int $holdoutPercent): array
    {
        if ($testPercent < 2 || $testPercent > 100 || $holdoutPercent < 0 || $testPercent + $holdoutPercent > 100) {
            throw new BadRequest('Choose a test size from 2 to 100 percent and a holdout that fits the remaining audience.');
        }
        $unique = [];
        usort($audience, fn(array $a, array $b): int => strcmp($a['contact_id'], $b['contact_id']));
        foreach ($audience as $row) {
            $email = mb_strtolower(trim((string) ($row['email'] ?? '')));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) continue;
            $recipientHash = hash('sha256', $email);
            $unique[$recipientHash] ??= ['contactId'=>$row['contact_id'], 'recipientHash'=>$recipientHash,
                'rank'=>hash_hmac('sha256', $row['contact_id'] . ':' . $recipientHash, $seed)];
        }
        $rows = array_values($unique);
        usort($rows, fn(array $a, array $b): int => strcmp($a['rank'], $b['rank']) ?: strcmp($a['contactId'], $b['contactId']));
        if (count($rows) < 2) throw new BadRequest('An experiment needs at least two distinct eligible email recipients.');
        $testCount = min(count($rows), max(2, (int) floor(count($rows) * $testPercent / 100)));
        $holdoutCount = min(count($rows) - $testCount, (int) floor(count($rows) * $holdoutPercent / 100));
        foreach ($rows as $index => &$row) {
            $row['cohort'] = $index < $testCount ? 'test' : ($index < $testCount + $holdoutCount ? 'holdout' : 'rollout');
            $row['variant'] = $row['cohort'] === 'test' ? ($index % 2 === 0 ? 'A' : 'B') : null;
            unset($row['rank']);
        }
        return $rows;
    }
}
