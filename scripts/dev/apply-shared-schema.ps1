[CmdletBinding()]
param(
    [ValidateSet('Docker', 'Local')]
    [string] $Mode = 'Docker',
    [string] $Database = 'espocrm',
    [string] $ClientPath = 'mariadb',
    [string] $DatabaseHost = '127.0.0.1',
    [int] $Port = 3306,
    [string] $User = 'root',
    [string] $Password = '',
    [string] $EnvironmentFile = '.env',
    [switch] $InitializeBaseSchema,
    [switch] $IncludeDevelopmentSeeds
)

$ErrorActionPreference = 'Stop'
$root = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$migrationRoot = Join-Path $root 'database\shared\migrations'
$seedRoot = Join-Path $root 'database\shared\seeds'
$baseSchema = Join-Path $root 'database\shared\testing\0000_espocrm_9_1_9_schema.sql'
$localPassword = ""
$previousMysqlPassword = $null
. (Join-Path $PSScriptRoot 'mariadb-version-policy.ps1')

function Resolve-LocalMariaDbClient([string] $RequestedClient) {
    $command = Get-Command $RequestedClient -ErrorAction SilentlyContinue
    if ($command) { return $command.Source }
    $programFilesCandidates = Get-ChildItem 'C:\Program Files' -Directory -Filter 'MariaDB *' -ErrorAction SilentlyContinue |
        ForEach-Object { Join-Path $_.FullName 'bin\mariadb.exe' } |
        Where-Object { Test-Path -LiteralPath $_ -PathType Leaf } |
        Sort-Object -Descending
    $wampCandidates = Get-ChildItem 'C:\wamp64\bin\mariadb' -Directory -ErrorAction SilentlyContinue |
        ForEach-Object { Join-Path $_.FullName 'bin\mariadb.exe' } |
        Where-Object { Test-Path -LiteralPath $_ -PathType Leaf } |
        Sort-Object -Descending
    $candidates = @($wampCandidates) + @($programFilesCandidates)
    $installed = $candidates | Where-Object { Test-Path -LiteralPath $_ } | Select-Object -First 1
    if ($installed) { return $installed }
    throw "A supported MariaDB client was not found."
}

function Get-LocalArguments([switch] $SkipDatabase) {
    $arguments = @('--batch', '--skip-column-names', "--host=$DatabaseHost", "--port=$Port", "--user=$User", '--ssl=FALSE')
    if ($Password -eq '') { $arguments += '--skip-password' }
    if (-not $SkipDatabase) { $arguments += $Database }
    return $arguments
}

function Invoke-LocalQuery([string] $Sql) {
    $output = @($Sql | & $ClientPath @(Get-LocalArguments -SkipDatabase))
    if ($LASTEXITCODE -ne 0) { throw 'Local MariaDB query failed.' }
    return $output
}

function Invoke-LocalFile([IO.FileInfo] $File) {
    Get-Content -LiteralPath $File.FullName -Raw | & $ClientPath @(Get-LocalArguments)
    if ($LASTEXITCODE -ne 0) { throw "Migration failed: $($File.Name)" }
}

function Invoke-Query([string] $Sql) {
    return Invoke-LocalQuery $Sql
}

function Invoke-SqlFile([IO.FileInfo] $File) {
    Invoke-LocalFile $File
}

try {
    if (-not $PSBoundParameters.ContainsKey('Password') -and (Test-Path -LiteralPath (Join-Path $root $EnvironmentFile))) {
        foreach ($line in Get-Content -LiteralPath (Join-Path $root $EnvironmentFile)) {
            if ($line -match '^DB_PASSWORD=(.*)$') { $Password = $matches[1].Trim().Trim('"').Trim("'") }
        }
    }

    $ClientPath = Resolve-LocalMariaDbClient $ClientPath
    $clientVersion = (& $ClientPath --version 2>&1 | Out-String).Trim()
    if ($LASTEXITCODE -ne 0) { throw "Unable to read the MariaDB client version: $clientVersion" }
    Assert-NexaMariaDbVersion $clientVersion 'MariaDB client' | Out-Null
    Write-Host "Using $ClientPath ($clientVersion)" -ForegroundColor DarkGray
    
    $localPassword = $Password
    $previousMysqlPassword = $env:MYSQL_PWD
    $env:MYSQL_PWD = if ($Password -ne '') { $Password } else { $null }

    $serverVersion = @((Invoke-LocalQuery 'SELECT VERSION();'))
    Assert-NexaMariaDbVersion $serverVersion 'MariaDB server' | Out-Null

    if ($InitializeBaseSchema) {
        $tableCount = @((Invoke-Query "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$Database';"))
        if ([int] $tableCount -ne 0) {
            throw "Base-schema initialization requires an empty database."
        }
        Write-Host '[BASE] EspoCRM 9.1.9 schema' -ForegroundColor Cyan
        Invoke-SqlFile (Get-Item -LiteralPath $baseSchema)
    }

    $migrations = @(Get-ChildItem -LiteralPath $migrationRoot -Filter '*.sql' -File | Sort-Object Name)
    foreach ($migration in $migrations) {
        $checksum = (Get-FileHash -LiteralPath $migration.FullName -Algorithm SHA256).Hash.ToLowerInvariant()
        $started = Get-Date
        
        $trackingExists = @((Invoke-Query "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$Database' AND table_name='nexa_schema_migration';"))[0] -eq '1'
        if ($trackingExists) {
            $stored = @((Invoke-Query "SELECT checksum_sha256 FROM $Database.nexa_schema_migration WHERE migration_id='$($migration.Name)';"))
            if ($stored.Count -gt 0) {
                if ($stored[0] -ne $checksum) {
                    throw "Applied migration $($migration.Name) has changed. Restore it and create a new numbered migration."
                }
                Write-Host "[SKIP] $($migration.Name)" -ForegroundColor DarkGray
                continue
            }
        }

        Write-Host "[APPLY] $($migration.Name)" -ForegroundColor Cyan
        Invoke-SqlFile $migration
        $elapsed = [int]((Get-Date) - $started).TotalMilliseconds
        $trackingSql = "INSERT INTO $Database.nexa_schema_migration (migration_id, checksum_sha256, execution_ms, applied_by) VALUES ('$($migration.Name)', '$checksum', $elapsed, 'local') ON DUPLICATE KEY UPDATE checksum_sha256=VALUES(checksum_sha256), execution_ms=VALUES(execution_ms), applied_at=CURRENT_TIMESTAMP(6), applied_by=VALUES(applied_by);"
        Invoke-Query $trackingSql | Out-Null
    }

    Write-Host 'Shared-schema database migrations are current.' -ForegroundColor Green
}
finally {
    $localPassword = $null
    $Password = ''
    $env:MYSQL_PWD = $previousMysqlPassword
}
