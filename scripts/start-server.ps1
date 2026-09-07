param(
    [string] $BindHost = '127.0.0.1',
    [int] $Port = 8000
)

$ErrorActionPreference = 'Stop'

$ProjectRoot = Resolve-Path (Join-Path $PSScriptRoot '..')
$CmsRoot = Join-Path $ProjectRoot 'root'
$Router = Join-Path $CmsRoot 'index.php'

if (-not (Get-Command php -ErrorAction SilentlyContinue)) {
    Write-Error 'PHP was not found in PATH. Install PHP or add php.exe to PATH, then run this script again.'
}

if (-not (Test-Path $Router)) {
    Write-Error "CMS entry point was not found: $Router"
}

$Url = "http://${BindHost}:$Port"

Write-Host "Starting MurCMS development server"
Write-Host "URL: $Url"
Write-Host "Document root: $CmsRoot"
Write-Host "Press Ctrl+C to stop."
Write-Host ''

& php -S "${BindHost}:$Port" -t $CmsRoot $Router
