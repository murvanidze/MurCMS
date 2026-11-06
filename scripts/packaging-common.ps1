function Get-MurCmsVersion {
    param(
        [Parameter(Mandatory = $true)][string]$RepoRoot
    )

    $versionPath = Join-Path $RepoRoot 'root/murcms/app/version.php'
    if (-not (Test-Path -LiteralPath $versionPath -PathType Leaf)) {
        throw "MurCMS version source is missing: $versionPath"
    }

    $versionSource = [System.IO.File]::ReadAllText($versionPath, [System.Text.Encoding]::UTF8)
    $versionMatch = [regex]::Match(
        $versionSource,
        "return\s+['`"](?<version>\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?(?:\+[0-9A-Za-z.-]+)?)['`"]\s*;"
    )
    if (-not $versionMatch.Success) {
        throw "Could not read the canonical MurCMS version from $versionPath"
    }

    return $versionMatch.Groups['version'].Value
}

function Get-PackPaths {
    param(
        [Parameter(Mandatory = $true)][string]$RepoRoot,
        [Parameter(Mandatory = $true)][string]$PackName
    )

    if ([string]::IsNullOrWhiteSpace($PackName) -or
        $PackName -in @('.', '..') -or
        $PackName -ne [System.IO.Path]::GetFileName($PackName) -or
        $PackName.IndexOfAny([System.IO.Path]::GetInvalidFileNameChars()) -ge 0) {
        throw "PackName must be a safe directory name, not a path: '$PackName'"
    }

    $distRoot = [System.IO.Path]::GetFullPath((Join-Path $RepoRoot '_dist'))
    $packDir = [System.IO.Path]::GetFullPath((Join-Path $distRoot $PackName))
    $zipPath = [System.IO.Path]::GetFullPath((Join-Path $distRoot ($PackName + '.zip')))
    $distPrefix = $distRoot.TrimEnd('\', '/') + [System.IO.Path]::DirectorySeparatorChar

    if (-not $packDir.StartsWith($distPrefix, [System.StringComparison]::OrdinalIgnoreCase) -or
        -not $zipPath.StartsWith($distPrefix, [System.StringComparison]::OrdinalIgnoreCase)) {
        throw "Refusing to create a package outside _dist: $PackName"
    }

    return [pscustomobject]@{
        DistRoot = $distRoot
        PackDir = $packDir
        ZipPath = $zipPath
    }
}

function Reset-PackDirectory {
    param(
        [Parameter(Mandatory = $true)]$Paths
    )

    New-Item -ItemType Directory -Path $Paths.DistRoot -Force | Out-Null

    if (Test-Path -LiteralPath $Paths.PackDir) {
        Remove-Item -LiteralPath $Paths.PackDir -Recurse -Force
    }
    if (Test-Path -LiteralPath $Paths.ZipPath -PathType Leaf) {
        Remove-Item -LiteralPath $Paths.ZipPath -Force
    }

    New-Item -ItemType Directory -Path $Paths.PackDir -Force | Out-Null
}

function Get-RepositoryFiles {
    param(
        [Parameter(Mandatory = $true)][string]$RepoRoot,
        [Parameter(Mandatory = $true)][string]$Scope
    )

    $snapshotManifest = Join-Path $RepoRoot '.release-files.txt'
    if (Test-Path -LiteralPath $snapshotManifest -PathType Leaf) {
        return @(Get-Content -LiteralPath $snapshotManifest -Encoding UTF8 | Where-Object {
            $_.StartsWith($Scope.TrimEnd('/') + '/') -and (Test-Path -LiteralPath (Join-Path $RepoRoot $_) -PathType Leaf)
        } | Sort-Object -Unique)
    }

    $tracked = @(& git -C $RepoRoot ls-files -- $Scope)
    if ($LASTEXITCODE -ne 0) {
        throw "Could not list tracked repository files under '$Scope'."
    }

    $untracked = @(& git -C $RepoRoot ls-files --others --exclude-standard -- $Scope)
    if ($LASTEXITCODE -ne 0) {
        throw "Could not list untracked repository files under '$Scope'."
    }

    return @($tracked + $untracked) |
        Where-Object {
            $_ -is [string] -and
            $_.StartsWith(($Scope.TrimEnd('/') + '/')) -and
            (Test-Path -LiteralPath (Join-Path $RepoRoot $_) -PathType Leaf)
        } |
        Sort-Object -Unique
}

function Copy-PackFile {
    param(
        [Parameter(Mandatory = $true)][string]$RepoRoot,
        [Parameter(Mandatory = $true)][string]$PackDir,
        [Parameter(Mandatory = $true)][string]$SourceRelativePath,
        [string]$DestinationRelativePath = $SourceRelativePath
    )

    $source = Join-Path $RepoRoot $SourceRelativePath
    if (-not (Test-Path -LiteralPath $source -PathType Leaf)) {
        throw "Package source file is missing: $SourceRelativePath"
    }

    $destination = Join-Path $PackDir $DestinationRelativePath
    $parent = Split-Path -Parent $destination
    if ($parent -and -not (Test-Path -LiteralPath $parent)) {
        New-Item -ItemType Directory -Path $parent -Force | Out-Null
    }

    Copy-Item -LiteralPath $source -Destination $destination -Force
}

function New-PackZip {
    param(
        [Parameter(Mandatory = $true)]$Paths
    )

    Add-Type -AssemblyName System.IO.Compression.FileSystem
    [System.IO.Compression.ZipFile]::CreateFromDirectory($Paths.PackDir, $Paths.ZipPath, [System.IO.Compression.CompressionLevel]::Optimal, $false)
}

function Test-StandardPackageFile {
    param([Parameter(Mandatory = $true)][string]$Path)
    return $Path -notmatch '^root/murcms/(?:plugins|assets/plugins)/banerina(?:/|$)' -and
        $Path -ne 'root/data/config/banerina.dist.php' -and
        $Path -notmatch '^root/data/templates/' -and
        $Path -notmatch '^root/data/config/(?![^/]+\.dist\.php$)' -and
        ($Path -notmatch '^root/data/storage/' -or $Path.EndsWith('/.gitkeep'))
}
