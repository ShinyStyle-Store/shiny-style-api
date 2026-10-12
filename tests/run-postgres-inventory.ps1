param(
    [Parameter(Mandatory = $true)]
    [string] $Username,

    [System.Security.SecureString] $Password
)

$ErrorActionPreference = 'Stop'
$repositoryRoot = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$reportPath = Join-Path ([System.IO.Path]::GetTempPath()) ("shiny-style-pgtest-$([guid]::NewGuid().ToString('N')).json")
$environmentNames = @(
    'APP_ENV', 'DB_CONNECTION', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME',
    'DB_PASSWORD', 'DB_URL', 'SHINY_STYLE_PG_TESTING', 'SHINY_STYLE_PG_TEST_DATABASE',
    'SHINY_STYLE_PG_TEST_REPORT_PATH'
)
$previousEnvironment = @{}
foreach ($name in $environmentNames) {
    $previousEnvironment[$name] = [Environment]::GetEnvironmentVariable($name, 'Process')
}
$phpCommand = Get-Command php -ErrorAction SilentlyContinue
if ($null -eq $phpCommand) {
    throw 'PHP CLI is required. Install the project-supported PHP version and add php.exe to PATH.'
}

$phpModules = & $phpCommand.Source -m
if ($LASTEXITCODE -ne 0 -or $phpModules -notcontains 'pdo_pgsql') {
    throw 'The PHP CLI must have the pdo_pgsql extension enabled.'
}

if ($null -eq $Password) {
    $Password = Read-Host 'Password for the local PostgreSQL test role' -AsSecureString
}

$passwordPointer = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($Password)
try {
    $plainPassword = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($passwordPointer)
    $env:APP_ENV = 'testing'
    $env:DB_CONNECTION = 'pgsql'
    $env:DB_HOST = '127.0.0.1'
    $env:DB_PORT = '5432'
    $env:DB_DATABASE = 'shiny_style_pg_test'
    $env:DB_USERNAME = $Username
    $env:DB_PASSWORD = $plainPassword
    $env:DB_URL = ''
    $env:SHINY_STYLE_PG_TESTING = '1'
    $env:SHINY_STYLE_PG_TEST_DATABASE = 'shiny_style_pg_test'
    $env:SHINY_STYLE_PG_TEST_REPORT_PATH = $reportPath

    Push-Location $repositoryRoot
    try {
        $testFiles = @(
            'tests/Feature/Database/CatalogIntegrityTest.php',
            'tests/Feature/Database/PostgreSqlInventorySchemaTest.php',
            'tests/Feature/Database/PostgreSqlInventoryUpgradeTest.php',
            'tests/Feature/Database/PostgreSqlInventoryConcurrencyTest.php',
            'tests/Feature/Api/AdminProductConversionApiTest.php',
            'tests/Feature/Api/AdminPhysicalReturnTest.php',
            'tests/Feature/Api/AdminReturnInventoryTransferTest.php'
        )
        $postgresInventoryFiles = @(
            'tests/Feature/Database/PostgreSqlInventorySchemaTest.php',
            'tests/Feature/Database/PostgreSqlInventoryUpgradeTest.php',
            'tests/Feature/Database/PostgreSqlInventoryConcurrencyTest.php'
        )

        foreach ($testFile in $testFiles) {
            $phpunitArguments = @('vendor/bin/phpunit', '--configuration=phpunit.pgsql.xml')
            if ($postgresInventoryFiles -contains $testFile) {
                $phpunitArguments += '--group=postgres-inventory'
            }
            $phpunitArguments += $testFile

            & $phpCommand.Source @phpunitArguments
            if ($LASTEXITCODE -ne 0) {
                throw "PostgreSQL test run failed for $testFile (exit $LASTEXITCODE)."
            }
        }

        if (Test-Path -LiteralPath $reportPath) {
            $postgresReport = Get-Content -Raw -LiteralPath $reportPath | ConvertFrom-Json
            Write-Output "Verified database: $($postgresReport.database)"
            Write-Output "PostgreSQL server version: $($postgresReport.server_version)"
        }
    } finally {
        Pop-Location
    }
} finally {
    if ($null -ne $passwordPointer -and $passwordPointer -ne [IntPtr]::Zero) {
        [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($passwordPointer)
    }
    foreach ($name in $environmentNames) {
        [Environment]::SetEnvironmentVariable($name, $previousEnvironment[$name], 'Process')
    }
    Remove-Item -LiteralPath $reportPath -Force -ErrorAction SilentlyContinue
    Remove-Variable plainPassword -ErrorAction SilentlyContinue
}
