param(
    [string]$PackName,
    [switch]$NoZip
)

$ErrorActionPreference = 'Stop'

$RepoRoot = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
. (Join-Path $PSScriptRoot 'packaging-common.ps1')

$CmsVersion = Get-MurCmsVersion -RepoRoot $RepoRoot
if ([string]::IsNullOrWhiteSpace($PackName)) {
    $PackName = "murcms-$CmsVersion-root"
}

$Paths = Get-PackPaths -RepoRoot $RepoRoot -PackName $PackName
Reset-PackDirectory -Paths $Paths

$files = @(Get-RepositoryFiles -RepoRoot $RepoRoot -Scope 'root')
if ($files.Count -eq 0) {
    throw 'No root files found for packaging.'
}

foreach ($file in $files) {
    Copy-PackFile -RepoRoot $RepoRoot -PackDir $Paths.PackDir -SourceRelativePath $file
}

$packagedVersion = Get-MurCmsVersion -RepoRoot $Paths.PackDir
if ($packagedVersion -ne $CmsVersion) {
    throw "Packaged MurCMS version mismatch: expected $CmsVersion, found $packagedVersion."
}

$workingConfigs = @(
    Get-ChildItem -LiteralPath (Join-Path $Paths.PackDir 'root/data/config') -File -Filter '*.php' |
        Where-Object { -not $_.Name.EndsWith('.dist.php', [System.StringComparison]::OrdinalIgnoreCase) }
)
if ($workingConfigs.Count -gt 0) {
    throw "Root pack contains working config: $($workingConfigs[0].Name)"
}

if (-not (Test-Path -LiteralPath (Join-Path $Paths.PackDir 'root/.htaccess') -PathType Leaf)) {
    throw 'Root pack is missing root/.htaccess.'
}

if (-not $NoZip) {
    New-PackZip -Paths $Paths
}

Write-Host "MurCMS version: $CmsVersion"
Write-Host "New-install root pack: $($Paths.PackDir)"
if (-not $NoZip) {
    Write-Host "Zip: $($Paths.ZipPath)"
}
