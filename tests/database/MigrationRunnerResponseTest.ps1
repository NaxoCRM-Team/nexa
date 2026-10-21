$ErrorActionPreference = 'Stop'
$root = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$previous = $env:NEXA_MIGRATION_RESPONSE_TEST
try {
    foreach ($mode in @('empty-count','empty-checksum','malformed-checksum')) {
        $env:NEXA_MIGRATION_RESPONSE_TEST = $mode
        $rejected = $false
        try {
            & (Join-Path $root 'scripts\dev\apply-shared-schema.ps1') -Mode Local `
                -Database nexa_response_contract -User synthetic -Password '' `
                -ClientPath (Join-Path $root 'tests\fixtures\mariadb-ledger-response.ps1')
        } catch {
            $rejected = $_.Exception.Message -like '*could not be*No migration was applied*'
            if (-not $rejected) { throw }
        }
        if (-not $rejected) { throw "Migration runner accepted $mode." }
    }
    Write-Host 'Migration ledger response guards passed: 3 (fake client; no database writes).'
} finally {
    $env:NEXA_MIGRATION_RESPONSE_TEST = $previous
}
