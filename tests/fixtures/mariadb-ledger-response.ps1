# Deliberately no database connection: exercise fail-closed native-client output.
$global:LASTEXITCODE = 0
if ($args -contains '--version') { 'mariadb from 11.4.9-MariaDB'; return }
$sql = $input -join "`n"
if ($sql -match 'SELECT VERSION\(\)') { '11.4.9-MariaDB'; return }
if ($sql -match 'information_schema.tables') {
    if ($env:NEXA_MIGRATION_RESPONSE_TEST -eq 'empty-count') { return }
    '1'; return
}
if ($sql -match 'SELECT COALESCE') {
    if ($env:NEXA_MIGRATION_RESPONSE_TEST -eq 'malformed-checksum') { 'invalid'; return }
    return
}
throw 'Unexpected migration SQL reached the fake client.'
