# XAMPP Development Setup

## Purpose

This guide creates a complete Nexa development environment on Windows using
XAMPP Apache/PHP 8.2 and MariaDB 10.11 or 11.x. A successful setup contains the full
tracked application, all 158 current database tables, all migrations, the local
bootstrap administrator, two demo tenants, and tenant-scoped demo CRM records.

No application archive or EspoCRM download is required. Git supplies the exact
application and Nexa code used by the team.

## Required Software

- Git for Windows
- PowerShell 5.1 or later
- XAMPP with PHP 8.2
- MariaDB 10.11 or 11.x ([official downloads](https://mariadb.org/download/))

Use a separate supported MariaDB service on port `3306` and keep XAMPP MySQL
stopped. XAMPP's bundled MariaDB 10.4 is below the supported minimum.

Enable these PHP extensions in `C:\xampp\php\php.ini`:

```ini
extension=curl
extension=gd
extension=mbstring
extension=mysqli
extension=openssl
extension=pdo_mysql
extension=zip
```

Use these minimum local installer limits, then restart Apache:

```ini
max_execution_time=180
max_input_time=180
```

## 1. Clone And Prepare

```powershell
Set-Location C:\xampp\htdocs
git clone https://github.com/NaxoCRM-Team/nexa.git
Set-Location nexa

$env:Path = "C:\xampp\php;$env:Path"
```

The setup command verifies the tracked application and creates an ignored
`.env` with random local credentials. Set this value in `.env`:

```text
ESPOCRM_SITE_URL=http://nexa.local
```

Never commit `.env`. It contains:

- `DB_PASSWORD`: local `espocrm` database-user password.
- `ADMIN_USERNAME` and `ADMIN_PASSWORD`: local bootstrap administrator.
- `DEMO_TENANT_A_ADMIN_*`: Tenant A development login.
- `DEMO_TENANT_B_ADMIN_*`: Tenant B development login.

For real signup email delivery, also configure:

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

Use credentials issued by the selected transactional email provider. The From
address or domain must be verified with that provider. Leave `SMTP_HOST` empty
when local email delivery is intentionally disabled.

## 2. Start MariaDB

Install the MariaDB MSI with **Database instance**, **Install as service** and
**Enable networking** selected. Locate and verify its client:

```powershell
$mariadb = Get-ChildItem 'C:\Program Files' -Directory -Filter 'MariaDB *' |
    ForEach-Object { Join-Path $_.FullName 'bin\mariadb.exe' } |
    Where-Object { Test-Path -LiteralPath $_ } |
    Sort-Object -Descending |
    Select-Object -First 1
if (-not $mariadb) { throw 'MariaDB 10.11 or 11.x is not installed.' }

& $mariadb --version
Get-Service | Where-Object { $_.DisplayName -match 'MariaDB' }
```

The version must be `10.11.x` or `11.x`, and the MariaDB service must be running.

## 3. Configure Apache

Confirm these lines are enabled in `C:\xampp\apache\conf\httpd.conf`:

```apache
LoadModule rewrite_module modules/mod_rewrite.so
Include conf/extra/httpd-vhosts.conf
```

Do not add `ClearModuleList` or `AddModule mod_rewrite.c`; those belong to old
Apache versions and are not used by Apache 2.4.

Add this block to `C:\xampp\apache\conf\extra\httpd-vhosts.conf`:

```apache
<VirtualHost *:80>
    ServerName nexa.local
    DocumentRoot "C:/xampp/htdocs/nexa/espocrm"

    <Directory "C:/xampp/htdocs/nexa/espocrm">
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

Start Apache from XAMPP and keep XAMPP MySQL stopped. Check Apache before
continuing:

```powershell
& 'C:\xampp\apache\bin\httpd.exe' -t
& 'C:\xampp\apache\bin\httpd.exe' -M | Select-String rewrite_module
```

## 4. Run The Complete Setup

Run one command from an Administrator PowerShell after MariaDB and Apache are
running:

```powershell
Set-Location C:\xampp\htdocs\nexa
powershell -ExecutionPolicy Bypass -File scripts/dev/setup-native-windows.ps1 `
  -PhpPath 'C:\xampp\php\php.exe' `
  -ClientPath $mariadb
```

On its first run, the command creates `.env` with random local credentials. If
the generated `DB_ROOT_PASSWORD` does not match MariaDB, it securely prompts
for the current root password without storing or printing it.

The command performs the complete installation:

- creates the database and restricted application user;
- loads the base schema and every migration;
- generates valid machine-specific application configuration and encryption keys;
- creates the local bootstrap administrator;
- validates and applies SMTP settings when `SMTP_HOST` is configured;
- loads development catalog and tenant seeds in order;
- creates or refreshes two separate demo tenant administrators;
- adds accounts, contacts, leads, opportunities, tasks and meetings per tenant;
- rebuilds and clears application caches;
- marks the application as installed;
- verifies the schema, demo data and shared login;
- proves that `/install` redirects away from the installer.

No browser installation is used. Opening <http://nexa.local> now shows the
landing or login experience directly. The command can be run again after
`git pull`; it applies pending migrations and refreshes development fixtures.

Both demo accounts sign in through <http://nexa.local/login>. Use the
`DEMO_TENANT_A_ADMIN_*` or `DEMO_TENANT_B_ADMIN_*` values from `.env`. The
submitted login identity selects the tenant; separate login domains are not
required.

Reapply SMTP settings after changing `.env`:

```powershell
& 'C:\xampp\php\php.exe' scripts/dev/configure-smtp.php --env=.env
```

The command never prints the SMTP password. Use Administration > Outbound
Emails to send a test message after configuration.

## 5. Configure Scheduled Jobs

Create a Windows Task Scheduler task that runs every minute:

```text
Program: C:\xampp\php\php.exe
Arguments: C:\xampp\htdocs\nexa\espocrm\cron.php
Start in: C:\xampp\htdocs\nexa\espocrm
```

Run the task manually once and inspect `espocrm/data/logs/` if it fails.

## Updating A Checkout

```powershell
Set-Location C:\xampp\htdocs\nexa
git switch main
git pull --ff-only

powershell -ExecutionPolicy Bypass -File scripts/dev/setup-native-windows.ps1 `
  -PhpPath 'C:\xampp\php\php.exe' `
  -ClientPath $mariadb
```

The command detects the installed Nexa database and applies only pending
migrations before rebuilding and verifying the application.

## Common Problems

### API Is Unavailable

Run `httpd.exe -t` and confirm `rewrite_module` is listed. If `/api/v1/` returns
HTTP `401`, rewriting and tenant routing are working; the installer accepts
`200` or `401`. A `403` normally means `nexa.local` was not registered, so rerun
Step 4 only on a fresh database or inspect `nexa_tenant_domain` on an existing
installation.

### Native Setup Fails After Database Creation

Inspect the newest file under `espocrm/data/logs/`, then run the installation
verifier to identify an incomplete configuration or schema:

```powershell
& 'C:\xampp\php\php.exe' scripts/dev/verify-local-install.php --before-demo
```

### MariaDB Will Not Start

Only one database server can use port `3306`. Keep XAMPP MySQL stopped while the
supported MariaDB Windows service is running.

### phpMyAdmin Cannot Connect

phpMyAdmin must target the running supported MariaDB server and use cookie
authentication or valid MariaDB credentials. This does not affect Nexa when the
application connection itself is valid.

### Missing PHP Extensions Or Limits

Edit `C:\xampp\php\php.ini`, restart Apache, and rerun:

```powershell
powershell -ExecutionPolicy Bypass -File scripts/dev/check-environment.ps1
```

## Acceptance Check

The environment is complete only when:

- <http://nexa.local/login> loads;
- `setup-native-windows.ps1` finishes successfully;
- <http://nexa.local/install/> redirects away from the installer;
- the verifier reports at least 158 tables, 147 tenant columns, 138 service
  columns and all migrations;
- both demo accounts authenticate through the same login page;
- demo CRM records are visible only inside their assigned tenant;
- scheduled jobs run without errors.
