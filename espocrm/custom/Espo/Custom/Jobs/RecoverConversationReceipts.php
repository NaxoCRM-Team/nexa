<?php
declare(strict_types=1);
namespace Espo\Custom\Jobs;

use Espo\Core\Job\JobDataLess;
use Espo\Custom\Tools\Conversation\ConversationReceipt;

/** Native scheduler supplies the tenant context; no global mailbox sweep. */
final class RecoverConversationReceipts implements JobDataLess
{
    public function __construct(private ConversationReceipt $receipts) {}

    public function run(): void
    {
        $this->receipts->recover();
    }
}
