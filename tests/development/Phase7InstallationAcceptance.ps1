[CmdletBinding()]
param(
    [string] $PhpPath = 'php',
    [string] $ClientPath = 'mariadb',
    [string] $DatabaseHost = '127.0.0.1',
    [int] $Port = 3306,
    [string] $User = 'root',
    [string] $Password = ''
)
$ErrorActionPreference='Stop'
$root=(Resolve-Path (Join-Path $PSScriptRoot '../..')).Path
$name='nexa_p7_install_'+[Guid]::NewGuid().ToString('N')
$temp=[IO.Path]::GetFullPath([IO.Path]::GetTempPath()).TrimEnd('\','/')
$stage=Join-Path $temp $name
$databaseUser='p7_'+[Guid]::NewGuid().ToString('N').Substring(0,20)
$databasePassword=[Guid]::NewGuid().ToString('N')+[Guid]::NewGuid().ToString('N')
$adminPassword='Install-A1!'+[Guid]::NewGuid().ToString('N')
$previous=$env:MYSQL_PWD;$created=$false;$stageCreated=$false;$hosts=New-Object System.Collections.Generic.List[string]
function Sql([string] $sql) {
    $args=@('--batch','--skip-column-names',"--host=$DatabaseHost","--port=$Port","--user=$User",'--ssl=FALSE')
    if(!$Password){$args+='--skip-password'}
    $result=@($sql|& $ClientPath @args)
    if($LASTEXITCODE -ne 0){throw 'Disposable installation SQL failed.'}
    return $result
}
function Run-Php([string] $script,[string[]] $arguments=@()) {
    & $PhpPath (Join-Path $stage $script) @arguments
    if($LASTEXITCODE -ne 0){throw "Fresh installation failed: $script"}
}
function Migrate {
    & (Join-Path $stage 'scripts/dev/apply-shared-schema.ps1') -Mode Local -ClientPath $ClientPath -Database $name -DatabaseHost $DatabaseHost -Port $Port -User $User -Password $Password -InitializeBaseSchema:$false
}
try {
    $PhpPath=(Get-Command $PhpPath -ErrorAction Stop).Source;$ClientPath=(Get-Command $ClientPath -ErrorAction Stop).Source
    $env:MYSQL_PWD=if($Password){$Password}else{$null}
    if($name -notmatch '^nexa_p7_install_[a-f0-9]{32}$'){throw 'Unsafe installation database name.'}
    New-Item -ItemType Directory -Path $stage -ErrorAction Stop|Out-Null;$stageCreated=$true
    Write-Host 'Copying source into a private temporary installation tree (excluding ignored runtime configuration).'
    Push-Location $root
    try {
        $files=@(& git -c core.quotepath=false ls-files --cached --others --exclude-standard -- espocrm database/shared scripts/dev/apply-shared-schema.ps1 scripts/dev/mariadb-version-policy.ps1 scripts/dev/install-native-application.php scripts/dev/verify-local-install.php tests/tenant/Phase7FreshInstallProbe.php)
        if($LASTEXITCODE -ne 0){throw 'Unable to enumerate installation source.'}
        $parents=New-Object 'System.Collections.Generic.HashSet[string]'
        foreach($file in $files){
            if([IO.Path]::IsPathRooted($file) -or $file -match '(^|/)\.\.(/|$)' -or $file -match '^espocrm/data/(?!\.data$|(?:cache|tmp|logs|upload)/\.gitkeep$)' -or $file -eq 'espocrm/install/config.php'){continue}
            $destination=Join-Path $stage $file;$parent=Split-Path -Parent $destination
            if($parents.Add($parent)){[IO.Directory]::CreateDirectory($parent)|Out-Null}
            [IO.File]::Copy((Join-Path $root $file),$destination,$true)
        }
    } finally {Pop-Location}
    Sql "CREATE DATABASE ``$name`` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"|Out-Null;$created=$true
    foreach($hostName in @('localhost','127.0.0.1')){
        Sql "CREATE USER '$databaseUser'@'$hostName' IDENTIFIED BY '$databasePassword';"|Out-Null;$hosts.Add($hostName)
        Sql "GRANT ALL PRIVILEGES ON ``$name``.* TO '$databaseUser'@'$hostName';"|Out-Null
    }
    $utf8=New-Object Text.UTF8Encoding($false)
    $environment="DB_HOST=$DatabaseHost`nDB_PORT=$Port`nDB_NAME=$name`nDB_USER=$databaseUser`nDB_PASSWORD=$databasePassword`nADMIN_USERNAME=phase7-install-admin`nADMIN_PASSWORD=$adminPassword`nESPOCRM_SITE_URL=http://127.0.0.1/$name`nCRM_NAME=Nexa Installation Acceptance`n"
    $environment += "ADMIN_EMAIL=phase7-install-admin@example.com`n"
    [IO.File]::WriteAllText((Join-Path $stage '.env'),$environment,$utf8)
    Write-Host '[INSTALL] Native base schema and actual migration runner'
    & (Join-Path $stage 'scripts/dev/apply-shared-schema.ps1') -Mode Local -ClientPath $ClientPath -Database $name -DatabaseHost $DatabaseHost -Port $Port -User $User -Password $Password -InitializeBaseSchema
    $ledger="SELECT CONCAT(migration_id,'|',checksum_sha256,'|',applied_at,'|',execution_ms,'|',applied_by) FROM ``$name``.nexa_schema_migration ORDER BY migration_id;"
    $before=@(Sql $ledger)
    $expected=@(Get-ChildItem (Join-Path $stage 'database/shared/migrations') -Filter '*.sql' -File).Count
    if($before.Count -ne $expected){throw 'Fresh migration ledger is incomplete.'}
    $skip=@(Migrate 6>&1)
    if(@($skip|Where-Object{[string]$_ -like '[[]APPLY[]]*'}).Count -ne 0 -or @($skip|Where-Object{[string]$_ -like '[[]SKIP[]]*'}).Count -ne $expected){throw 'Unchanged migration replay did not skip every applied migration.'}
    if(Compare-Object $before @(Sql $ledger)){throw 'Skipping migrations changed the ledger.'}
    $last=Get-ChildItem (Join-Path $stage 'database/shared/migrations') -Filter '*.sql' -File|Sort-Object Name|Select-Object -Last 1
    [IO.File]::AppendAllText($last.FullName,"`n-- Disposable checksum rejection probe.`n",$utf8)
    $rejected=$false
    try {Migrate|Out-Null} catch {$rejected=$_.Exception.Message -like 'Applied migration * has changed.*'}
    if(!$rejected){throw 'Changed applied migration was not rejected.'}
    if(Compare-Object $before @(Sql $ledger)){throw 'Checksum rejection changed the migration ledger.'}
    Copy-Item -LiteralPath (Join-Path $root ('database/shared/migrations/'+$last.Name)) -Destination $last.FullName
    Write-Host '[PASS] Applied migrations skipped unchanged; checksum mismatch rejected without rewriting the ledger.'
    Run-Php 'scripts/dev/install-native-application.php' @('--env=.env')
    Run-Php 'scripts/dev/verify-local-install.php' @('--before-demo')
    @{username='phase7-install-admin';password=$adminPassword}|ConvertTo-Json -Compress|& $PhpPath (Join-Path $stage 'tests/tenant/Phase7FreshInstallProbe.php')
    if($LASTEXITCODE -ne 0){throw 'Fresh native installation service probe failed.'}
    Run-Php 'scripts/dev/install-native-application.php' @('--env=.env')
    Write-Host 'Phase 7 fresh native installation and migration-runner checksum acceptance passed.' -ForegroundColor Green
} finally {
    try {
        if($created -and $name -match '^nexa_p7_install_[a-f0-9]{32}$'){Sql "DROP DATABASE ``$name``;"|Out-Null}
        foreach($hostName in $hosts){Sql "DROP USER '$databaseUser'@'$hostName';"|Out-Null}
    } finally {
        $env:MYSQL_PWD=$previous;$databasePassword=$null;$adminPassword=$null;$environment=$null
        if($stageCreated -and (Test-Path -LiteralPath $stage)){
            $resolved=(Resolve-Path -LiteralPath $stage).Path
            if(![string]::Equals($resolved,$stage,[StringComparison]::OrdinalIgnoreCase) -or (Split-Path -Parent $resolved) -ne $temp -or (Split-Path -Leaf $resolved) -ne $name){throw 'Refusing unsafe temporary installation cleanup.'}
            Remove-Item -LiteralPath $resolved -Recurse -Force
        }
    }
}
