<?php
/************************************************************************
 * This file is part of EspoCRM.
 *
 * EspoCRM – Open Source CRM application.
 * Copyright (C) 2014-2025 Yurii Kuznietsov, Taras Machyshyn, Oleksii Avramenko
 * Website: https://www.espocrm.com
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 *
 * The interactive user interfaces in modified source and object code versions
 * of this program must display Appropriate Legal Notices, as required under
 * Section 5 of the GNU Affero General Public License version 3.
 *
 * In accordance with Section 7(b) of the GNU Affero General Public License version 3,
 * these Appropriate Legal Notices must retain the display of the "EspoCRM" word.
 ************************************************************************/

require_once __DIR__ . '/application-path.php';

$basePath = NexaApplicationPath::fromScriptName($_SERVER['SCRIPT_NAME'] ?? '/public/index.php');
$requestPath = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$isFriendlyLoginRequest = NexaApplicationPath::isRoute($requestPath, $basePath, 'login');
$workspaceRoute = NexaApplicationPath::workspaceRoute($requestPath, $basePath);

// Keep one canonical login URL across root and subfolder installations.
if ($isFriendlyLoginRequest && !str_ends_with($requestPath, '/')) {
    $queryString = trim((string) ($_SERVER['QUERY_STRING'] ?? ''));
    $location = NexaApplicationPath::baseHref($basePath) . 'login/';

    if ($queryString !== '') {
        $location .= '?' . $queryString;
    }

    header('Location: ' . $location, true, 302);

    exit;
}

// Keep tenant workspace roots canonical so shared links and browser history do not
// alternate between equivalent trailing-slash forms.
if ($workspaceRoute !== null && str_ends_with($requestPath, '/')) {
    header('Location: ' . rtrim($requestPath, '/'), true, 302);

    exit;
}

$isLandingRequest = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET'
    && NexaApplicationPath::isApplicationRoot($requestPath, $basePath)
    && !isset($_GET['login'])
    && !filter_has_var(INPUT_GET, 'entryPoint');

if ($isLandingRequest) {
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
    $landing = file_get_contents(__DIR__ . '/landing/index.html');

    echo str_replace(
        '{{baseHref}}',
        htmlspecialchars(NexaApplicationPath::baseHref($basePath), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
        $landing === false ? '' : $landing
    );

    exit;
}

if (isset($_GET['login'])) {
    header('Cache-Control: no-store, no-cache, must-revalidate');
}

include dirname(__DIR__) . '/bootstrap.php';

use Espo\Core\Application;
use Espo\Core\ApplicationRunners\Client;
use Espo\Core\ApplicationRunners\EntryPoint;

$app = new Application();
$clientBasePath = NexaApplicationPath::baseHref($basePath);
$app->setClientBasePath($clientBasePath);

if (filter_has_var(INPUT_GET, 'entryPoint')) {
    $app->run(EntryPoint::class);

    exit;
}

$app->run(Client::class);
