<?php
declare(strict_types=1);

// Explicit opt-in: external transport is confined to the local capture-only stack.
define('NEXA_POSTAL_CAPTURE_ACCEPTANCE', true);
require dirname(__DIR__) . '/tenant/TenantMarketingDispatchTest.php';
