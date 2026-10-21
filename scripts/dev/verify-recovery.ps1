[CmdletBinding()]
param(
    [string] $ClientPath = 'C:\wamp64\bin\mariadb\mariadb11.4.9\bin\mariadb.exe',
    [string] $DumpPath = 'C:\wamp64\bin\mariadb\mariadb11.4.9\bin\mariadb-dump.exe',
    [ValidateSet('127.0.0.1', 'localhost')] [string] $DatabaseHost = '127.0.0.1',
    [int] $Port = 3306,
    [string] $User = 'root',
    [string] $Password = ''
)
$ErrorActionPreference = 'Stop'
$root = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$id = [guid]::NewGuid().ToString('N')
$owned = @("nexa_recovery_${id}_source", "nexa_recovery_${id}_restored")
$created = @()
$oldPassword = $env:MYSQL_PWD
$directory = Join-Path $root "test-results\recovery-$id"
$clock = [Diagnostics.Stopwatch]::StartNew()

function Assert-Owned([string] $Database) {
    if ($Database -notin $owned -or $Database -notmatch '^nexa_recovery_[a-f0-9]{32}_(source|restored)$') {
        throw 'Refusing to access a database not created by this drill.'
    }
}
function Query([string] $Sql, [string] $Database = '') {
    $arguments = @('--no-defaults', '--batch', '--skip-column-names', '--default-character-set=utf8mb4', '--ssl=FALSE', "--host=$DatabaseHost", "--port=$Port", "--user=$User")
    if (-not $Password) { $arguments += '--skip-password' }
    if ($Database) { Assert-Owned $Database; $arguments += $Database }
    $result = @($Sql | & $ClientPath @arguments)
    if ($LASTEXITCODE -ne 0) { throw 'Recovery drill database statement failed.' }
    return $result
}
function Snapshot([string] $Database) {
    Assert-Owned $Database
    $tables = @(Query 'SHOW TABLES;' $Database)
    $rows = foreach ($table in $tables) {
        if ($table -notmatch '^[a-zA-Z0-9_]+$') { throw 'Unexpected table identifier.' }
        "$table`t$(@(Query "SELECT COUNT(*) FROM ``$table``;" $Database)[0])"
    }
    return @($rows) + @(Query 'SELECT migration_id,checksum_sha256,applied_at FROM nexa_schema_migration ORDER BY migration_id;' $Database) + @(Query 'SELECT id,first_name,last_name,tenant_id,service_id FROM contact ORDER BY id;' $Database)
}
try {
    if (-not (Get-Command $ClientPath -ErrorAction SilentlyContinue) -or -not (Get-Command $DumpPath -ErrorAction SilentlyContinue)) {
        throw 'Provide matching local MariaDB client and dump executables.'
    }
    $env:MYSQL_PWD = if ($Password) { $Password } else { $null }
    foreach ($database in $owned) {
        Assert-Owned $database
        Query "CREATE DATABASE ``$database`` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" | Out-Null
        $created += $database
    }
    $migrationArguments = @{
        Mode='Local'; ClientPath=$ClientPath; Database=$owned[0]; DatabaseHost=$DatabaseHost
        Port=$Port; User=$User; Password=$Password; InitializeBaseSchema=$true; IncludeDevelopmentSeeds=$true
    }
    & (Join-Path $PSScriptRoot 'apply-shared-schema.ps1') @migrationArguments
    Query @'
INSERT INTO contact(id,first_name,last_name,tenant_id,service_id,deleted) VALUES
('recovery-alpha','Synthetic','Recovery Alpha','30000000-0000-4000-8000-000000000001','20000000-0000-4000-8000-000000000001',0),
('recovery-beta','Synthetic','Recovery Beta','30000000-0000-4000-8000-000000000002','20000000-0000-4000-8000-000000000001',0);
'@ $owned[0] | Out-Null
    $before = @(Snapshot $owned[0])
    New-Item -ItemType Directory -Path $directory -Force | Out-Null
    $dump = Join-Path $directory 'synthetic.sql'
    $arguments = @('--no-defaults', '--single-transaction', '--quick', '--hex-blob', '--routines', '--events', '--triggers', '--default-character-set=utf8mb4', '--ssl=FALSE', "--host=$DatabaseHost", "--port=$Port", "--user=$User", "--result-file=$dump", $owned[0])
    if (-not $Password) { $arguments += '--skip-password' }
    & $DumpPath @arguments
    if ($LASTEXITCODE -ne 0 -or -not (Test-Path -LiteralPath $dump)) { throw 'Synthetic backup failed.' }
    $hash = (Get-FileHash -LiteralPath $dump -Algorithm SHA256).Hash
    $restoreClock = [Diagnostics.Stopwatch]::StartNew()
    # SOURCE reads the dump without a PowerShell encoding round trip.
    $source = $dump.Replace('\', '/')
    if ($source -match '[;\r\n]') { throw 'Unsafe dump filename.' }
    Query "SOURCE $source;" $owned[1] | Out-Null
    if ((Get-FileHash -LiteralPath $dump -Algorithm SHA256).Hash -cne $hash) { throw 'Backup checksum changed during restoration.' }
    $after = @(Snapshot $owned[1])
    if (($before -join "`n") -cne ($after -join "`n")) { throw 'Restored tables, row counts, tenant sentinels or migration ledger differ.' }
    $migrationArguments.Database = $owned[1]
    $migrationArguments.Remove('InitializeBaseSchema')
    $migrationArguments.Remove('IncludeDevelopmentSeeds')
    & (Join-Path $PSScriptRoot 'apply-shared-schema.ps1') @migrationArguments
    if (($after -join "`n") -cne (@(Snapshot $owned[1]) -join "`n")) { throw 'Migration replay changed restored records or the checksum ledger.' }
    [pscustomobject]@{
        status='passed'; scope='synthetic local database only'; utc=[DateTime]::UtcNow.ToString('o')
        dumpSha256=$hash; restoreSeconds=[math]::Round($restoreClock.Elapsed.TotalSeconds,2)
        totalSeconds=[math]::Round($clock.Elapsed.TotalSeconds,2); snapshotAssertions=$after.Count
        externalFilesVerified=$false; productionRecoveryVerified=$false
    } | ConvertTo-Json | Set-Content -LiteralPath (Join-Path $directory 'evidence.json') -Encoding UTF8
    Write-Host "Recovery drill passed. Evidence: $directory\evidence.json"
    Write-Warning 'Synthetic database restore only. No production backup, encryption, R2 restoration or recovery SLA is established.'
} finally {
    try {
        foreach ($database in $created) {
            Assert-Owned $database
            Query "DROP DATABASE ``$database``;" | Out-Null
        }
    } finally {
        $env:MYSQL_PWD = $oldPassword
    }
}
