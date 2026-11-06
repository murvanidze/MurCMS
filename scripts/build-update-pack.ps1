param(
    [string]$PackName,
    [switch]$NoZip
)

$ErrorActionPreference = 'Stop'

$RepoRoot = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
. (Join-Path $PSScriptRoot 'packaging-common.ps1')

$CmsVersion = Get-MurCmsVersion -RepoRoot $RepoRoot
if ([string]::IsNullOrWhiteSpace($PackName)) {
    $PackName = "MurCMS-$CmsVersion-update"
}

$Paths = Get-PackPaths -RepoRoot $RepoRoot -PackName $PackName
Reset-PackDirectory -Paths $Paths

$rootFiles = @(Get-RepositoryFiles -RepoRoot $RepoRoot -Scope 'root')
$overlayFiles = @(
    $rootFiles | Where-Object { (Test-StandardPackageFile $_) -and (
        $_ -eq 'root/index.php' -or
        $_.StartsWith('root/murcms/', [System.StringComparison]::Ordinal) -or
        ($_.StartsWith('root/data/config/', [System.StringComparison]::Ordinal) -and
            $_.EndsWith('.dist.php', [System.StringComparison]::OrdinalIgnoreCase))
    ) }
)

if ($overlayFiles.Count -eq 0) {
    throw 'No update-overlay files found for packaging.'
}

foreach ($file in $overlayFiles) {
    Copy-PackFile -RepoRoot $RepoRoot -PackDir $Paths.PackDir -SourceRelativePath $file
}

# The working root .htaccess can be site-specific and admin-editable. Ship the
# new canonical file for manual comparison without placing it in the overlay.
Copy-PackFile `
    -RepoRoot $RepoRoot `
    -PackDir $Paths.PackDir `
    -SourceRelativePath 'root/.htaccess' `
    -DestinationRelativePath '_manual-review/root/.htaccess'

Copy-PackFile -RepoRoot $RepoRoot -PackDir $Paths.PackDir -SourceRelativePath 'root/uploads/.htaccess' -DestinationRelativePath '_manual-review/root/uploads/.htaccess'

Copy-PackFile `
    -RepoRoot $RepoRoot `
    -PackDir $Paths.PackDir `
    -SourceRelativePath 'scripts/update-delete-paths.txt' `
    -DestinationRelativePath 'DELETE-PATHS.txt'

$instructions = @'
# MurCMS {{VERSION}} update pack

This package updates an existing MurCMS installation. It is not a new-site package.

## Before updating

1. Back up `data/`, `uploads/`, and the working root `.htaccess`.
2. Test the update on a staging copy first.
3. Review `DELETE-PATHS.txt` and `_manual-review/root/.htaccess`.

## Apply the overlay

Copy the contents of this package's `root/` directory over the existing MurCMS
document root: the directory that already contains `index.php`, `murcms/`, `data/`,
and `uploads/`. Do not create an extra `root/` level on the server.

The overlay replaces engine, admin, template, plugin code, and public plugin assets.
It also updates `data/config/*.dist.php` templates. Both `.htaccess` files are
provided under `_manual-review/` for manual comparison, outside the overlay.

## Preserved by design

The overlay does not contain or overwrite:

- working `data/config/*.php` files, including plugin settings;
- content, menus, snippets, blocks, galleries, storage, cache, logs, or backups;
- user files and site-specific CSS under `uploads/`;
- the working root `.htaccess`.
- site-owned `data/templates/*/functions.php` and the working uploads `.htaccess`.

Compare new `*.dist.php` keys with the site's working configs and merge only the
required settings. The canonical root `.htaccess` is supplied under `_manual-review/`;
merge relevant security/routing changes manually instead of replacing the live file.

## Removed code

ZIP extraction cannot delete obsolete files. After making a backup, remove only the
root-relative paths listed in `DELETE-PATHS.txt`. These entries are restricted to the
package-managed `murcms/` code tree.

## Verification

Confirm the Dashboard reports MurCMS {{VERSION}}, then check login/roles, enabled
plugins, public pages, assets, `/robots.txt`, `/sitemap.xml`, 404 handling, and private
path protection. Run the repository tests on a local/staging checkout when available.
'@.Replace('{{VERSION}}', $CmsVersion)
$instructions | Set-Content -LiteralPath (Join-Path $Paths.PackDir 'UPDATE-INSTRUCTIONS.md') -Encoding UTF8

$packagedVersion = Get-MurCmsVersion -RepoRoot $Paths.PackDir
if ($packagedVersion -ne $CmsVersion) {
    throw "Packaged MurCMS version mismatch: expected $CmsVersion, found $packagedVersion."
}

$overlayRoot = Join-Path $Paths.PackDir 'root'
$forbiddenOverlayPaths = @(
    'data/content',
    'data/menus',
    'data/snippets',
    'data/blocks',
    'data/galleries',
    'data/storage',
    'data/templates',
    'uploads',
    '.htaccess'
)
foreach ($relativePath in $forbiddenOverlayPaths) {
    if (Test-Path -LiteralPath (Join-Path $overlayRoot $relativePath)) {
        throw "Unsafe path found in update overlay: root/$relativePath"
    }
}

$workingConfigs = @(
    Get-ChildItem -LiteralPath (Join-Path $overlayRoot 'data/config') -File -Filter '*.php' |
        Where-Object { -not $_.Name.EndsWith('.dist.php', [System.StringComparison]::OrdinalIgnoreCase) }
)
if ($workingConfigs.Count -gt 0) {
    throw "Update overlay contains working config: root/data/config/$($workingConfigs[0].Name)"
}

$deleteEntries = @(
    Get-Content -LiteralPath (Join-Path $Paths.PackDir 'DELETE-PATHS.txt') -Encoding UTF8 |
        ForEach-Object { $_.Trim() } |
        Where-Object { $_ -and -not $_.StartsWith('#') }
)
foreach ($deleteEntry in $deleteEntries) {
    if (-not $deleteEntry.StartsWith('murcms/', [System.StringComparison]::Ordinal) -or
        $deleteEntry.Contains('..') -or
        [System.IO.Path]::IsPathRooted($deleteEntry)) {
        throw "Unsafe update deletion entry: $deleteEntry"
    }
}

if (-not $NoZip) {
    New-PackZip -Paths $Paths
}

Write-Host "MurCMS version: $CmsVersion"
Write-Host "Existing-site update pack: $($Paths.PackDir)"
Write-Host 'Apply only the contents of its root/ directory to the live document root.'
Write-Host 'Review DELETE-PATHS.txt and _manual-review/root/.htaccess separately.'
if (-not $NoZip) {
    Write-Host "Zip: $($Paths.ZipPath)"
}
