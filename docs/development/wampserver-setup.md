# WampServer Development Setup

## Purpose

This is the standard Windows development setup for Nexa. It runs the complete
tracked repository at <http://localhost/nexa/> without a virtual host, hosts-file
entry, browser installer, Docker, XAMPP, or a separate application download.

A completed installation contains 166 tables, 155 tenant columns, 138 service
columns, all 9 migrations, the bootstrap administrator, two demo tenants, and
tenant-scoped CRM demo data.

## Required Software

- Git for Windows
- PowerShell 5.1 or later
- WampServer with Apache 2.4
- PHP 8.2.x
- MariaDB 10.11 or 11.x

From the WampServer tray menu, select PHP 8.2 and enable `curl`, `gd`, `intl`,
`mbstring`, `mysqli`, `openssl`, `pdo_mysql`, and `zip`. Set
`max_execution_time` and `max_input_time` to at least `180`.

Use MariaDB on port `3306`. Stop MySQL or assign it another port so the two
database servers do not compete. Apache must listen on port `80` for the exact
URL used by this guide.

## 1. Clone The Repository

```powershell
Set-Location C:\wamp64\www
git clone https://github.com/NaxoCRM-Team/nexa.git
Set-Location C:\wamp64\www\nexa
git switch main
git pull --ff-only origin main
```

The repository already contains the complete application and dependencies. Do
not download a separate EspoCRM archive.

## 2. Locate PHP And MariaDB

```powershell
$php = Get-ChildItem C:\wamp64\bin\php -Filter php.exe -File -Recurse |
    Where-Object { $_.VersionInfo.ProductVersion -like '8.2.*' } |
    Sort-Object FullName -Descending |
    Select-Object -ExpandProperty FullName -First 1

$mariadb = Get-ChildItem C:\wamp64\bin\mariadb -Filter mariadb.exe -File -Recurse |
    Sort-Object FullName -Descending |
    Select-Object -ExpandProperty FullName -First 1

& $php -v
& $mariadb --version
```

PHP must report `8.2.x`. MariaDB may report `10.11.x` or `11.x`.

## 3. Configure The Localhost Alias

Create `C:\wamp64\alias\nexa.conf` with the following content:

```apache
AliasMatch "^/nexa/api/v1/portal-access(?:/.*)?$" "C:/wamp64/www/nexa/espocrm/public/api/v1/portal-access/index.php"
AliasMatch "^/nexa/api/v1(?:/.*)?$" "C:/wamp64/www/nexa/espocrm/public/api/v1/index.php"
SetEnvIfNoCase Authorization "^(.*)$" HTTP_AUTHORIZATION=$1

Alias /nexa/client/ "C:/wamp64/www/nexa/espocrm/client/"
Alias /nexa/client "C:/wamp64/www/nexa/espocrm/client"
Alias /nexa/ "C:/wamp64/www/nexa/espocrm/public/"
Alias /nexa "C:/wamp64/www/nexa/espocrm/public"

<Directory "C:/wamp64/www/nexa/espocrm/">
    Options FollowSymLinks
    AllowOverride None
    Require local
</Directory>

<Directory "C:/wamp64/www/nexa/espocrm/client/">
    Options FollowSymLinks
    AllowOverride None
    Require local
</Directory>

<Directory "C:/wamp64/www/nexa/espocrm/public/">
    Options FollowSymLinks
    AllowOverride All
    Require local
    DirectoryIndex index.php

    RewriteEngine On
    RewriteBase /nexa/
    RewriteRule ^login/?$ index.php?login=1 [END,QSA,NC]
</Directory>
```

This is an Apache alias, not a virtual host. It exposes the public application
and client assets without exposing `application`, `custom`, `data`, or `vendor`.
It also sends every friendly API URL through the correct API front controller.

Confirm `mod_alias`, `mod_rewrite`, `mod_setenvif`, and `AllowOverride` support
are enabled, then restart all WampServer services. Validate Apache if necessary:

```powershell
$httpd = Get-ChildItem C:\wamp64\bin\apache -Filter httpd.exe -File -Recurse |
    Sort-Object FullName -Descending |
    Select-Object -ExpandProperty FullName -First 1
& $httpd -t
```

The result must be `Syntax OK`. No Windows hosts-file entry is required.

## 4. Configure The Local Environment

The setup command creates an ignored `.env` when one does not exist. Review it
before sharing screenshots or diagnostics because it contains local secrets.
The important local URL values are:

```dotenv
ESPOCRM_SITE_URL=http://localhost/nexa
ESPOCRM_PORT=80
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=espocrm
DB_USER=espocrm
AUTH_SESSION_IDLE_MINUTES=30
NEXA_AUTH_GOOGLE_REDIRECT_URI=http://localhost/nexa/api/v1/Nexa/auth/provider/google/callback
NEXA_AUTH_MICROSOFT_REDIRECT_URI=http://localhost/nexa/api/v1/Nexa/auth/provider/microsoft/callback
```

Register those exact callback URLs in Google Cloud and Microsoft Entra when
social authentication is enabled. SMTP and provider credentials remain only in
`.env` and are never committed.

## 5. Run The Complete Setup

Start WampServer Apache and MariaDB, then run:

```powershell
Set-Location C:\wamp64\www\nexa
powershell -ExecutionPolicy Bypass -File scripts/dev/setup-native-windows.ps1 `
  -PhpPath $php `
  -ClientPath $mariadb `
  -DatabaseHost 127.0.0.1 `
  -DatabasePort 3306 `
  -SiteUrl http://localhost/nexa
```

If WampServer's MariaDB `root` account has no password, leave the secure root
password prompt empty. The setup command then:

- creates the `espocrm` database and restricted application user;
- loads the EspoCRM 9.1.9 base schema;
- applies every tracked Nexa migration;
- generates machine-specific application configuration and installed marker;
- configures SMTP and authentication from `.env`;
- creates the bootstrap administrator;
- provisions both demo tenants and their administrators;
- loads tenant-scoped accounts, contacts, leads, opportunities, tasks, and meetings;
- rebuilds the application and clears its cache;
- blocks the browser installer;
- runs repository, schema, tenant-isolation, and authentication verification.

The command is idempotent. Run it again after pulling reviewed migrations or
configuration changes.

## 6. Verify The Installation

Open:

- Landing page: <http://localhost/nexa/>
- Shared login: <http://localhost/nexa/login/>

Both demo administrators use the shared login. Their credentials are the
`DEMO_TENANT_A_ADMIN_*` and `DEMO_TENANT_B_ADMIN_*` values in the ignored `.env`.
The submitted username or email resolves the correct tenant automatically.

Run these HTTP checks:

```powershell
Invoke-WebRequest http://localhost/nexa/ -UseBasicParsing
Invoke-WebRequest http://localhost/nexa/login/ -UseBasicParsing
Invoke-WebRequest http://localhost/nexa/api/v1/Nexa/auth/providers -UseBasicParsing
```

Each request must return HTTP `200`. Visiting `/install/` must redirect to the
landing page instead of showing the browser installer.

## 7. Configure Scheduled Jobs

Create a Windows Task Scheduler task that runs every minute:

```text
Program: C:\wamp64\bin\php\php8.2.x\php.exe
Arguments: C:\wamp64\www\nexa\espocrm\cron.php
Start in: C:\wamp64\www\nexa\espocrm
```

Replace `php8.2.x` with the installed folder. Scheduled jobs process email,
automation, queues, and other background work.

## Updating The Checkout

```powershell
Set-Location C:\wamp64\www\nexa
git switch main
git pull --ff-only origin main

powershell -ExecutionPolicy Bypass -File scripts/dev/setup-native-windows.ps1 `
  -PhpPath $php `
  -ClientPath $mariadb `
  -DatabaseHost 127.0.0.1 `
  -DatabasePort 3306 `
  -SiteUrl http://localhost/nexa
```

Do not replace the database with another developer's SQL dump. Reviewed
migrations and seeds keep every environment reproducible.

## Troubleshooting

### Landing Works But Login Or API Returns 404

Confirm `C:\wamp64\alias\nexa.conf` matches this guide, including both
`AliasMatch` directives, and restart Apache. Directly opening
`/nexa/api/v1/index.php` should return `401`; the friendly provider endpoint
must return `200`.

### PHP Extensions Fail Or Report A Different Version

The Wamp tray-selected PHP version and `$php -v` must both report PHP 8.2.x.
Restart Apache after changing PHP. Check the active Apache `php.ini` and confirm
its `extension_dir` points to the same PHP 8.2 folder.

### MariaDB Will Not Start

Only one process can own port `3306`. Stop standalone MariaDB, XAMPP MySQL, or
another MySQL service before starting WampServer MariaDB. Alternatively move the
unused MySQL service to another port.

### Setup Detects A Non-Nexa Database

The setup refuses to alter a non-empty database that does not contain
`nexa_schema_migration`. Use a new empty `espocrm` database or explicitly migrate
the existing database; do not force the installer over unrelated data.

## Acceptance Check

WampServer setup is complete only when:

- <http://localhost/nexa/> and <http://localhost/nexa/login/> return HTTP `200`;
- the provider API returns HTTP `200`;
- `/install/` redirects away from the installer;
- PHP reports 8.2.x with the required extensions;
- MariaDB reports a supported 10.11 or 11.x version;
- verification reports 166 tables, 155 tenant columns, 138 service columns, and 9 migrations;
- both demo administrators authenticate and resolve different tenants;
- repository verification passes without errors.