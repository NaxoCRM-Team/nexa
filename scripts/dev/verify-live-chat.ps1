[CmdletBinding()]
param([string] $PhpPath = 'php', [switch] $Live, [string] $BaseUrl = 'http://localhost/nexa')
$ErrorActionPreference = 'Stop'
if ($Live -and ([uri] $BaseUrl).Host -notin @('localhost', '127.0.0.1')) { throw 'Use the local WAMP application.' }
$root = Split-Path -Parent (Split-Path -Parent $PSScriptRoot)
$names = @('NEXA_AUTOMATION_ACCEPTANCE','NEXA_LIVE_URL','NEXA_PHP_PATH')
$previous = @{}
foreach ($name in $names) { $previous[$name] = [Environment]::GetEnvironmentVariable($name,'Process') }
Push-Location $root
try {
    & $PhpPath tests/tenant/ChatBotDefinitionTest.php
    if ($LASTEXITCODE -ne 0) { throw 'Chat bot definition acceptance failed.' }
    & $PhpPath tests/tenant/TenantLiveChatTest.php
    if ($LASTEXITCODE -ne 0) { throw 'Native Live Chat acceptance failed.' }
    & $PhpPath tests/tenant/TenantChatPrivacyTest.php
    if ($LASTEXITCODE -ne 0) { throw 'Chat privacy lifecycle acceptance failed.' }
    if ($Live) {
        $env:NEXA_AUTOMATION_ACCEPTANCE = '1'
        $env:NEXA_LIVE_URL = $BaseUrl.TrimEnd('/')
        $env:NEXA_PHP_PATH = (Get-Command $PhpPath).Source
        & npx playwright test tests/browser/live-chat.spec.js tests/browser/live-chat-privacy.spec.js --workers=1
        if ($LASTEXITCODE -ne 0) { throw 'Authenticated Live Chat browser acceptance failed.' }
    }
} finally {
    foreach ($name in $names) { [Environment]::SetEnvironmentVariable($name,$previous[$name],'Process') }
    Pop-Location
}
