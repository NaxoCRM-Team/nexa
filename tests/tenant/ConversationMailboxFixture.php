<?php
declare(strict_types=1);

// Included only by the validated, CLI-only synthetic-tenant fixture dispatcher.
if (!isset($command, $tenants, $contexts, $em, $factory) || getenv('NEXA_MAILBOX_ACCEPTANCE') !== '1') {
    throw new RuntimeException('Use the local acceptance fixture dispatcher.');
}
$crypt = $factory->create(\Espo\Core\Utils\Crypt::class);
$smtp = static function (string $to, string $subject, string $messageId, ?string $replyTo = null): void {
    $message = new \Laminas\Mail\Message();
    $message->setFrom('customer@conversation.test')->addTo($to)->setSubject($subject)->setBody('Local transport acceptance: '.$subject);
    $message->getHeaders()->addHeaderLine('Message-ID', '<'.$messageId.'>');
    if ($replyTo) $message->getHeaders()->addHeaderLine('In-Reply-To', $replyTo);
    $transport = new \Laminas\Mail\Transport\Smtp(new \Laminas\Mail\Transport\SmtpOptions(['host' => '127.0.0.1', 'port' => 13025]));
    $transport->send($message);
};
$result = [];
foreach ($tenants as $index => $tenant) {
    $context = new \Espo\Core\Tenant\TenantContext($tenant['id'], $tenant['slug'], 'mailbox-acceptance', '', $tenant['serviceId']);
    $result[] = $contexts->runWith($context, function () use ($command, $tenant, $index, $em, $factory, $crypt, $smtp, $input): array {
        $login = $index === 0 ? 'alpha' : 'beta';
        $address = $login.'@conversation.test';
        $subject = 'Mailbox acceptance '.$tenant['slug'];
        if ($command === 'conversation-mailbox-setup') {
            $team = $em->createEntity('Team', ['name' => 'Mailbox acceptance']);
            $em->getRDBRepository('Team')->getRelation($team, 'users')->relate($em->getEntityById('User', $tenant['userId']));
            $mailbox = $em->createEntity('InboundEmail', [
                'name' => 'Mailbox transport acceptance', 'emailAddress' => $address,
                'status' => 'Active', 'useImap' => true, 'host' => '127.0.0.1', 'port' => 13143,
                'security' => '', 'username' => $login, 'password' => $crypt->encrypt('local-test-only'),
                'monitoredFolders' => ['INBOX'], 'fetchSince' => gmdate('Y-m-d', time() - 86400),
                'assignToUserId' => $tenant['userId'], 'teamsIds' => [$team->getId()], 'addAllTeamUsers' => true,
                'useSmtp' => true, 'smtpIsShared' => true, 'smtpHost' => '127.0.0.1', 'smtpPort' => 13025,
                'smtpSecurity' => '', 'smtpAuth' => false, 'fromName' => 'Acceptance Support',
                'storeSentEmails' => false, 'reply' => false, 'createCase' => false,
            ]);
            // Provider Message-IDs are not tenant identifiers; duplicate detection must stay scoped.
            $smtp($address, $subject, substr($tenant['slug'], 0, -2).'@conversation.test');
        } else {
            $mailbox = $em->getRDBRepository('InboundEmail')->where(['name' => 'Mailbox transport acceptance', 'emailAddress' => $address])->findOne();
            if (!$mailbox) throw new RuntimeException('Synthetic mailbox is unavailable.');
        }
        $fetch = fn () => $factory->create(\Espo\Core\Mail\Account\GroupAccount\Service::class)->fetch($mailbox->getId());
        if ($command === 'conversation-mailbox-setup' || $command === 'conversation-mailbox-fetch') $fetch();
        if ($command === 'conversation-mailbox-smtp-fail' || $command === 'conversation-mailbox-smtp-restore') {
            // An unused loopback port fails locally; no external fallback sender is selected.
            $mailbox->set('smtpPort', $command === 'conversation-mailbox-smtp-fail' ? 13026 : 13025);
            $em->saveEntity($mailbox);
        }
        $email = $em->getRDBRepository('Email')->where(['name' => $subject, 'status' => 'Archived'])->findOne();
        if (!$email) throw new RuntimeException('Native IMAP import did not create the expected message.');
        if ($command === 'conversation-mailbox-followup') {
            $smtp($address, 'Re: '.$subject, 'followup-'.$tenant['slug'].'@conversation.test', $email->get('messageId'));
            $fetch();
        }
        if ($command === 'conversation-mailbox-imap-failure') {
            $mailbox->set('password', $crypt->encrypt('deliberately-wrong-local-password')); $em->saveEntity($mailbox);
            $failed = false;
            try { $fetch(); } catch (\Throwable $error) { $failed = true; }
            finally { $mailbox->set('password', $crypt->encrypt('local-test-only')); $em->saveEntity($mailbox); }
            if (!$failed) throw new RuntimeException('Invalid IMAP credentials were not rejected.');
            $fetch();
        }
        return ['emailId' => $email->getId(), 'messageId' => $email->get('messageId'), 'mailboxId' => $mailbox->getId(), 'subject' => $subject, 'address' => $address,
            'count' => $em->getRDBRepository('Email')->where(['name' => $subject, 'status' => 'Archived'])->count()];
    });
}
if ($command === 'conversation-mailbox-inspect') {
    $storage = new \Laminas\Mail\Storage\Imap(['host' => '127.0.0.1', 'port' => 13143, 'user' => 'customer', 'password' => 'local-test-only', 'ssl' => false]);
    $messages = [];
    foreach ($storage as $message) {
        $headers = $message->getHeaders();
        $messages[] = ['subject' => $headers->get('Subject')->getFieldValue(), 'from' => $headers->get('From')->getFieldValue(),
            'replyTo' => $headers->has('In-Reply-To') ? $headers->get('In-Reply-To')->getFieldValue() : null,
            'content' => $message->getContent()];
    }
    $storage->close(); $result = $messages;
}
echo json_encode($result, JSON_THROW_ON_ERROR);
