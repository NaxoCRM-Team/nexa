[CmdletBinding()]
param(
    [string] $PhpPath = 'php',
    [string] $BaseUrl = 'http://localhost/nexa',
    [ValidateSet('desktop', 'tablet', 'tablet-landscape', 'mobile', 'mobile-landscape')]
    [string] $Project = 'desktop'
)
$ErrorActionPreference = 'Stop'
if (([uri] $BaseUrl).Host -notin @('localhost', '127.0.0.1')) { throw 'Only local WAMP acceptance is allowed.' }
$root = Split-Path -Parent (Split-Path -Parent $PSScriptRoot)
$variables = @('NEXA_AUTOMATION_ACCEPTANCE', 'NEXA_MAILBOX_ACCEPTANCE', 'NEXA_LIVE_URL', 'NEXA_PHP_PATH')
$previous = @{}
foreach ($name in $variables) { $previous[$name] = [Environment]::GetEnvironmentVariable($name, 'Process') }
Push-Location $root
try {
    & docker compose -f compose.mailbox-test.yaml down
    if ($LASTEXITCODE -ne 0) { throw 'The disposable mailbox sandbox could not be reset.' }
    $probe = [System.Net.Sockets.TcpClient]::new()
    $occupied = $false
    try { $occupied = $probe.ConnectAsync('127.0.0.1', 13026).Wait(500) -and $probe.Connected } catch { } finally { $probe.Dispose() }
    if ($occupied) { throw 'Loopback port 13026 must be unused for the failed-send acceptance check.' }
    & docker compose -f compose.mailbox-test.yaml up -d
    if ($LASTEXITCODE -ne 0) { throw 'Local mailbox sandbox did not start.' }
    $ready = $false
    for ($attempt = 0; $attempt -lt 30; $attempt++) {
        $client = [System.Net.Sockets.TcpClient]::new()
        try {
            $connection = $client.ConnectAsync('127.0.0.1', 13143)
            if ($connection.Wait(1000) -and $client.Connected) { $ready = $true; break }
        } catch { } finally { $client.Dispose() }
        Start-Sleep -Seconds 1
    }
    if (-not $ready) { throw 'Local IMAP sandbox is not ready.' }
    $env:NEXA_AUTOMATION_ACCEPTANCE = '1'
    $env:NEXA_MAILBOX_ACCEPTANCE = '1'
    $env:NEXA_LIVE_URL = $BaseUrl.TrimEnd('/')
    $env:NEXA_PHP_PATH = (Get-Command $PhpPath).Source
    & npx playwright test tests/browser/live-conversation-mailbox.spec.js "--project=$Project" --workers=1
    if ($LASTEXITCODE -ne 0) { throw 'Native mailbox browser acceptance failed.' }
} finally {
    & docker compose -f compose.mailbox-test.yaml down
    foreach ($name in $variables) { [Environment]::SetEnvironmentVariable($name, $previous[$name], 'Process') }
    Pop-Location
}
