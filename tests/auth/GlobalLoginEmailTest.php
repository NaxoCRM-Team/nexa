<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$migration = (string) file_get_contents(
    $root . '/database/shared/migrations/0017_add_global_login_email.sql'
);
$resolver = (string) file_get_contents(
    $root . '/espocrm/application/Espo/Core/Tenant/TenantResolver.php'
);
$finder = (string) file_get_contents(
    $root . '/espocrm/application/Espo/Core/Authentication/Helper/UserFinder.php'
);
$hook = (string) file_get_contents(
    $root . '/espocrm/custom/Espo/Custom/Hooks/User/LoginEmail.php'
);
$signup = (string) file_get_contents(
    $root . '/espocrm/custom/Espo/Custom/Tools/Signup/SignupService.php'
);
$template = (string) file_get_contents(
    $root . '/espocrm/client/custom/res/templates/login-modern.tpl'
);

$assert(
    str_contains($migration, '`login_email` VARCHAR(190)') &&
    str_contains($migration, 'UNIQ_USER_LOGIN_EMAIL') &&
    str_contains($migration, 'LOWER(TRIM(ea.name))'),
    'The schema must backfill and uniquely index normalized login emails.'
);
$assert(
    str_contains($resolver, 'u.login_email = :email') &&
    str_contains($resolver, 'u.login_email IS NULL AND u.user_name = :identifier') &&
    str_contains($finder, "['loginEmail' => strtolower(\$identifier)]") &&
    str_contains($finder, "'loginEmail' => null"),
    'Tenant discovery and user lookup must share the same email-first identity rules.'
);
$assert(
    str_contains($hook, "strtolower(\$email)") &&
    str_contains($hook, 'WHERE login_email = :email') &&
    str_contains($hook, 'already connected to a Nexa account'),
    'All ORM-created users must normalize and reject a duplicate login email.'
);
$assert(
    str_contains($signup, 'user_name,login_email,type') &&
    substr_count($signup, 'assertEmailAvailable($pdo,') >= 3 &&
    str_contains($signup, 'login_email = :loginEmail') &&
    !str_contains($signup, 'SELECT 1 FROM email_address WHERE `lower`=') &&
    str_contains($template, '>Email address</label>') &&
    str_contains($template, 'inputmode="email"'),
    'Email and social signup must check the global user identity at entry and provisioning without treating CRM contacts as users.'
);

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, "Global login email contract passed.\n");
