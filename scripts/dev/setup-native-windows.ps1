[CmdletBinding()]
param(
    [string] $PhpPath = 'php',
    [string] $ClientPath = 'mariadb',
    [string] $EnvironmentFile = '.env',
    [string] $DatabaseHost,
    [int] $DatabasePort,
    [string] $DatabaseRootUser = 'root',
    [string] $SiteUrl,
    [switch] $SkipHttpCheck
)

$ErrorActionPreference = 'Stop'
$root = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$environmentPath = if ([IO.Path]::IsPathRooted($EnvironmentFile)) {
    $EnvironmentFile
} else {
    Join-Path $root $EnvironmentFile
}
$previousMysqlPassword = [Environment]::GetEnvironmentVariable('MYSQL_PWD', 'Process')
. (Join-Path $PSScriptRoot 'mariadb-version-policy.ps1')

function Read-EnvironmentFile([string] $Path) {
    $values = @{}
    if (-not (Test-Path -LiteralPath $Path)) { return $values }

    foreach ($line in Get-Content -LiteralPath $Path) {
        if ($line -match '^\s*#' -or $line -notmatch '^\s*([A-Za-z_][A-Za-z0-9_]*)=(.*)$') { continue }
        $values[$matches[1]] = $matches[2].Trim().Trim('"').Trim("'")
    }

    return $values
}

function Set-EnvironmentValue([string] $Path, [string] $Name, [string] $Value) {
    $content = Get-Content -LiteralPath $Path -Raw
    $replacement = $Name + '=' + $Value.Replace('$', '$$')

    if ($content -match '(?m)^' + [regex]::Escape($Name) + '=') {
        $content = [regex]::Replace(
            $content,
            '(?m)^' + [regex]::Escape($Name) + '=.*$',
            $replacement
        )
    } else {
        $content = $content.TrimEnd([char[]] [Environment]::NewLine) +
            [Environment]::NewLine + $Name + '=' + $Value + [Environment]::NewLine
    }

    Set-Content -LiteralPath $Path -Value $content -Encoding ASCII
}

function ConvertFrom-SecurePassword([Security.SecureString] $Password) {
    $pointer = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($Password)
    try { return [Runtime.InteropServices.Marshal]::PtrToStringBSTR($pointer) }
    finally { [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($pointer) }
}

function Resolve-Executable([string] $Requested, [string[]] $Candidates) {
    $command = Get-Command $Requested -ErrorAction SilentlyContinue
    if ($command) { return $command.Source }

    $installed = $Candidates |
        Where-Object { $_ -and (Test-Path -LiteralPath $_ -PathType Leaf) } |
        Select-Object -First 1
    if ($installed) { return $installed }

    throw "Executable not found: $Requested"
}

function Invoke-RootQuery([string] $Sql, [string] $Password) {
    if ([string]::IsNullOrEmpty($Password)) {
        Remove-Item Env:MYSQL_PWD -ErrorAction SilentlyContinue
    } else {
        $env:MYSQL_PWD = $Password
    }

    $arguments = @(
        '--batch',
        '--skip-column-names',
        "--host=$DatabaseHost",
        "--port=$DatabasePort",
        "--user=$DatabaseRootUser"
    )
    $output = @($Sql | & $script:MariaDbClient @arguments 2>&1)

    return [pscustomobject]@{
        ExitCode = $LASTEXITCODE
        Output = $output
    }
}

try {
    $wampPhp = Get-ChildItem 'C:\wamp64\bin\php' -Filter 'php.exe' -File -Recurse -ErrorAction SilentlyContinue |
        Sort-Object FullName -Descending |
        Select-Object -ExpandProperty FullName
    $PhpPath = Resolve-Executable $PhpPath (@($wampPhp) + @('C:\xampp\php\php.exe'))
    $env:Path = (Split-Path $PhpPath) + ';' + $env:Path

    & (Join-Path $PSScriptRoot 'setup.ps1') -SkipStart
    if ($LASTEXITCODE -ne 0) { throw 'Repository prerequisite setup failed.' }

    $environment = Read-EnvironmentFile $environmentPath
    if (-not $PSBoundParameters.ContainsKey('DatabaseHost')) {
        $DatabaseHost = if ($environment['DB_HOST']) { $environment['DB_HOST'] } else { '127.0.0.1' }
    }
    if (-not $PSBoundParameters.ContainsKey('DatabasePort')) {
        $DatabasePort = if ($environment['DB_PORT']) { [int] $environment['DB_PORT'] } else { 3306 }
    }
    if (-not $PSBoundParameters.ContainsKey('SiteUrl')) {
        $SiteUrl = if ($environment['ESPOCRM_SITE_URL']) { $environment['ESPOCRM_SITE_URL'] } else { 'http://nexa.local' }
    }

    Set-EnvironmentValue $environmentPath 'ESPOCRM_SITE_URL' $SiteUrl
    Set-EnvironmentValue $environmentPath 'DB_HOST' $DatabaseHost
    Set-EnvironmentValue $environmentPath 'DB_PORT' ([string] $DatabasePort)
    Set-EnvironmentValue $environmentPath 'DB_USER' 'espocrm'
    $environment = Read-EnvironmentFile $environmentPath

    foreach ($name in @('DB_NAME', 'DB_PASSWORD', 'ADMIN_USERNAME', 'ADMIN_PASSWORD')) {
        if ([string]::IsNullOrWhiteSpace($environment[$name]) -or $environment[$name] -like 'replace_with_*') {
            throw "Set $name in the ignored .env file."
        }
    }

    foreach ($name in @($environment.DB_NAME, 'espocrm', $DatabaseRootUser)) {
        if ($name -notmatch '^[A-Za-z0-9_]+$') { throw "Unsafe database identifier: $name" }
    }

    $wampMariaDb = Get-ChildItem 'C:\wamp64\bin\mariadb' -Filter 'mariadb.exe' -File -Recurse -ErrorAction SilentlyContinue |
        Sort-Object FullName -Descending |
        Select-Object -ExpandProperty FullName
    $installedMariaDb = Get-ChildItem 'C:\Program Files' -Directory -Filter 'MariaDB *' -ErrorAction SilentlyContinue |
        ForEach-Object { Join-Path $_.FullName 'bin\mariadb.exe' } |
        Where-Object { Test-Path -LiteralPath $_ -PathType Leaf } |
        Sort-Object -Descending
    $script:MariaDbClient = Resolve-Executable $ClientPath (
        @($wampMariaDb) + @($installedMariaDb)
    )
    $version = (& $script:MariaDbClient --version 2>&1 | Out-String).Trim()
    if ($LASTEXITCODE -ne 0) { throw "Unable to read the MariaDB client version: $version" }
    Assert-NexaMariaDbVersion $version 'MariaDB client' | Out-Null

    $rootPassword = $environment.DB_ROOT_PASSWORD
    $connection = Invoke-RootQuery 'SELECT 1;' $rootPassword
    if ($connection.ExitCode -ne 0) {
        Write-Host 'DB_ROOT_PASSWORD did not authenticate. Enter the local MariaDB root password.' -ForegroundColor Yellow
        $rootPassword = ConvertFrom-SecurePassword (
            Read-Host 'MariaDB root password (leave empty if none)' -AsSecureString
        )
        $connection = Invoke-RootQuery 'SELECT 1;' $rootPassword
    }
    if ($connection.ExitCode -ne 0) {
        throw 'Unable to authenticate to MariaDB as the configured root user.'
    }
    $serverVersion = Invoke-RootQuery 'SELECT VERSION();' $rootPassword
    if ($serverVersion.ExitCode -ne 0) { throw 'Unable to read the MariaDB server version.' }
    Assert-NexaMariaDbVersion (($serverVersion.Output | Out-String).Trim()) 'MariaDB server' | Out-Null

    $escapedPassword = $environment.DB_PASSWORD.Replace("'", "''")
    $database = $environment.DB_NAME
    $databaseSql = @"
CREATE DATABASE IF NOT EXISTS $database CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
"@
    $databaseResult = Invoke-RootQuery $databaseSql $rootPassword
    if ($databaseResult.ExitCode -ne 0) {
        throw "Database creation failed: $($databaseResult.Output -join ' ')"
    }

    $tableQuery = "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$database';"
    $tableResult = Invoke-RootQuery $tableQuery $rootPassword
    if ($tableResult.ExitCode -ne 0) { throw 'Could not inspect the local database.' }
    $tableCount = [int] ($tableResult.Output | Select-Object -Last 1)

    $provisionSql = @"
CREATE USER IF NOT EXISTS 'espocrm'@'localhost' IDENTIFIED BY '$escapedPassword';
CREATE USER IF NOT EXISTS 'espocrm'@'127.0.0.1' IDENTIFIED BY '$escapedPassword';
GRANT ALL PRIVILEGES ON $database.* TO 'espocrm'@'localhost';
GRANT ALL PRIVILEGES ON $database.* TO 'espocrm'@'127.0.0.1';
FLUSH PRIVILEGES;
"@
    if ($tableCount -eq 0) {
        $provisionSql += [Environment]::NewLine + @"
ALTER USER 'espocrm'@'localhost' IDENTIFIED BY '$escapedPassword';
ALTER USER 'espocrm'@'127.0.0.1' IDENTIFIED BY '$escapedPassword';
"@
    }
    $provision = Invoke-RootQuery $provisionSql $rootPassword
    if ($provision.ExitCode -ne 0) {
        throw "Database provisioning failed: $($provision.Output -join ' ')"
    }
    Write-Host "Database $database and its application user are ready." -ForegroundColor Green

    $schemaParameters = @{
        ClientPath = $script:MariaDbClient
        Database = $database
        DatabaseHost = $DatabaseHost
        Port = $DatabasePort
        User = 'espocrm'
        EnvironmentFile = $environmentPath
    }

    if ($tableCount -eq 0) {
        & (Join-Path $PSScriptRoot 'initialize-local-database.ps1') @schemaParameters
    } else {
        $trackingQuery = "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$database' AND table_name='nexa_schema_migration';"
        $tracking = Invoke-RootQuery $trackingQuery $rootPassword
        if ($tracking.ExitCode -ne 0 -or [int] ($tracking.Output | Select-Object -Last 1) -ne 1) {
            throw "Database $database is non-empty but is not managed by Nexa. Use an empty database or migrate it explicitly."
        }

        & (Join-Path $PSScriptRoot 'apply-shared-schema.ps1') -Mode Local @schemaParameters
    }
    if ($LASTEXITCODE -ne 0) { throw 'Database schema installation failed.' }

    & $PhpPath (Join-Path $PSScriptRoot 'install-native-application.php') "--env=$environmentPath"
    if ($LASTEXITCODE -ne 0) { throw 'Native application installation failed.' }

    $completionParameters = @{
        PhpPath = $PhpPath
        EnvironmentFile = $environmentPath
    }
    & (Join-Path $PSScriptRoot 'complete-local-setup.ps1') @completionParameters
    if ($LASTEXITCODE -ne 0) { throw 'Demo data and verification setup failed.' }

    if (-not $SkipHttpCheck) {
        try {
            $loginUrl = $SiteUrl.TrimEnd('/') + '/login'
            $login = Invoke-WebRequest -Uri $loginUrl -UseBasicParsing -MaximumRedirection 5 -TimeoutSec 30
            if ($login.StatusCode -ne 200) { throw "Login returned HTTP $($login.StatusCode)." }

            $installUrl = $SiteUrl.TrimEnd('/') + '/install/'
            $install = Invoke-WebRequest -Uri $installUrl -UseBasicParsing -MaximumRedirection 5 -TimeoutSec 30
            $finalPath = $install.BaseResponse.ResponseUri.AbsolutePath
            if ($finalPath -match '/install/?$') { throw 'The browser installer is still reachable.' }
        } catch {
            throw "Application files and database are ready, but HTTP verification failed. Check the Apache virtual host, hosts entry and mod_rewrite. $($_.Exception.Message)"
        }
    }

    Write-Host "Native Nexa setup is complete. Open $SiteUrl" -ForegroundColor Green
} finally {
    if ($null -eq $previousMysqlPassword) {
        Remove-Item Env:MYSQL_PWD -ErrorAction SilentlyContinue
    } else {
        $env:MYSQL_PWD = $previousMysqlPassword
    }
}
