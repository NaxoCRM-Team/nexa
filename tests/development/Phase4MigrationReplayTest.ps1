[CmdletBinding()]
param(
    [string] $ClientPath = 'mariadb',
    [string] $DatabaseHost = '127.0.0.1',
    [int] $Port = 3306,
    [string] $User = 'root',
    [string] $Password = ''
)

$ErrorActionPreference = 'Stop'
$root = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$baseSchema = Join-Path $root 'database\shared\testing\0000_espocrm_9_1_9_schema.sql'
$migrationRoot = Join-Path $root 'database\shared\migrations'
$seedRoot = Join-Path $root 'database\shared\seeds'
$cleanDatabase = 'nexa_phase4_clean_test'
$upgradeDatabase = 'nexa_phase4_upgrade_test'
$previousPassword = $env:MYSQL_PWD

function Resolve-MariaDbClient([string] $requested) {
    $command = Get-Command $requested -ErrorAction SilentlyContinue
    if ($command) { return $command.Source }
    $candidate = Get-ChildItem 'C:\wamp64\bin\mariadb' -Directory -ErrorAction SilentlyContinue |
        ForEach-Object { Join-Path $_.FullName 'bin\mariadb.exe' } |
        Where-Object { Test-Path -LiteralPath $_ } |
        Sort-Object -Descending | Select-Object -First 1
    if ($candidate) { return $candidate }
    throw 'A MariaDB client is required for Phase 4 migration replay.'
}

function Get-Arguments([string] $database = '') {
    $arguments = @('--batch', '--skip-column-names', "--host=$DatabaseHost", "--port=$Port", "--user=$User")
    if ($Password -eq '') { $arguments += '--skip-password' }
    if ($database -ne '') { $arguments += $database }
    return $arguments
}

function Invoke-Sql([string] $sql, [string] $database = '') {
    $output = @($sql | & $ClientPath @(Get-Arguments $database))
    if ($LASTEXITCODE -ne 0) { throw "MariaDB statement failed for $database." }
    return $output
}

function Invoke-SqlFile([IO.FileInfo] $file, [string] $database) {
    Get-Content -LiteralPath $file.FullName -Raw | & $ClientPath @(Get-Arguments $database)
    if ($LASTEXITCODE -ne 0) { throw "SQL replay failed: $($file.Name) in $database." }
}

function Assert-Scalar([string] $database, [string] $sql, [string] $expected, [string] $message) {
    $actual = @((Invoke-Sql $sql $database))[0]
    if ([string] $actual -ne $expected) { throw "$message Expected $expected, received $actual." }
}

try {
    $ClientPath = Resolve-MariaDbClient $ClientPath
    $env:MYSQL_PWD = if ($Password -ne '') { $Password } else { $null }
    foreach ($database in @($cleanDatabase, $upgradeDatabase)) {
        if ($database -notmatch '^nexa_phase4_[a-z_]+_test$') { throw "Unsafe test database name: $database" }
        Invoke-Sql "DROP DATABASE IF EXISTS ``$database``; CREATE DATABASE ``$database`` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" | Out-Null
    }

    $migrations = @(Get-ChildItem -LiteralPath $migrationRoot -Filter '*.sql' -File | Sort-Object Name)
    $phase4Migrations = @($migrations | Where-Object Name -GE '0043_add_consent_governance.sql')
    $seeds = @(Get-ChildItem -LiteralPath $seedRoot -Filter '*.sql' -File | Sort-Object Name)
    $phase4Tables = @(
        'nexa_consent_purpose', 'nexa_consent_event', 'nexa_consent_state',
        'nexa_cookie_banner', 'nexa_cookie_category', 'nexa_cookie_receipt',
        'nexa_form_profile', 'nexa_form_version', 'nexa_form_event',
        'nexa_asset_profile', 'nexa_asset_version', 'nexa_asset_event',
        'nexa_landing_page', 'nexa_landing_page_version', 'nexa_landing_page_event',
        'nexa_segment_definition', 'nexa_segment_version', 'nexa_segment_run',
        'nexa_segment_membership_event', 'nexa_visitor_identity', 'nexa_behavior_event',
        'nexa_public_rate_limit'
    )

    Invoke-SqlFile (Get-Item -LiteralPath $baseSchema) $cleanDatabase
    foreach ($migration in $migrations) { Invoke-SqlFile $migration $cleanDatabase }
    foreach ($pass in 1..2) { foreach ($seed in $seeds) { Invoke-SqlFile $seed $cleanDatabase } }
    $tableList = ($phase4Tables | ForEach-Object { "'$_'" }) -join ','
    Assert-Scalar $cleanDatabase "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$cleanDatabase' AND table_name IN ($tableList);" ([string]$phase4Tables.Count) 'Clean installation omitted Phase 4 tables.'
    Assert-Scalar $cleanDatabase "SELECT COUNT(*) FROM nexa_tenant WHERE slug IN ('isolation-alpha','isolation-beta');" '2' 'Clean installation duplicated or omitted tenant fixtures.'

    Invoke-SqlFile (Get-Item -LiteralPath $baseSchema) $upgradeDatabase
    foreach ($migration in $migrations | Where-Object Name -LT '0043_add_consent_governance.sql') { Invoke-SqlFile $migration $upgradeDatabase }
    foreach ($seed in $seeds) { Invoke-SqlFile $seed $upgradeDatabase }
    Invoke-Sql @"
INSERT INTO contact (id, first_name, last_name, deleted, created_at, tenant_id, service_id)
VALUES ('phase4contact001', 'Existing', 'Phase Four Contact', 0, UTC_TIMESTAMP(), '30000000-0000-4000-8000-000000000001', '20000000-0000-4000-8000-000000000001');
"@ $upgradeDatabase | Out-Null

    foreach ($migration in $phase4Migrations) { Invoke-SqlFile $migration $upgradeDatabase }
    Assert-Scalar $upgradeDatabase "SELECT COUNT(*) FROM contact WHERE id='phase4contact001' AND first_name='Existing' AND tenant_id='30000000-0000-4000-8000-000000000001';" '1' 'Phase 4 upgrade changed or removed an existing Contact.'
    Assert-Scalar $upgradeDatabase "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$upgradeDatabase' AND table_name IN ($tableList);" ([string]$phase4Tables.Count) 'Incremental upgrade omitted Phase 4 tables.'
    foreach ($pass in 1..2) { foreach ($seed in $seeds) { Invoke-SqlFile $seed $upgradeDatabase } }
    Assert-Scalar $upgradeDatabase "SELECT COUNT(*) FROM contact WHERE id='phase4contact001';" '1' 'Phase 4 seed replay duplicated or removed the existing Contact.'

    Write-Host 'Phase 4 clean-install and incremental migration replay passed.' -ForegroundColor Green
}
finally {
    foreach ($database in @($cleanDatabase, $upgradeDatabase)) {
        if ($database -match '^nexa_phase4_[a-z_]+_test$') {
            try { Invoke-Sql "DROP DATABASE IF EXISTS ``$database``;" | Out-Null } catch { }
        }
    }
    $env:MYSQL_PWD = $previousPassword
}
