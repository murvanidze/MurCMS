param([Parameter(Mandatory = $true)][string]$ReleaseDirectory, [Parameter(Mandatory = $true)][string]$Snapshot)

$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.IO.Compression.FileSystem
$checks = 0
$standard = @('snippets', 'blocks', 'gallery', 'tags', 'redirects', 'murconsent', 'murtext', 'berrymur') | Sort-Object
$version = (Get-Content -LiteralPath (Join-Path $Snapshot 'root/murcms/app/version.php') -Raw) -replace "(?s).*return '([^']+)'.*", '$1'
$stage = Join-Path $ReleaseDirectory ('overlay-check-' + [Guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Path $stage | Out-Null
$sentinels = @('data/config/site.php', 'data/config/seo.php', 'data/config/murtext.php', 'data/config/banerina.php', 'data/config/plugins.php', 'data/content/ru/pages/site-owned.mur.md', 'data/storage/site-owned.txt', 'data/templates/default/functions.php', 'uploads/site-owned.txt', 'uploads/murcms-custom/default/custom.css', 'uploads/.htaccess', '.htaccess')
foreach ($relative in $sentinels) {
    $target = Join-Path $stage "root/$relative"
    New-Item -ItemType Directory -Path (Split-Path $target -Parent) -Force | Out-Null
    [IO.File]::WriteAllText($target, "preserve:$relative")
}

try {
    foreach ($file in Get-ChildItem -LiteralPath $ReleaseDirectory -Filter '*.zip' -File) {
        $zip = [IO.Compression.ZipFile]::OpenRead($file.FullName)
        $manifest = [Collections.Generic.List[string]]::new()
        try {
            $names = @($zip.Entries | Where-Object { $_.Length -gt 0 -or -not $_.FullName.EndsWith('/') } | ForEach-Object { $_.FullName.Replace('\', '/') })
            foreach ($entry in $zip.Entries) {
                $name = $entry.FullName.Replace('\', '/')
                if ($name -match '(?:^|/)\.\.(?:/|$)' -or $name.StartsWith('/') -or $name.Contains(':')) { throw "Unsafe archive path: $name" }
                if ($entry.FullName.EndsWith('/')) { continue }
                $stream = $entry.Open()
                try { $hash = [Convert]::ToHexString([Security.Cryptography.SHA256]::HashData($stream)).ToLowerInvariant() } finally { $stream.Dispose() }
                $manifest.Add("$hash  $name")
                if ($name.StartsWith('root/')) {
                    $source = Join-Path $Snapshot $name
                    if (-not (Test-Path -LiteralPath $source -PathType Leaf) -or (Get-FileHash -LiteralPath $source -Algorithm SHA256).Hash.ToLowerInvariant() -ne $hash) { throw "Archive differs from source commit: $name" }
                }
                $checks++
            }
            if ($file.Name.StartsWith('MurCMS-')) {
                if ($names -match '(?:^|/)banerina(?:/|\.dist\.php)') { throw 'Banerina leaked into CMS package.' }
                $ids = @($names | Where-Object { $_ -match '^root/murcms/plugins/[^/]+/info\.json$' } | ForEach-Object { ($_ -split '/')[3] } | Sort-Object)
                if (($ids -join ',') -ne ($standard -join ',')) { throw "Wrong standard plugins: $($ids -join ',')" }
                foreach ($id in $ids) {
                    $reader = [IO.StreamReader]::new($zip.GetEntry("root/murcms/plugins/$id/info.json").Open())
                    try { $info = $reader.ReadToEnd() | ConvertFrom-Json; if (-not $info.name -or -not $info.version) { throw 'Incomplete info.json' } } finally { $reader.Dispose() }
                }
                if ($names -match '^root/data/config/(?![^/]+\.dist\.php$)' -or $names -match '^root/data/templates/' -or $names -match '^root/data/storage/(?![^/]+/\.gitkeep$)') { throw 'Runtime files in CMS archive.' }
            }
            if ($file.Name -eq "MurCMS-$version-root.zip") {
                if (@($names | Where-Object { -not $_.StartsWith('root/') }).Count -gt 0 -or $names -notcontains 'root/.htaccess') { throw 'Invalid new-install root package.' }
                $adminSource = [IO.File]::ReadAllText((Join-Path $Snapshot 'root/data/config/admin.dist.php'))
                if ($adminSource -notmatch "'users'\s*=>\s*\[\s*\]") { throw 'Shared administrator in distribution.' }
            }
            if ($file.Name.EndsWith('-update.zip')) {
                if ($names -match '^root/(?:uploads|data/(?:content|storage|templates|menus|snippets|blocks|galleries))(?:/|$)' -or $names -contains 'root/.htaccess' -or $names -match '^root/data/config/(?![^/]+\.dist\.php$)') { throw "Unsafe update archive: $($file.Name)" }
                if (-not $file.Name.StartsWith('MurCMS-')) {
                    $pluginId = if ($file.Name.StartsWith('MurText-')) { 'murtext' } else { 'banerina' }
                    if (@($names | Where-Object { $_.StartsWith('root/') -and $_ -notmatch "^root/(?:murcms/(?:plugins|assets/plugins)/$pluginId/|data/config/$pluginId\.dist\.php$)" }).Count -gt 0) { throw 'Unexpected plugin update file.' }
                }
            }
        } finally { $zip.Dispose() }
        [IO.File]::WriteAllText((Join-Path $ReleaseDirectory ($file.BaseName + '.manifest.sha256')), ((($manifest | Sort-Object) -join "`n") + "`n"), [Text.UTF8Encoding]::new($false))
        if ($file.Name.EndsWith('-update.zip')) { Expand-Archive -LiteralPath $file.FullName -DestinationPath $stage -Force }
    }
    foreach ($relative in $sentinels) {
        if ([IO.File]::ReadAllText((Join-Path $stage "root/$relative")) -ne "preserve:$relative") { throw "Update overwrote site-owned $relative" }
        $checks++
    }
    Write-Host "Package paths, source hashes, metadata and staged overlay preservation passed: $checks checks."
} finally {
    $resolvedStage = [IO.Path]::GetFullPath($stage)
    $allowedPrefix = [IO.Path]::GetFullPath($ReleaseDirectory).TrimEnd('\', '/') + [IO.Path]::DirectorySeparatorChar
    if (-not $resolvedStage.StartsWith($allowedPrefix, [StringComparison]::OrdinalIgnoreCase)) { throw 'Unsafe staging cleanup path.' }
    Remove-Item -LiteralPath $resolvedStage -Recurse -Force
}
