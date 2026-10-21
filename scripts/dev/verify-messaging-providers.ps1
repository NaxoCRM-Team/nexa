[CmdletBinding()]
param([string] $PhpPath = 'php', [switch] $Database, [switch] $Live, [string] $BaseUrl = 'http://localhost/nexa')
$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent (Split-Path -Parent $PSScriptRoot)
Push-Location $root
try {
    & $PhpPath tests/tenant/TwilioWebhookFormTest.php
    if ($LASTEXITCODE -ne 0) { throw 'Strict webhook form acceptance failed.' }
    & $PhpPath tests/tenant/MessagingProviderContractTest.php
    if ($LASTEXITCODE -ne 0) { throw 'Messaging provider contract acceptance failed.' }
    & $PhpPath tests/tenant/TwilioSenderLookupTest.php
    if ($LASTEXITCODE -ne 0) { throw 'SMS sender lookup acceptance failed.' }
    & $PhpPath tests/tenant/TwilioSmsTransportTest.php
    if ($LASTEXITCODE -ne 0) { throw 'SMS transport acceptance failed.' }
    & $PhpPath tests/tenant/WhatsAppProviderTest.php
    if ($LASTEXITCODE -ne 0) { throw 'WhatsApp sender/template/session policy acceptance failed.' }
    & $PhpPath tests/tenant/MessengerContractTest.php
    if ($LASTEXITCODE -ne 0) { throw 'Messenger provider contract acceptance failed.' }
    & $PhpPath tests/tenant/TwilioMediaFetcherTest.php
    if ($LASTEXITCODE -ne 0) { throw 'Incoming media fetch/content acceptance failed.' }
    if ($Database -or $Live) {
        & $PhpPath tests/tenant/TenantIncomingMediaTest.php
        if ($LASTEXITCODE -ne 0) { throw 'Incoming media tenant/storage acceptance failed.' }
        & $PhpPath tests/tenant/TenantConversationRoutingTest.php
        if ($LASTEXITCODE -ne 0) { throw 'Conversation routing acceptance failed.' }
        & $PhpPath tests/tenant/TenantMessagingCallbackTest.php
        if ($LASTEXITCODE -ne 0) { throw 'Messaging callback acceptance failed.' }
        & $PhpPath tests/tenant/TenantMessagingConnectionTest.php
        if ($LASTEXITCODE -ne 0) { throw 'Messaging connection acceptance failed.' }
        & $PhpPath tests/tenant/TenantSmsDispatchTest.php
        if ($LASTEXITCODE -ne 0) { throw 'SMS dispatch acceptance failed.' }
        & $PhpPath tests/tenant/TenantMessengerTest.php
        if ($LASTEXITCODE -ne 0) { throw 'Messenger tenant acceptance failed.' }
        & $PhpPath tests/tenant/TenantWhatsAppDispatchTest.php
        if ($LASTEXITCODE -ne 0) { throw 'WhatsApp two-way dispatch acceptance failed.' }
    }
    if ($Live) {
        if (([uri]$BaseUrl).Host -notin @('localhost','127.0.0.1')) { throw 'Use the local WAMP application.' }
        $names=@('NEXA_AUTOMATION_ACCEPTANCE','NEXA_LIVE_URL','NEXA_PHP_PATH');$previous=@{}
        foreach($name in $names){$previous[$name]=[Environment]::GetEnvironmentVariable($name,'Process')}
        try {
            $env:NEXA_AUTOMATION_ACCEPTANCE='1';$env:NEXA_LIVE_URL=$BaseUrl.TrimEnd('/');$env:NEXA_PHP_PATH=(Get-Command $PhpPath).Source
            & npx playwright test tests/browser/live-messaging-connections.spec.js tests/browser/live-whatsapp-settings.spec.js tests/browser/live-whatsapp-dispatch.spec.js tests/browser/live-messenger.spec.js tests/browser/live-conversation-routing.spec.js tests/browser/live-incoming-media.spec.js --workers=1
            if ($LASTEXITCODE -ne 0) { throw 'Messaging connection browser acceptance failed.' }
        } finally {foreach($name in $names){[Environment]::SetEnvironmentVariable($name,$previous[$name],'Process')}}
    }
    Write-Warning 'No live provider delivery is claimed. Real sending requires deployment approval, tenant activation, fresh sender proof and public HTTPS callbacks. Tests use fake transport.'
} finally { Pop-Location }
