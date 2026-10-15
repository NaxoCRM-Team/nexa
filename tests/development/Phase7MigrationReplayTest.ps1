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
. (Join-Path $root 'scripts/dev/mariadb-version-policy.ps1')
$previousPassword = $env:MYSQL_PWD
$suffix = [Guid]::NewGuid().ToString('N')
$databases = @("nexa_p7_clean_$suffix", "nexa_p7_upgrade_$suffix")
$created = New-Object System.Collections.Generic.List[string]
function Invoke-Sql([string] $sql, [string] $database = '') {
    $arguments = @('--batch','--skip-column-names',"--host=$DatabaseHost","--port=$Port","--user=$User",'--ssl=FALSE')
    if ($Password -eq '') { $arguments += '--skip-password' }
    if ($database) { $arguments += $database }
    $result = @($sql | & $ClientPath @arguments)
    if ($LASTEXITCODE -ne 0) { throw "Phase 7 SQL replay failed in $database." }
    return $result
}
function Replay([IO.FileInfo] $file, [string] $database) {
    Write-Host "[$database] $($file.Name)"
    Invoke-Sql (Get-Content -LiteralPath $file.FullName -Raw) $database | Out-Null
}
function Assert-Value([string] $database, [string] $sql, [string] $expected, [string] $message) {
    $actual = @(Invoke-Sql $sql $database)
    if ($actual.Count -ne 1 -or [string]$actual[0] -ne $expected) { throw $message }
}
try {
    $ClientPath = (Get-Command $ClientPath -ErrorAction Stop).Source
    $env:MYSQL_PWD = if ($Password) { $Password } else { $null }
    Assert-NexaMariaDbVersion (@(Invoke-Sql 'SELECT VERSION();')[0]) 'MariaDB server' | Out-Null
    $migrations = @(Get-ChildItem (Join-Path $root 'database/shared/migrations') -Filter '*.sql' -File | Sort-Object Name)
    if ($migrations[-1].Name -ne '0085_add_job_dispatch_rotation.sql') { throw 'Review the Phase 7 migration endpoint before extending this gate.' }
    $base = Get-Item (Join-Path $root 'database/shared/testing/0000_espocrm_9_1_9_schema.sql')
    $seeds = @(Get-ChildItem (Join-Path $root 'database/shared/seeds') -Filter '*.sql' -File | Sort-Object Name)
    foreach ($database in $databases) {
        if ($database -notmatch '^nexa_p7_(clean|upgrade)_[a-f0-9]{32}$') { throw 'Unsafe replay database name.' }
        # CREATE without IF NOT EXISTS ensures cleanup never claims a pre-existing database.
        Invoke-Sql "CREATE DATABASE ``$database`` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" | Out-Null
        $created.Add($database)
        Replay $base $database
    }
    $clean = $databases[0]; $upgrade = $databases[1]
    foreach ($migration in $migrations) { Replay $migration $clean }
    foreach ($migration in $migrations | Where-Object Name -LT '0079_add_scoring_models.sql') { Replay $migration $upgrade }
    foreach ($database in $databases) { foreach ($seed in $seeds) { Replay $seed $database } }
    Invoke-Sql @"
INSERT INTO contact (id,first_name,last_name,deleted,created_at,tenant_id,service_id)
VALUES ('p7existing000001','Preserved','Phase Seven',0,UTC_TIMESTAMP(),'30000000-0000-4000-8000-000000000001','20000000-0000-4000-8000-000000000001');
INSERT INTO account (id,name,deleted,created_at,tenant_id,service_id)
VALUES ('p7account000001','Preserved Company',0,UTC_TIMESTAMP(),'30000000-0000-4000-8000-000000000001','20000000-0000-4000-8000-000000000001');
"@ $upgrade | Out-Null
    $phase7 = @($migrations | Where-Object Name -GE '0079_add_scoring_models.sql')
    foreach ($migration in $phase7) { Replay $migration $upgrade }
    Assert-Value $upgrade "SELECT COUNT(*) FROM contact WHERE id='p7existing000001' AND first_name='Preserved' AND deleted=0;" '1' 'Upgrade changed an existing Contact.'
    Assert-Value $upgrade "SELECT COUNT(*) FROM account WHERE id='p7account000001' AND name='Preserved Company' AND is_target_account=0;" '1' 'Upgrade changed an existing Account or automatically targeted it.'
    $tables = @('nexa_scoring_model','nexa_scoring_version','nexa_scoring_evaluation','nexa_scoring_adjustment','nexa_abm_list','nexa_personalization_identity_proof')
    $list = ($tables | ForEach-Object { "'$_'" }) -join ','
    foreach ($database in $databases) {
        Assert-Value $database "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$database' AND table_name IN ($list);" '6' 'Phase 7 tables are missing.'
        Assert-Value $database "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema='$database' AND table_name IN ($list) AND column_name IN ('tenant_id','service_id') AND is_nullable='NO';" '12' 'Required tenant/service ownership is missing.'
        Assert-Value $database "SELECT COUNT(*) FROM information_schema.referential_constraints WHERE constraint_schema='$database' AND table_name='nexa_personalization_identity_proof';" '2' 'Identity proof foreign keys are missing.'
        Assert-Value $database "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$database' AND table_name='nexa_job_dispatch_rotation';" '1' 'Global dispatch cursor is missing.'
    }
    $shape = "SELECT table_name,column_name,column_type,is_nullable,COALESCE(column_default,'<NULL>'),extra FROM information_schema.columns WHERE table_schema=DATABASE() AND (table_name IN ($list) OR table_name IN ('account','nexa_job_dispatch_rotation')) ORDER BY table_name,ordinal_position;"
    $a = @(Invoke-Sql $shape $clean); $b = @(Invoke-Sql $shape $upgrade)
    if (Compare-Object $a $b) { throw 'Clean and incremental Phase 7 column definitions differ.' }
    # Direct replay checks idempotent DDL separately from the normal checksum-ledger runner.
    Invoke-Sql "INSERT INTO nexa_job_dispatch_rotation (scope_key,last_tenant_id) VALUES (REPEAT('a',64),'preserved-dispatch-turn');" $upgrade | Out-Null
    foreach ($migration in $phase7) { Replay $migration $upgrade }
    Assert-Value $upgrade "SELECT last_tenant_id FROM nexa_job_dispatch_rotation WHERE scope_key=REPEAT('a',64);" 'preserved-dispatch-turn' 'Replay reset an existing dispatch cursor.'
    Assert-Value $upgrade "SELECT COUNT(*) FROM contact WHERE id='p7existing000001' AND first_name='Preserved';" '1' 'Repeated Phase 7 replay changed an existing Contact.'
    Write-Host 'Phase 7 clean, incremental and repeated DDL migration replay passed.' -ForegroundColor Green
} finally {
    try {
        foreach ($database in $created) {
            if ($database -notmatch '^nexa_p7_(clean|upgrade)_[a-f0-9]{32}$') { throw 'Unsafe cleanup database name.' }
            Invoke-Sql "DROP DATABASE ``$database``;" | Out-Null
        }
    } finally { $env:MYSQL_PWD = $previousPassword }
}
