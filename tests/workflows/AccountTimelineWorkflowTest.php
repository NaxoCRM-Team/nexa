<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static function (string $relative) use ($root): string {
    $content = file_get_contents($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative));
    if ($content === false) throw new RuntimeException("Unable to read {$relative}.");
    return $content;
};
$mustContain = static function (string $needle, string $content, string $message): void {
    if (!str_contains($content, $needle)) throw new RuntimeException($message);
};

$routes = json_decode($read('espocrm/custom/Espo/Custom/Resources/routes.json'), true, flags: JSON_THROW_ON_ERROR);
$service = $read('espocrm/custom/Espo/Custom/Tools/Account/AccountTimelineService.php');
$api = $read('espocrm/custom/Espo/Custom/Tools/Account/Api/GetTimeline.php');
$view = $read('espocrm/client/custom/src/views/account/record/detail-workspace.js');

if (!array_filter($routes, static fn (array $route): bool =>
    ($route['route'] ?? '') === '/Nexa/account/:id/timeline' &&
    ($route['method'] ?? '') === 'get' && empty($route['noAuth'])
)) {
    throw new RuntimeException('The Account timeline must be exposed only through an authenticated route.');
}

$mustContain("->get('Account')->read(", $service, 'Timeline access must first prove the Account is readable.');
$mustContain('findLinked($accountId, $link', $service, 'Associated Contacts must resolve through Record Service.');
$mustContain('recordServiceContainer->get($entityType)->find(', $service, 'Timeline entities must be read through Record Service.');
$mustContain("acl->check(\$entityType, Table::ACTION_READ)", $service, 'Timeline sources must enforce entity read permission.');
$mustContain('CONTACT_LIMIT', $service, 'Timeline association traversal must have an explicit safety bound.');
$mustContain("'hasMore' => \$hasMore", $service, 'Timeline responses must expose paging state.');
$mustContain("'comments' =>", $service, 'Timeline responses must carry threaded discussion separately from activity records.');
$mustContain('COMMENT_LIMIT', $service, 'Timeline discussion loading must have an explicit safety bound.');
$mustContain("->get('Note')->find", $service, 'Timeline comments must be loaded through tenant and ACL-scoped Record Service.');
$mustContain('nexa-engagement-(?:comment|reply)', $service, 'Only explicit Account timeline discussion markers may enter the comment payload.');
$mustContain("getQueryParam('offset')", $api, 'Timeline API must accept an offset.');
$mustContain("getQueryParam('limit')", $api, 'Timeline API must accept a page size.');
$mustContain('Nexa/account/${encodeURIComponent(this.model.id)}/timeline', $view, 'The Account workspace must use the protected timeline endpoint.');
$mustContain('data-nexa-engagement-more', $view, 'Each timeline tab must expose progressive loading.');
$mustContain('loadTimelinePage(shell, key, false)', $view, 'Initial timeline pages must load from the server boundary.');

foreach (['tenant-a', 'tenant-b', 'isolation-alpha'] as $literal) {
    if (str_contains($service . $api, $literal)) {
        throw new RuntimeException("Timeline code must not hardcode {$literal}.");
    }
}

echo "Account timeline isolation and pagination contracts passed.\n";
