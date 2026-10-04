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
$cleanDatabase = 'nexa_phase5_clean_test'
$upgradeDatabase = 'nexa_phase5_upgrade_test'
$previousPassword = $env:MYSQL_PWD

function Resolve-MariaDbClient([string] $requested) {
    $command = Get-Command $requested -ErrorAction SilentlyContinue
    if ($command) { return $command.Source }
    $candidate = Get-ChildItem 'C:\wamp64\bin\mariadb' -Directory -ErrorAction SilentlyContinue |
        ForEach-Object { Join-Path $_.FullName 'bin\mariadb.exe' } |
        Where-Object { Test-Path -LiteralPath $_ } |
        Sort-Object -Descending | Select-Object -First 1
    if ($candidate) { return $candidate }
    throw 'A MariaDB client is required for Phase 5 migration replay.'
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
        if ($database -notmatch '^nexa_phase5_[a-z_]+_test$') { throw "Unsafe test database name: $database" }
        Invoke-Sql "DROP DATABASE IF EXISTS ``$database``; CREATE DATABASE ``$database`` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" | Out-Null
    }

    $migrations = @(Get-ChildItem -LiteralPath $migrationRoot -Filter '*.sql' -File | Sort-Object Name)
    $phase5Migrations = @($migrations | Where-Object Name -GE '0053_add_behavior_event_foundation.sql')
    if ($migrations[-1].Name -ne '0061_add_event_retention_governance.sql') {
        throw "Update the Phase 5 migration gate for the new endpoint: $($migrations[-1].Name)"
    }
    $seeds = @(Get-ChildItem -LiteralPath $seedRoot -Filter '*.sql' -File | Sort-Object Name)
    $phase5Tables = @(
        'nexa_visitor_identity', 'nexa_behavior_event', 'nexa_behavior_event_replay',
        'nexa_tracking_source', 'nexa_event_retention_policy', 'nexa_timeline_event',
        'nexa_outbox_event'
    )

    Invoke-SqlFile (Get-Item -LiteralPath $baseSchema) $cleanDatabase
    foreach ($migration in $migrations) { Invoke-SqlFile $migration $cleanDatabase }
    foreach ($pass in 1..2) { foreach ($seed in $seeds) { Invoke-SqlFile $seed $cleanDatabase } }
    $tableList = ($phase5Tables | ForEach-Object { "'$_'" }) -join ','
    Assert-Scalar $cleanDatabase "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$cleanDatabase' AND table_name IN ($tableList);" ([string]$phase5Tables.Count) 'Clean installation omitted Phase 5 tables.'
    Assert-Scalar $cleanDatabase "SELECT is_nullable FROM information_schema.columns WHERE table_schema='$cleanDatabase' AND table_name='nexa_timeline_event' AND column_name='service_id';" 'NO' 'Clean installation did not enforce timeline service ownership.'
    Assert-Scalar $cleanDatabase "SELECT COUNT(DISTINCT index_name) FROM information_schema.statistics WHERE table_schema='$cleanDatabase' AND index_name IN ('idx_nexa_behavior_retention','idx_nexa_behavior_replay_retention','idx_nexa_visitor_retention');" '3' 'Clean installation omitted Phase 5 retention indexes.'
    Assert-Scalar $cleanDatabase "SELECT COUNT(*) FROM nexa_tenant WHERE slug IN ('isolation-alpha','isolation-beta');" '2' 'Clean installation duplicated or omitted tenant fixtures.'

    Invoke-SqlFile (Get-Item -LiteralPath $baseSchema) $upgradeDatabase
    foreach ($migration in $migrations | Where-Object Name -LT '0053_add_behavior_event_foundation.sql') { Invoke-SqlFile $migration $upgradeDatabase }
    foreach ($seed in $seeds) { Invoke-SqlFile $seed $upgradeDatabase }
    Invoke-Sql @"
INSERT INTO contact (id, first_name, last_name, deleted, created_at, tenant_id, service_id)
VALUES ('phase5contact0001', 'Existing', 'Phase Five Contact', 0, UTC_TIMESTAMP(), '30000000-0000-4000-8000-000000000001', '20000000-0000-4000-8000-000000000001');
INSERT INTO nexa_timeline_event
    (id, tenant_id, contact_id, event_type, source_entity_type, source_entity_id, source_occurred_at, summary)
VALUES
    ('95000000-0000-4000-8000-000000000001', '30000000-0000-4000-8000-000000000001', 'phase5contact0001', 'contact.imported', 'Contact', '95000000-0000-4000-8000-000000000002', UTC_TIMESTAMP(6), 'Existing timeline event');
"@ $upgradeDatabase | Out-Null

    foreach ($migration in $phase5Migrations) { Invoke-SqlFile $migration $upgradeDatabase }
    Assert-Scalar $upgradeDatabase "SELECT COUNT(*) FROM contact WHERE id='phase5contact0001' AND first_name='Existing';" '1' 'Phase 5 upgrade changed or removed an existing Contact.'
    Assert-Scalar $upgradeDatabase "SELECT service_id FROM nexa_timeline_event WHERE id='95000000-0000-4000-8000-000000000001';" '20000000-0000-4000-8000-000000000001' 'Phase 5 upgrade did not backfill timeline service ownership.'
    Assert-Scalar $upgradeDatabase "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$upgradeDatabase' AND table_name IN ($tableList);" ([string]$phase5Tables.Count) 'Incremental upgrade omitted Phase 5 tables.'
    foreach ($pass in 1..2) { foreach ($seed in $seeds) { Invoke-SqlFile $seed $upgradeDatabase } }
    Assert-Scalar $upgradeDatabase "SELECT COUNT(*) FROM contact WHERE id='phase5contact0001';" '1' 'Phase 5 seed replay duplicated or removed the existing Contact.'

    Write-Host 'Phase 5 clean-install and incremental migration replay passed.' -ForegroundColor Green
}
finally {
    foreach ($database in @($cleanDatabase, $upgradeDatabase)) {
        if ($database -match '^nexa_phase5_[a-z_]+_test$') {
            try { Invoke-Sql "DROP DATABASE IF EXISTS ``$database``;" | Out-Null } catch { }
        }
    }
    $env:MYSQL_PWD = $previousPassword
}
