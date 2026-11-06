param()

$ErrorActionPreference = 'Stop'
$ProjectRoot = Resolve-Path (Join-Path $PSScriptRoot '..')

& (Join-Path $PSScriptRoot 'check-docs-drift.ps1')
if ($LASTEXITCODE -ne 0) {
    throw 'Documentation anti-drift checks failed.'
}

if (-not (Get-Command php -ErrorAction SilentlyContinue)) {
    throw 'PHP was not found in PATH.'
}

$phpFiles = Get-ChildItem -Path (Join-Path $ProjectRoot 'root'), (Join-Path $ProjectRoot 'tests') -Recurse -Filter *.php
foreach ($file in $phpFiles) {
    & php -l $file.FullName | Out-Null
    if ($LASTEXITCODE -ne 0) {
        throw "PHP syntax check failed: $($file.FullName)"
    }
}

if (Get-Command node -ErrorAction SilentlyContinue) {
    $jsFiles = Get-ChildItem -Path (Join-Path $ProjectRoot 'root') -Recurse -Include *.js,*.mjs
    foreach ($file in $jsFiles) {
        & node --check $file.FullName
        if ($LASTEXITCODE -ne 0) {
            throw "JavaScript syntax check failed: $($file.FullName)"
        }
    }

    & node (Join-Path $ProjectRoot 'tests/murconsent/js/runtime.test.js')
    if ($LASTEXITCODE -ne 0) {
        throw 'MurConsent JavaScript regression test failed.'
    }
    & node (Join-Path $ProjectRoot 'tests/admin-042.test.js')
    if ($LASTEXITCODE -ne 0) { throw 'Admin dates / BerryMur regression test failed.' }
} else {
    Write-Warning 'Node.js was not found; JavaScript syntax checks were skipped.'
}

$guardOutput = & php (Join-Path $ProjectRoot 'root/murcms/app/Core/Document.php')
if (($guardOutput -join "`n") -notmatch 'No direct script access allowed') {
    throw 'Direct execution guard did not stop a component file.'
}

& php (Join-Path $ProjectRoot 'tests/smoke.php')
if ($LASTEXITCODE -ne 0) {
    throw 'Smoke test failed.'
}

& php (Join-Path $ProjectRoot 'tests/murconsent/php/run.php')
if ($LASTEXITCODE -ne 0) {
    throw 'MurConsent PHP regression test failed.'
}

& php (Join-Path $ProjectRoot 'tests/release-042.php')
if ($LASTEXITCODE -ne 0) { throw 'Release 0.4.2 regression test failed.' }

Write-Host "Syntax and smoke checks passed for $($phpFiles.Count) PHP files."
