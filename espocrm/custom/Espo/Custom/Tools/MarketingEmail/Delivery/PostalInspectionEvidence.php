<?php
declare(strict_types=1);
namespace Espo\Custom\Tools\MarketingEmail\Delivery;

use Espo\Core\Exceptions\BadRequest;

final class PostalInspectionEvidence
{
    public static function verify(array $data, int $recordId, string $queueId, string $recipient, string $sender): array
    {
        $details = $data['details'] ?? [];
        $headers = $data['headers'] ?? [];
        if ($recordId < 1 || $queueId === '' || !is_array($details) || !is_array($headers)) throw new BadRequest('Postal evidence is incomplete. The reservation remains held.');
        $queueHeaders = [];
        foreach (is_array($headers) ? $headers : [] as $name=>$value) {
            if (strtolower((string) $name) === 'x-nexa-queue-item') $queueHeaders = [...$queueHeaders, ...(is_array($value) ? $value : [$value])];
        }
        $messageId = $details['message_id'] ?? null;
        if (($data['id'] ?? null) !== $recordId || !is_array($details) || ($details['direction'] ?? null) !== 'outgoing'
            || count($queueHeaders) !== 1 || !is_string($queueHeaders[0]) || trim($queueHeaders[0]) !== $queueId
            || strcasecmp((string) ($details['rcpt_to'] ?? ''), $recipient) !== 0
            || strcasecmp((string) ($details['mail_from'] ?? ''), $sender) !== 0
            || !is_string($messageId) || $messageId === '' || strlen($messageId) > 255 || preg_match('/[\x00-\x1f]/', $messageId)) {
            throw new BadRequest('Postal evidence does not match this exact queue, sender and recipient. The reservation remains held.');
        }
        $proof = ['providerRecordId'=>$recordId,'providerMessageId'=>$messageId,'queueItemId'=>$queueId,'recipient'=>strtolower($recipient),'sender'=>strtolower($sender)];
        return ['providerRecordId'=>$recordId,'providerMessageId'=>$messageId,'evidenceHash'=>hash('sha256', json_encode($proof, JSON_THROW_ON_ERROR))];
    }
}
