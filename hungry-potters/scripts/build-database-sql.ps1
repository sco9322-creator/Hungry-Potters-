[CmdletBinding()]
param(
    [string]$ProjectPath = (Split-Path $PSScriptRoot -Parent)
)

$ErrorActionPreference = 'Stop'
$databasePath = Join-Path $ProjectPath 'database'
$schemaPath = Join-Path $databasePath 'schema.sql'
$demoDataPath = Join-Path $databasePath 'demo-data.sql'
$outputPath = Join-Path $databasePath 'database.sql'

foreach ($path in @($schemaPath, $demoDataPath)) {
    if (-not (Test-Path -LiteralPath $path -PathType Leaf)) {
        throw "Required SQL source file is missing: $path"
    }
}

$schema = [System.IO.File]::ReadAllText($schemaPath)
$demoData = [System.IO.File]::ReadAllText($demoDataPath)
[System.IO.File]::WriteAllText(
    $outputPath,
    $schema + $demoData,
    [System.Text.UTF8Encoding]::new($false)
)