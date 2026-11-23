[CmdletBinding()]
param(
    [string]$ProjectPath = (Split-Path $PSScriptRoot -Parent)
)

$ErrorActionPreference = 'Stop'
$databasePath = Join-Path $ProjectPath 'database'
$schemaPath = Join-Path $databasePath 'schema.sql'
$demoDataPath = Join-Path $databasePath 'demo-data.sql'
$databaseSqlPath = Join-Path $databasePath 'database.sql'

foreach ($path in @($schemaPath, $demoDataPath, $databaseSqlPath)) {
    if (-not (Test-Path -LiteralPath $path -PathType Leaf)) {
        throw "Required SQL file is missing: $path"
    }
}

function Normalize-LineEndings([string]$Value)
{
    return ($Value -replace "`r`n", "`n") -replace "`r", "`n"
}

$schema = [System.IO.File]::ReadAllText($schemaPath)
$demoData = [System.IO.File]::ReadAllText($demoDataPath)
if ([regex]::IsMatch($schema, '(?im)^\s*(?:INSERT|REPLACE)\s+')) {
    throw 'database/schema.sql must not contain INSERT or REPLACE statements. Move demo rows to database/demo-data.sql.'
}

$expected = Normalize-LineEndings ($schema + $demoData)
$actual = Normalize-LineEndings ([System.IO.File]::ReadAllText($databaseSqlPath))

if ($actual -ne $expected) {
    throw 'database/database.sql differs from schema.sql + demo-data.sql. Run scripts/build-database-sql.ps1.'
}

Write-Host 'PASS: database.sql matches schema.sql + demo-data.sql (line endings ignored).'