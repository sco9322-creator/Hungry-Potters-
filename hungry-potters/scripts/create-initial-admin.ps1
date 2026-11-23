[CmdletBinding()]
param(
    [switch]$Force,
    [string]$PhpPath = 'C:\xampp_jaka\php\php.exe',
    [string]$DatabaseHost = 'localhost',
    [int]$DatabasePort = 3306,
    [string]$DatabaseName = 'hungry_potters',
    [string]$DatabaseUser = 'hungry_potters_app',
    [string]$AdminUsername,
    [string]$AdminFullName
)

$ErrorActionPreference = 'Stop'
$phpScript = Join-Path $PSScriptRoot 'create-initial-admin.php'
if (-not (Test-Path -LiteralPath $PhpPath -PathType Leaf)) {
    throw "PHP executable was not found: $PhpPath"
}
if (-not (Test-Path -LiteralPath $phpScript -PathType Leaf)) {
    throw "Initial-admin script was not found: $phpScript"
}
if ($DatabasePort -lt 1 -or $DatabasePort -gt 65535) {
    throw 'DatabasePort must be between 1 and 65535.'
}
if ([string]::IsNullOrWhiteSpace($AdminUsername)) {
    $AdminUsername = Read-Host 'Initial admin username'
}
if ([string]::IsNullOrWhiteSpace($AdminFullName)) {
    $AdminFullName = Read-Host 'Initial admin full name'
}

function Convert-SecureStringToPlainText([Security.SecureString]$Value)
{
    $pointer = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($Value)
    try {
        return [Runtime.InteropServices.Marshal]::PtrToStringBSTR($pointer)
    } finally {
        [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($pointer)
    }
}

$dbPassword = Read-Host 'Database password' -AsSecureString
$adminPassword = Read-Host 'Initial temporary admin password' -AsSecureString
$confirmPassword = Read-Host 'Confirm initial temporary admin password' -AsSecureString

$dbPasswordPlainText = Convert-SecureStringToPlainText $dbPassword
$adminPasswordPlainText = Convert-SecureStringToPlainText $adminPassword
$confirmPasswordPlainText = Convert-SecureStringToPlainText $confirmPassword
if ($adminPasswordPlainText -cne $confirmPasswordPlainText) {
    throw 'The initial admin passwords did not match.'
}

$environmentNames = @(
    'HP_DB_HOST', 'HP_DB_PORT', 'HP_DB_NAME', 'HP_DB_USER', 'HP_DB_PASSWORD',
    'HP_INITIAL_ADMIN_USERNAME', 'HP_INITIAL_ADMIN_FULL_NAME', 'HP_INITIAL_ADMIN_PASSWORD'
)
$previousEnvironment = @{}
foreach ($name in $environmentNames) {
    $item = Get-Item ("Env:$name") -ErrorAction SilentlyContinue
    $previousEnvironment[$name] = if ($null -eq $item) { $null } else { [string]$item.Value }
}

try {
    $env:HP_DB_HOST = $DatabaseHost
    $env:HP_DB_PORT = [string]$DatabasePort
    $env:HP_DB_NAME = $DatabaseName
    $env:HP_DB_USER = $DatabaseUser
    $env:HP_DB_PASSWORD = $dbPasswordPlainText
    $env:HP_INITIAL_ADMIN_USERNAME = $AdminUsername
    $env:HP_INITIAL_ADMIN_FULL_NAME = $AdminFullName
    $env:HP_INITIAL_ADMIN_PASSWORD = $adminPasswordPlainText

    $arguments = @($phpScript)
    if ($Force) {
        $arguments += '--force'
    }
    & $PhpPath @arguments
    if ($LASTEXITCODE -ne 0) {
        throw 'Initial admin provisioning failed.'
    }
} finally {
    foreach ($name in $environmentNames) {
        if ($null -eq $previousEnvironment[$name]) {
            Remove-Item ("Env:$name") -ErrorAction SilentlyContinue
        } else {
            Set-Item ("Env:$name") -Value $previousEnvironment[$name]
        }
    }
    $dbPasswordPlainText = $null
    $adminPasswordPlainText = $null
    $confirmPasswordPlainText = $null
}