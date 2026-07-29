# WampServer Development Setup

## Purpose

This guide creates the same complete Nexa development environment as Docker and
XAMPP while using WampServer Apache/PHP 8.2 and MariaDB 10.11 or 11.x. The repository
already contains the full application; no separate EspoCRM download is needed.

A completed setup contains all 158 current tables, all tenant and service
columns, every migration, the local bootstrap administrator, two demo tenants,
and tenant-scoped demo CRM data.

## Required Software

- Git for Windows
- PowerShell 5.1 or later
- WampServer with Apache and PHP 8.2
- MariaDB 10.11 or 11.x available through WampServer or a separate Windows service

The Apache PHP version and command-line PHP version must both be 8.2.x. Enable
`curl`, `gd`, `mbstring`, `mysqli`, `openssl`, `pdo_mysql` and `zip`. Set
`max_execution_time` and `max_input_time` to at least `180`, then restart all
WampServer services.

## 1. Clone And Prepare

```powershell
Set-Location C:\wamp64\www
git clone https://github.com/NaxoCRM-Team/nexa.git
Set-Location nexa

$php = 'C:\wamp64\bin\php\php8.2.x\php.exe'
$env:Path = (Split-Path $php) + ';' + $env:Path
```

Replace `php8.2.x` with the installed WampServer folder. Set this value in the
ignored `.env`:

```text
ESPOCRM_SITE_URL=http://nexa.local
```

The ignored `.env` stores the database password, local bootstrap administrator,
and separate Tenant A and Tenant B demo credentials. Never commit it.

For real signup verification delivery, add provider-issued SMTP values:

```dotenv
SMTP_HOST=smtp.provider.example
SMTP_PORT=587
SMTP_SECURITY=TLS
SMTP_AUTH=true
SMTP_USERNAME=provider-user
SMTP_PASSWORD=provider-password
SMTP_FROM_EMAIL=verified-sender@example.com
SMTP_FROM_NAME=Nexa CRM
```

The From address or domain must be verified by the selected provider. Leave
`SMTP_HOST` empty when local delivery is intentionally disabled.

## 2. Select MariaDB

Use the WampServer tray menu to activate MariaDB 10.11 or 11.x, or run a separate
supported MariaDB Windows service. Do not let another MySQL/MariaDB service compete
for the same port.

Locate and verify the client:

```powershell
Get-ChildItem C:\wamp64\bin\mariadb -Directory
$mariadb = Get-ChildItem C:\wamp64\bin\mariadb -Filter mariadb.exe -File -Recurse |
    Sort-Object FullName -Descending |
    Select-Object -ExpandProperty FullName -First 1
& $mariadb --version
```

The output must report MariaDB 10.11.x or 11.x.

## 3. Configure Apache

Enable `mod_rewrite` from the WampServer tray menu. Add the virtual host through
WampServer's **Add a Virtual Host** interface or use the equivalent configuration:

```apache
<VirtualHost *:80>
    ServerName nexa.local
    DocumentRoot "C:/wamp64/www/nexa/espocrm"

    <Directory "C:/wamp64/www/nexa/espocrm">
        Options FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

Add this entry to `C:\Windows\System32\drivers\etc\hosts` as Administrator:

```text
127.0.0.1 nexa.local
```

Restart all WampServer services. Do not add obsolete Apache directives such as
`ClearModuleList` or `AddModule mod_rewrite.c`.

## 3A. Portable Folder Mode Without A Virtual Host

Use this mode when a teammate receives only a prepared application folder and a
matching SQL export. It does not require `nexa.local`, a virtual host, the hosts
file, Git, or the browser installer.

1. Copy the prepared folder to `C:\wamp64\www\espocrm_boye`.
2. Import the supplied SQL file into MariaDB.
3. Enable Apache `mod_rewrite` and confirm the WampServer `www` directory allows
   `.htaccess` overrides with `AllowOverride All`.
4. Edit `C:\wamp64\www\espocrm_boye\data\config.php` and set the imported
   database name, database username, database password, and:

```php
'siteUrl' => 'http://localhost/espocrm_boye',
```

5. Confirm this entry exists in `data/config-internal.php`:

```php
'isInstalled' => true,
```

6. Clear the contents of `data/cache` and `data/tmp`, restart WampServer, then
   open <http://localhost/espocrm_boye/>.

The folder name may be changed, but `siteUrl` must match it exactly. Google and
Microsoft callback URLs must also use the same base, for example
`http://localhost/espocrm_boye/api/v1/Nexa/auth/provider/google/callback`.
Database credentials and the exact local URL are machine-specific, so those are
the only unavoidable configuration values. Do not copy another developer's
SMTP password, encryption keys, or production secrets into the package.

## 4. Run The Complete Setup

Run one command from an Administrator PowerShell after MariaDB and Apache are
running:

```powershell
Set-Location C:\wamp64\www\nexa
powershell -ExecutionPolicy Bypass -File scripts/dev/setup-native-windows.ps1 `
  -PhpPath $php `
  -ClientPath $mariadb
```

On its first run, the command creates `.env` with random local credentials. If
the generated `DB_ROOT_PASSWORD` does not match WampServer, it securely prompts
for the current MariaDB root password without storing or printing it.

The command then:

- creates the database and restricted application user;
- loads the complete base schema and applies every migration;
- generates valid machine-specific application configuration and encryption keys;
- creates the local bootstrap administrator;
- validates and applies SMTP settings when `SMTP_HOST` is configured;
- loads development seeds in dependency order;
- provisions both demo tenant administrators;
- creates tenant-scoped accounts, contacts, leads, opportunities, tasks and meetings;
- rebuilds and clears cache;
- verifies table, tenant-column, service-column and migration counts;
- proves that both tenants have administrators and CRM data;
- runs repository verification;
- verifies the shared login and proves that `/install` redirects away.

No browser installation is used. After setup, opening <http://nexa.local>
shows the landing or login experience directly. The command is idempotent: on
later runs it applies pending migrations and refreshes development fixtures.

Both tenants sign in through <http://nexa.local/?login=1>. Use the
`DEMO_TENANT_A_ADMIN_*` or `DEMO_TENANT_B_ADMIN_*` values from `.env`.

Reapply SMTP settings after editing `.env`:

```powershell
& $php scripts/dev/configure-smtp.php --env=.env
```

The command never prints the SMTP password. Send a test message from
Administration > Outbound Emails after configuration.

## 5. Configure Scheduled Jobs

Create a Windows Task Scheduler task that runs every minute:

```text
Program: C:\wamp64\bin\php\php8.2.x\php.exe
Arguments: C:\wamp64\www\nexa\espocrm\cron.php
Start in: C:\wamp64\www\nexa\espocrm
```

Run it manually once and inspect `espocrm/data/logs/` if it fails.

## Updating A Checkout

```powershell
Set-Location C:\wamp64\www\nexa
git switch main
git pull --ff-only

powershell -ExecutionPolicy Bypass -File scripts/dev/setup-native-windows.ps1 `
  -PhpPath $php `
  -ClientPath $mariadb
```

The command detects the installed Nexa database and applies only pending
migrations before rebuilding and verifying the application.

## Common Problems

### API Is Unavailable

Confirm `mod_rewrite`, `AllowOverride All`, and the hosts entry. HTTP `401` from
`/api/v1/` means rewriting and tenant routing work; the installer accepts `200`
or `401`. HTTP `403` normally means the local tenant host was not registered.

### Native Setup Fails After Database Creation

Inspect the newest file in `espocrm/data/logs/`, then run the installation
verifier to identify an incomplete configuration or schema:

```powershell
& $php scripts/dev/verify-local-install.php --before-demo
```

### Wrong PHP Version

The tray-selected Apache PHP and `$php -v` must both report PHP 8.2.x with the
required extensions.

### Database Connection Fails

Confirm a supported MariaDB 10.11/11.x server is running, no other database server owns its port, and
the `espocrm` user has privileges on the `espocrm` database.

### Migration Checksum Mismatch

Do not edit an applied migration. Pull the reviewed repository state and add a
new forward migration for further changes.

## Acceptance Check

WampServer setup is complete only when:

- <http://nexa.local/?login=1> loads;
- `setup-native-windows.ps1` passes;
- <http://nexa.local/install/> redirects away from the installer;
- validation reports at least 158 tables, 147 tenant columns, 138 service
  columns and all migrations;
- both demo accounts authenticate on the same login page;
- each tenant sees only its own CRM data;
- scheduled jobs run without errors.
