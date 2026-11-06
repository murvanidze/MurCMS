param([string]$Commit = 'HEAD')

$ErrorActionPreference = 'Stop'
$RepoRoot = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
. (Join-Path $PSScriptRoot 'packaging-common.ps1')
$dirty = @(& git -C $RepoRoot status --porcelain)
if ($LASTEXITCODE -ne 0 -or $dirty.Count -gt 0) { throw 'Commit all release changes before building from a clean checkout.' }
$commitHash = (& git -C $RepoRoot rev-parse --verify "$Commit^{commit}").Trim()
if ($LASTEXITCODE -ne 0 -or $commitHash -notmatch '^[0-9a-f]{40}$') { throw 'Invalid source commit.' }
$sourcePaths = Get-PackPaths -RepoRoot $RepoRoot -PackName ('release-source-' + $commitHash.Substring(0, 12))
Reset-PackDirectory -Paths $sourcePaths
& git -C $RepoRoot archive --format=zip "--output=$($sourcePaths.ZipPath)" $commitHash
if ($LASTEXITCODE -ne 0) { throw 'git archive failed.' }
Expand-Archive -LiteralPath $sourcePaths.ZipPath -DestinationPath $sourcePaths.PackDir -Force
$snapshot = $sourcePaths.PackDir
@(& git -C $RepoRoot -c core.quotepath=false ls-tree -r --name-only $commitHash) | Set-Content -LiteralPath (Join-Path $snapshot '.release-files.txt') -Encoding UTF8
if ($LASTEXITCODE -ne 0) { throw 'Cannot inventory the source commit.' }
$version = Get-MurCmsVersion -RepoRoot $snapshot
$outputPaths = Get-PackPaths -RepoRoot $RepoRoot -PackName "release-$version"
New-Item -ItemType Directory -Path $outputPaths.PackDir -Force | Out-Null

& (Join-Path $snapshot 'scripts/build-root-pack.ps1')
& (Join-Path $snapshot 'scripts/build-update-pack.ps1')
$packageNames = [Collections.Generic.List[string]]::new()
$packageNames.Add("MurCMS-$version-root")
$packageNames.Add("MurCMS-$version-update")

foreach ($definition in @(@('murtext', 'MurText'), @('banerina', 'Banerina'))) {
    $id, $name = $definition
    $info = Get-Content -LiteralPath (Join-Path $snapshot "root/murcms/plugins/$id/info.json") -Raw -Encoding UTF8 | ConvertFrom-Json
    $packName = "$name-$($info.version)-update"
    $paths = Get-PackPaths -RepoRoot $snapshot -PackName $packName
    Reset-PackDirectory -Paths $paths
    foreach ($file in Get-RepositoryFiles -RepoRoot $snapshot -Scope 'root' | Where-Object {
        $_.StartsWith("root/murcms/plugins/$id/") -or $_.StartsWith("root/murcms/assets/plugins/$id/") -or $_ -eq "root/data/config/$id.dist.php"
    }) { Copy-PackFile -RepoRoot $snapshot -PackDir $paths.PackDir -SourceRelativePath $file }
    "# $name $($info.version) update`n`nCopy the contents of root/ over the site's document root after backup and staging. Preserve runtime data/config/$id.php. Enable the plugin through plugin management if needed. See docs/releases/$version.md in docs.zip.`n" | Set-Content -LiteralPath (Join-Path $paths.PackDir 'UPDATE-INSTRUCTIONS.md') -Encoding UTF8
    New-PackZip -Paths $paths
    $packageNames.Add($packName)
}
$docsPaths = Get-PackPaths -RepoRoot $snapshot -PackName 'docs'
Reset-PackDirectory -Paths $docsPaths
foreach ($file in Get-RepositoryFiles -RepoRoot $snapshot -Scope 'docs') { Copy-PackFile -RepoRoot $snapshot -PackDir $docsPaths.PackDir -SourceRelativePath $file }
New-PackZip -Paths $docsPaths
$packageNames.Add('docs')

foreach ($name in $packageNames) {
    Copy-Item -LiteralPath (Join-Path $snapshot "_dist/$name.zip") -Destination (Join-Path $outputPaths.PackDir "$name.zip") -Force
}
"$commitHash`nMurCMS $version`n" | Set-Content -LiteralPath (Join-Path $outputPaths.PackDir 'RELEASE-COMMIT.txt') -Encoding UTF8
Copy-Item -LiteralPath (Join-Path $snapshot "docs/releases/$version.md") -Destination (Join-Path $outputPaths.PackDir 'RELEASE-NOTES.md') -Force

& (Join-Path $PSScriptRoot 'verify-packages.ps1') -ReleaseDirectory $outputPaths.PackDir -Snapshot $snapshot
if ($LASTEXITCODE -ne 0) { throw 'Package verification failed.' }
$sums = foreach ($file in Get-ChildItem -LiteralPath $outputPaths.PackDir -File | Where-Object { $_.Extension -eq '.zip' -or $_.Name.EndsWith('.manifest.sha256') -or $_.Name -in @('RELEASE-COMMIT.txt', 'RELEASE-NOTES.md') } | Sort-Object Name) {
    '{0}  {1}' -f (Get-FileHash -LiteralPath $file.FullName -Algorithm SHA256).Hash.ToLowerInvariant(), $file.Name
}
$sums | Set-Content -LiteralPath (Join-Path $outputPaths.PackDir 'SHA256SUMS.txt') -Encoding UTF8
Write-Host "Release artifacts: $($outputPaths.PackDir)"
Write-Host "Source commit: $commitHash"
