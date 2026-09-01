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
$cleanDatabase = 'nexa_phase3_clean_test'
$upgradeDatabase = 'nexa_phase3_upgrade_test'
$previousPassword = $env:MYSQL_PWD

function Resolve-MariaDbClient([string] $requested) {
    $command = Get-Command $requested -ErrorAction SilentlyContinue
    if ($command) { return $command.Source }

    $candidate = Get-ChildItem 'C:\wamp64\bin\mariadb' -Directory -ErrorAction SilentlyContinue |
        ForEach-Object { Join-Path $_.FullName 'bin\mariadb.exe' } |
        Where-Object { Test-Path -LiteralPath $_ } |
        Sort-Object -Descending |
        Select-Object -First 1
    if ($candidate) { return $candidate }

    throw 'A MariaDB client is required for Phase 3 migration replay.'
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
        if ($database -notmatch '^nexa_phase3_[a-z_]+_test$') { throw "Unsafe test database name: $database" }
        Invoke-Sql "DROP DATABASE IF EXISTS ``$database``; CREATE DATABASE ``$database`` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" | Out-Null
    }

    $migrations = @(Get-ChildItem -LiteralPath $migrationRoot -Filter '*.sql' -File | Sort-Object Name)
    $seeds = @(Get-ChildItem -LiteralPath $seedRoot -Filter '*.sql' -File | Sort-Object Name)

    Invoke-SqlFile (Get-Item -LiteralPath $baseSchema) $cleanDatabase
    foreach ($migration in $migrations) { Invoke-SqlFile $migration $cleanDatabase }
    foreach ($pass in 1..2) { foreach ($seed in $seeds) { Invoke-SqlFile $seed $cleanDatabase } }
    Assert-Scalar $cleanDatabase "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema='$cleanDatabase' AND table_name='case' AND column_name IN ('sla_status','resolution_due_at','opportunity_id');" '3' 'Clean installation omitted Case service columns.'
    Assert-Scalar $cleanDatabase "SELECT COUNT(*) FROM nexa_tenant WHERE slug IN ('isolation-alpha','isolation-beta');" '2' 'Clean installation did not preserve idempotent two-tenant seeds.'

    Invoke-SqlFile (Get-Item -LiteralPath $baseSchema) $upgradeDatabase
    foreach ($migration in $migrations | Where-Object Name -LT '0042_add_case_service_sla.sql') {
        Invoke-SqlFile $migration $upgradeDatabase
    }
    foreach ($seed in $seeds) { Invoke-SqlFile $seed $upgradeDatabase }
    Invoke-Sql @"
INSERT INTO user (id,user_name,type,password,is_active,delete_id,tenant_id,service_id)
VALUES ('phase3owner000001','phase3-owner','admin','test-only',1,'0','30000000-0000-4000-8000-000000000001','20000000-0000-4000-8000-000000000001');
INSERT INTO ``case`` (id,name,deleted,status,priority,created_at,assigned_user_id,tenant_id,service_id)
VALUES ('phase3case0000001','Existing Phase 3 Case',0,'New','Normal',UTC_TIMESTAMP(),NULL,'30000000-0000-4000-8000-000000000001','20000000-0000-4000-8000-000000000001');
INSERT INTO entity_user (entity_id,user_id,entity_type,deleted,tenant_id,service_id)
VALUES ('phase3case0000001','phase3owner000001','Case',0,'30000000-0000-4000-8000-000000000001','20000000-0000-4000-8000-000000000001');
"@ $upgradeDatabase | Out-Null

    $caseMigration = $migrations | Where-Object Name -EQ '0042_add_case_service_sla.sql'
    Invoke-SqlFile $caseMigration $upgradeDatabase
    Assert-Scalar $upgradeDatabase 'SELECT COUNT(*) FROM `case` WHERE id=''phase3case0000001'' AND name=''Existing Phase 3 Case'';' '1' 'Incremental migration changed or removed the existing Case.'
    Assert-Scalar $upgradeDatabase 'SELECT COUNT(*) FROM `case` WHERE id=''phase3case0000001'' AND assigned_user_id=''phase3owner000001'' AND sla_policy_id IS NOT NULL AND first_response_due_at IS NOT NULL AND resolution_due_at IS NOT NULL;' '1' 'Incremental migration did not reconcile ownership and SLA deadlines.'
    Assert-Scalar $upgradeDatabase "SELECT COUNT(*) FROM nexa_case_sla_policy WHERE tenant_id='30000000-0000-4000-8000-000000000001';" '4' 'Incremental migration did not create the four tenant SLA policies.'

    Invoke-SqlFile $caseMigration $upgradeDatabase
    Assert-Scalar $upgradeDatabase "SELECT COUNT(*) FROM nexa_case_sla_policy WHERE tenant_id='30000000-0000-4000-8000-000000000001';" '4' 'Case migration replay duplicated tenant SLA policies.'
    Assert-Scalar $upgradeDatabase 'SELECT COUNT(*) FROM `case` WHERE id=''phase3case0000001'';' '1' 'Case migration replay duplicated or removed the existing Case.'

    Write-Host 'Phase 3 clean-install and incremental migration replay passed.' -ForegroundColor Green
}
finally {
    foreach ($database in @($cleanDatabase, $upgradeDatabase)) {
        if ($database -match '^nexa_phase3_[a-z_]+_test$') {
            try { Invoke-Sql "DROP DATABASE IF EXISTS ``$database``;" | Out-Null } catch { }
        }
    }
    $env:MYSQL_PWD = $previousPassword
}
