<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static fn (string $path): string => (string) file_get_contents($root . '/' . $path);
$assert = static function (bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); };
$service = $read('espocrm/custom/Espo/Custom/Tools/MarketingEmail/MarketingDispatchService.php');
$job = $read('espocrm/custom/Espo/Custom/Jobs/ProcessMarketingDelivery.php');
$routes = $read('espocrm/custom/Espo/Custom/Resources/routes.json');
$scheduled = $read('espocrm/custom/Espo/Custom/Resources/metadata/entityDefs/ScheduledJob.json');
$client = $read('espocrm/client/custom/src/views/marketing-email/workspace.js');

$assert(str_contains($service, 'nexa_campaign_enrollment') && str_contains($service, 'nexa_consent_state') && str_contains($service, 'nexa_marketing_suppression'), 'Dispatch must recheck campaign enrollment, consent and suppressions.');
$assert(str_contains($service, "ea.invalid=0") && str_contains($service, "ea.opt_out=0") && str_contains($service, 'do_not_contact'), 'Dispatch must honor native email validity, opt-out and do-not-contact controls.');
$assert(str_contains($service, 'EmailQueueItem::ENTITY_TYPE') && str_contains($service, 'TemplateProcessor') && str_contains($service, 'PostalDeliveryProvider'), 'Dispatch must retain the native queue and renderer behind the provider adapter.');
$assert(str_contains($service, 'FOR UPDATE') && str_contains($service, 'accepted_count=accepted_count+1'), 'Monthly delivery capacity must be reserved atomically.');
$unsubscribe = $read('espocrm/custom/Espo/Custom/Tools/MarketingEmail/MarketingUnsubscribeService.php');
$assert(str_contains($service, 'List-Unsubscribe-Post') && str_contains($service, '/Nexa/public/marketing-unsubscribe/') && str_contains($unsubscribe, '$this->native->unsubscribe'), 'Every governed send must offer signed unsubscribe links backed by native opt-out handling.');
$assert(str_contains($routes, '/Nexa/marketing-emails/:id/schedule') && str_contains($client, 'schedule-email'), 'Approved email scheduling must be available through the API and workspace.');
$assert(str_contains($routes, '/Nexa/marketing-emails/:id/single-send') && str_contains($client, 'chooseSingleSendContact') && str_contains($client, 'data-single-send-form'), 'Approved single-send emails must expose Contact selection and explicit confirmation through the governed API.');
$assert(str_contains($client, "multiple: false, createButton: false, headerText: 'Choose email recipient'") && str_contains($client, 'dialog.busy') && str_contains($client, 'dialog !== this.singleSendDialog'), 'Single-send selection must be singular and protect in-flight requests and stale responses.');
$assert(str_contains($scheduled, 'ProcessMarketingDelivery') && str_contains($job, 'runScheduled'), 'The tenant-aware marketing delivery job is not registered.');
echo "Marketing dispatch contracts passed.\n";
