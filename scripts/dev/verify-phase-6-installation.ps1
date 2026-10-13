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
$migrationRoot = Join-Path $root 'database\shared\migrations'
$migrations = @(Get-ChildItem $migrationRoot -Filter '*.sql' -File | Sort-Object Name)
$seeds = @(Get-ChildItem (Join-Path $root 'database\shared\seeds') -Filter '*.sql' -File | Sort-Object Name)
$suffix = [guid]::NewGuid().ToString('N')
$databases = @("nexa_p6_acceptance_${suffix}_clean", "nexa_p6_acceptance_${suffix}_upgrade")
$created = @()
$previousPassword = $env:MYSQL_PWD
. (Join-Path $PSScriptRoot 'mariadb-version-policy.ps1')

function Assert-OwnedDatabase([string] $Database) {
    if ($databases -notcontains $Database -or $Database -notmatch '^nexa_p6_acceptance_[a-f0-9]{32}_(clean|upgrade)$') {
        throw 'Refusing a non-owned acceptance database.'
    }
}

function Invoke-Sql([string] $Sql, [string] $Database = '') {
    $arguments = @('--batch', '--skip-column-names', '--ssl=FALSE', "--host=$DatabaseHost", "--port=$Port", "--user=$User")
    if ($Password -eq '') { $arguments += '--skip-password' }
    if ($Database) { Assert-OwnedDatabase $Database; $arguments += $Database }
    $output = @($Sql | & $ClientPath @arguments)
    if ($LASTEXITCODE -ne 0) { throw 'MariaDB acceptance statement failed.' }
    return $output
}

function Apply-File([IO.FileInfo] $File, [string] $Database) {
    Invoke-Sql (Get-Content -LiteralPath $File.FullName -Raw) $Database | Out-Null
}

function Assert-Value([string] $Database, [string] $Sql, [string] $Expected, [string] $Message) {
    $actual = @(Invoke-Sql $Sql $Database)
    if ($actual.Count -ne 1 -or [string] $actual[0] -ne $Expected) { throw $Message }
}

function Run-CurrentMigrations([string] $Database, [switch] $Initialize) {
    $arguments = @{
        Mode = 'Local'; Database = $Database; ClientPath = $ClientPath
        DatabaseHost = $DatabaseHost; Port = $Port; User = $User; Password = $Password
    }
    if ($Initialize) { $arguments.InitializeBaseSchema = $true }
    & (Join-Path $PSScriptRoot 'apply-shared-schema.ps1') @arguments
}

function Assert-Schema([string] $Database) {
    Assert-Value $Database 'SELECT COUNT(*) FROM nexa_schema_migration;' ([string] $migrations.Count) 'Missing migration tracking rows.'
    foreach ($migration in $migrations) {
        $hash = (Get-FileHash $migration.FullName -Algorithm SHA256).Hash.ToLowerInvariant()
        Assert-Value $Database "SELECT checksum_sha256 FROM nexa_schema_migration WHERE migration_id='$($migration.Name)';" $hash 'Migration checksum differs from repository.'
    }
    Assert-Value $Database "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema='$Database' AND table_name='nexa_marketing_monthly_usage' AND column_name IN ('transactional_accepted_count','transactional_delivered_count','transactional_failed_count');" '3' 'Missing transactional usage columns.'
    Assert-Value $Database "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema='$Database' AND table_name='nexa_marketing_delivery_message' AND column_name IN ('usage_class','reserved_usage_month','reservation_released_at','provider_record_id','last_inspected_at','inspection_attempt_count');" '6' 'Missing reservation or Postal inspection columns.'
    Assert-Value $Database "SELECT COUNT(*) FROM nexa_service_definition WHERE service_key='email.transactional';" '1' 'Missing transactional service definition.'
    Assert-Value $Database "SELECT COUNT(*) FROM nexa_tenant_service ts JOIN nexa_service_definition sd ON sd.id=ts.service_id WHERE sd.service_key='email.transactional';" '0' 'Installation silently granted transactional sends.'
    foreach ($constraint in @('fk_nexa_marketing_usage_scope','fk_nexa_delivery_message_scope','fk_workflow_scope','fk_workflow_version','fk_workflow_run_version')) {
        Assert-Value $Database "SELECT GROUP_CONCAT(column_name ORDER BY ordinal_position) FROM information_schema.key_column_usage WHERE constraint_schema='$Database' AND constraint_name='$constraint' AND referenced_table_name IS NOT NULL;" $(if ($constraint -eq 'fk_workflow_version') { 'tenant_id,service_id,workflow_id' } elseif ($constraint -eq 'fk_workflow_run_version') { 'tenant_id,service_id,workflow_id,version_number' } else { 'tenant_id,service_id' }) 'Missing tenant/service ownership foreign key.'
    }
    Assert-Value $Database "SELECT GROUP_CONCAT(column_name ORDER BY seq_in_index) FROM information_schema.statistics WHERE table_schema='$Database' AND table_name='nexa_marketing_delivery_message' AND index_name='uq_nexa_delivery_queue_item' AND non_unique=0;" 'tenant_id,service_id,email_queue_item_id' 'Queue replay uniqueness is missing.'
    Assert-Value $Database "SELECT GROUP_CONCAT(column_name ORDER BY seq_in_index) FROM information_schema.statistics WHERE table_schema='$Database' AND table_name='nexa_workflow_run' AND index_name='uq_workflow_request' AND non_unique=0;" 'tenant_id,service_id,workflow_id,request_key_hash' 'Workflow replay uniqueness is missing.'
    if ($migrations.Name -contains '0077_add_governed_email_experiments.sql') {
        Assert-Value $Database "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$Database' AND table_name IN ('nexa_email_experiment','nexa_email_experiment_variant','nexa_email_experiment_assignment','nexa_email_experiment_event');" '4' 'Missing governed experiment tables.'
        foreach ($constraint in @('fk_email_experiment_variant','fk_email_experiment_assignment','fk_email_experiment_event')) {
            Assert-Value $Database "SELECT GROUP_CONCAT(column_name ORDER BY ordinal_position) FROM information_schema.key_column_usage WHERE constraint_schema='$Database' AND constraint_name='$constraint' AND referenced_table_name='nexa_email_experiment';" 'tenant_id,service_id,experiment_id' 'Missing experiment ownership foreign key.'
        }
        Assert-Value $Database "SELECT GROUP_CONCAT(column_name ORDER BY seq_in_index) FROM information_schema.statistics WHERE table_schema='$Database' AND table_name='nexa_email_experiment_assignment' AND index_name='uq_email_experiment_recipient' AND non_unique=0;" 'tenant_id,service_id,experiment_id,recipient_sha256' 'Missing experiment recipient replay uniqueness.'
    }
    if ($migrations.Name -match '^0078_') {
        Assert-Value $Database "SELECT GROUP_CONCAT(column_name ORDER BY ordinal_position) FROM information_schema.key_column_usage WHERE constraint_schema='$Database' AND constraint_name='fk_workflow_ab_version' AND referenced_table_name='nexa_workflow_version';" 'tenant_id,service_id,workflow_id,version_number' 'Missing workflow A/B version ownership foreign key.'
        Assert-Value $Database "SELECT GROUP_CONCAT(column_name ORDER BY seq_in_index) FROM information_schema.statistics WHERE table_schema='$Database' AND table_name='nexa_workflow_ab_assignment' AND index_name='PRIMARY' AND non_unique=0;" 'tenant_id,service_id,run_id,node_id' 'Missing workflow A/B assignment replay uniqueness.'
    }
}

try {
    $command = Get-Command $ClientPath -ErrorAction SilentlyContinue
    if ($command) { $ClientPath = $command.Source } else {
        $ClientPath = Get-ChildItem 'C:\wamp64\bin\mariadb' -Directory -ErrorAction SilentlyContinue |
            ForEach-Object { Join-Path $_.FullName 'bin\mariadb.exe' } |
            Where-Object { Test-Path -LiteralPath $_ } | Sort-Object -Descending | Select-Object -First 1
    }
    if (-not $ClientPath) { throw 'MariaDB client not found.' }
    $env:MYSQL_PWD = if ($Password) { $Password } else { $null }
    Assert-NexaMariaDbVersion (@(Invoke-Sql 'SELECT VERSION();')[0]) 'MariaDB server' | Out-Null
    foreach ($database in $databases) {
        Assert-OwnedDatabase $database
        Invoke-Sql "CREATE DATABASE ``$database`` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" | Out-Null
        $created += $database
    }
    $clean = $databases[0]
    $upgrade = $databases[1]
    Run-CurrentMigrations $clean -Initialize
    Assert-Schema $clean
    $trackingBefore = @(Invoke-Sql 'SELECT migration_id,checksum_sha256,applied_at FROM nexa_schema_migration ORDER BY migration_id;' $clean)
    Run-CurrentMigrations $clean
    $trackingAfter = @(Invoke-Sql 'SELECT migration_id,checksum_sha256,applied_at FROM nexa_schema_migration ORDER BY migration_id;' $clean)
    if (($trackingBefore -join "`n") -cne ($trackingAfter -join "`n")) { throw 'Replaying migrations changed the ledger.' }
    $initializationRejected = $false
    try { Run-CurrentMigrations $clean -Initialize } catch {
        if ($_.Exception.Message -notlike '*requires an empty database*') { throw }
        $initializationRejected = $true
    }
    if (-not $initializationRejected) { throw 'Base initialization accepted a populated database.' }
    $first = $migrations[0]
    $firstHash = (Get-FileHash $first.FullName -Algorithm SHA256).Hash.ToLowerInvariant()
    Invoke-Sql "UPDATE nexa_schema_migration SET checksum_sha256=REPEAT('0',64) WHERE migration_id='$($first.Name)';" $clean | Out-Null
    $checksumRejected = $false
    try { Run-CurrentMigrations $clean } catch {
        if ($_.Exception.Message -notlike '*has changed*') { throw }
        $checksumRejected = $true
    }
    Invoke-Sql "UPDATE nexa_schema_migration SET checksum_sha256='$firstHash' WHERE migration_id='$($first.Name)';" $clean | Out-Null
    if (-not $checksumRejected) { throw 'Runner accepted a changed applied checksum.' }
    Apply-File (Get-Item (Join-Path $root 'database\shared\testing\0000_espocrm_9_1_9_schema.sql')) $upgrade
    foreach ($migration in $migrations | Where-Object Name -LT '0075') {
        Write-Host "[BASELINE] $($migration.Name)" -ForegroundColor DarkGray
        Apply-File $migration $upgrade
        $hash = (Get-FileHash $migration.FullName -Algorithm SHA256).Hash.ToLowerInvariant()
        Invoke-Sql "INSERT INTO nexa_schema_migration(migration_id,checksum_sha256,execution_ms,applied_by) VALUES ('$($migration.Name)','$hash',0,'phase6-baseline');" $upgrade | Out-Null
    }
    foreach ($seed in $seeds) { Apply-File $seed $upgrade }
    Invoke-Sql @'
INSERT INTO contact(id,first_name,last_name,deleted,tenant_id,service_id) VALUES
('p6preservecontact','Phase Six','Existing Contact',0,'30000000-0000-4000-8000-000000000001','20000000-0000-4000-8000-000000000001');
INSERT INTO nexa_marketing_monthly_usage(tenant_id,service_id,usage_month,accepted_count,delivered_count,failed_count,transactional_accepted_count,transactional_delivered_count,transactional_failed_count)
VALUES ('30000000-0000-4000-8000-000000000001','20000000-0000-4000-8000-000000000001','2026-10-01',17,9,2,6,4,1);
'@ $upgrade | Out-Null
    $recordQuery = "SELECT CONCAT(first_name,'|',last_name,'|',tenant_id,'|',service_id) FROM contact WHERE id='p6preservecontact';"
    $usageQuery = 'SELECT CONCAT(accepted_count,''|'',delivered_count,''|'',failed_count,''|'',transactional_accepted_count,''|'',transactional_delivered_count,''|'',transactional_failed_count) FROM nexa_marketing_monthly_usage;'
    $recordBefore = @(Invoke-Sql $recordQuery $upgrade)[0]
    $usageBefore = @(Invoke-Sql $usageQuery $upgrade)[0]
    Run-CurrentMigrations $upgrade
    Run-CurrentMigrations $upgrade
    Assert-Schema $upgrade
    Assert-Value $upgrade $recordQuery $recordBefore 'Upgrade changed the existing Contact.'
    Assert-Value $upgrade $usageQuery $usageBefore 'Upgrade changed existing usage counters.'
    $schemaQuery = "SELECT table_name,column_name,column_type,is_nullable,COALESCE(column_default,'NULL') FROM information_schema.columns WHERE table_schema=DATABASE() ORDER BY table_name,ordinal_position;"
    $cleanSchema = @(Invoke-Sql $schemaQuery $clean) -join "`n"
    $upgradeSchema = @(Invoke-Sql $schemaQuery $upgrade) -join "`n"
    if ($cleanSchema -cne $upgradeSchema) { throw 'Clean and incremental column schemas diverged.' }
    Write-Host "Phase 6 clean and 0074 incremental schema acceptance passed through $($migrations[-1].Name)." -ForegroundColor Green
}
finally {
    try {
        foreach ($database in $created) {
            Assert-OwnedDatabase $database
            Invoke-Sql "DROP DATABASE ``$database``;" | Out-Null
        }
    }
    finally {
        $env:MYSQL_PWD = $previousPassword
        $Password = ''
    }
}
