param()

$ErrorActionPreference = 'Stop'
$ProjectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$DocsRoot = Join-Path $ProjectRoot 'docs'
$Drift = [System.Collections.Generic.List[string]]::new()

function Add-Drift([string] $Message) {
    $Drift.Add($Message)
}

function Read-Utf8([string] $Path) {
    return [System.IO.File]::ReadAllText($Path, [System.Text.Encoding]::UTF8)
}

function Repo-Relative([string] $Path) {
    $fullPath = [System.IO.Path]::GetFullPath($Path)
    if ($fullPath.StartsWith($ProjectRoot, [System.StringComparison]::OrdinalIgnoreCase)) {
        return $fullPath.Substring($ProjectRoot.Length).TrimStart('\', '/').Replace('\', '/')
    }

    return $fullPath
}

function Read-Version([string] $Path, [string] $Pattern, [string] $Label) {
    if (-not (Test-Path -LiteralPath $Path -PathType Leaf)) {
        Add-Drift "$Label canonical source is missing: $(Repo-Relative $Path)"
        return $null
    }

    $match = [regex]::Match((Read-Utf8 $Path), $Pattern)
    if (-not $match.Success) {
        Add-Drift "$Label version could not be read from $(Repo-Relative $Path)."
        return $null
    }

    return $match.Groups['version'].Value
}

function Require-Text([string] $Path, [string] $Needle, [string] $Label) {
    if (-not (Test-Path -LiteralPath $Path -PathType Leaf)) {
        Add-Drift "$Label is missing: $(Repo-Relative $Path)"
        return
    }

    if ((Read-Utf8 $Path).IndexOf($Needle, [System.StringComparison]::Ordinal) -lt 0) {
        Add-Drift "$Label does not mention canonical value '$Needle': $(Repo-Relative $Path)"
    }
}

function Require-Match([string] $Path, [string] $Pattern, [string] $Expectation) {
    if (-not (Test-Path -LiteralPath $Path -PathType Leaf)) {
        Add-Drift "$Expectation source is missing: $(Repo-Relative $Path)"
        return
    }

    if ((Read-Utf8 $Path) -notmatch $Pattern) {
        Add-Drift "$Expectation does not match canonical code: $(Repo-Relative $Path)"
    }
}

function Remove-FencedCode([string] $Text) {
    $result = [System.Text.StringBuilder]::new()
    $inFence = $false
    $fenceCharacter = ''
    $fenceLength = 0

    foreach ($line in [regex]::Split($Text, "\r?\n")) {
        if (-not $inFence) {
            $opening = [regex]::Match($line, '^\s*(?<fence>`{3,}|~{3,})')
            if ($opening.Success) {
                $inFence = $true
                $fenceCharacter = $opening.Groups['fence'].Value.Substring(0, 1)
                $fenceLength = $opening.Groups['fence'].Value.Length
                [void] $result.AppendLine()
                continue
            }

            [void] $result.AppendLine($line)
            continue
        }

        $closingPattern = '^\s*' + [regex]::Escape($fenceCharacter) + "{$fenceLength,}\s*$"
        if ($line -match $closingPattern) {
            $inFence = $false
        }
        [void] $result.AppendLine()
    }

    return $result.ToString()
}

function Normalize-LinkTarget([string] $RawTarget) {
    $target = $RawTarget.Trim()
    if ($target.StartsWith('<') -and $target.EndsWith('>')) {
        $target = $target.Substring(1, $target.Length - 2)
    } else {
        $targetWithTitle = [regex]::Match($target, '^(?<path>\S+)(?:\s+["''][^"'']*["''])?$')
        if ($targetWithTitle.Success) {
            $target = $targetWithTitle.Groups['path'].Value
        }
    }

    return $target
}

$cmsVersion = Read-Version `
    (Join-Path $ProjectRoot 'root/murcms/app/version.php') `
    "return\s+['`"](?<version>\d+\.\d+\.\d+)['`"]\s*;" `
    'MurCMS'

$murConsentVersion = Read-Version `
    (Join-Path $ProjectRoot 'root/murcms/plugins/murconsent/src/Plugin.php') `
    "public\s+const\s+VERSION\s*=\s*['`"](?<version>\d+\.\d+\.\d+)['`"]\s*;" `
    'MurConsent'

$murTextPluginVersion = Read-Version `
    (Join-Path $ProjectRoot 'root/murcms/plugins/murtext/src/version.php') `
    "public\s+const\s+PLUGIN\s*=\s*['`"](?<version>\d+\.\d+\.\d+)['`"]\s*;" `
    'MurText plugin'

$murTextCoreVersion = Read-Version `
    (Join-Path $ProjectRoot 'root/murcms/plugins/murtext/core/murtext.php') `
    "(?s)function\s+version\s*\([^)]*\).*?return\s+['`"](?<version>\d+\.\d+\.\d+)['`"]\s*;" `
    'MurText parser core'

# Canonical CMS and bundled-plugin versions in active documentation.
if ($null -ne $cmsVersion) {
    $escapedCmsVersion = [regex]::Escape($cmsVersion)
    Require-Match (Join-Path $DocsRoot 'README.md') `
        ('(?m)^Статус:\s+актуально для MurCMS\s+`{0}`\s*$' -f $escapedCmsVersion) `
        'Documentation-map current version'
    Require-Match (Join-Path $DocsRoot 'current-reference.md') `
        ('(?m)^Статус:\s+актуальный справочник стабильной версии\s+`{0}`\s*$' -f $escapedCmsVersion) `
        'Current-reference version'
    Require-Match (Join-Path $DocsRoot 'development/versioning-release.md') `
        ('(?m)^Статус:\s+канонический versioning reference для MurCMS\s+`{0}`\s*$' -f $escapedCmsVersion) `
        'Versioning-reference current version'

    $currentReleaseNote = Join-Path $DocsRoot "releases/$cmsVersion.md"
    Require-Match $currentReleaseNote ('(?m)^# MurCMS {0}\s*$' -f $escapedCmsVersion) 'Current release-note version'
}

if ($null -ne $murConsentVersion) {
    $escapedMurConsentVersion = [regex]::Escape($murConsentVersion)
    Require-Match (Join-Path $DocsRoot 'plugins/README.md') `
        ('(?m)^- .*MurConsent\s+`{0}`;\s*$' -f $escapedMurConsentVersion) `
        'Plugin-index MurConsent version'
    Require-Match (Join-Path $DocsRoot 'plugins/murconsent.md') `
        ('(?m)^# MurConsent {0} для MurCMS\s*$' -f $escapedMurConsentVersion) `
        'MurConsent documentation version'
}

if ($null -ne $murTextPluginVersion) {
    $escapedMurTextPluginVersion = [regex]::Escape($murTextPluginVersion)
    Require-Match (Join-Path $DocsRoot 'plugins/murtext/README_RU.md') `
        ('(?m)^Версия плагина:\s+\*\*{0}\*\*\s*$' -f $escapedMurTextPluginVersion) `
        'MurText integration documentation version'
    Require-Match (Join-Path $DocsRoot 'plugins/murtext/RELEASE_PACKAGE_RU.md') `
        ('(?m)^# MurText {0}: состав поставок\s*$' -f $escapedMurTextPluginVersion) `
        'MurText package documentation version'
}

if ($null -ne $murTextCoreVersion) {
    $escapedMurTextCoreVersion = [regex]::Escape($murTextCoreVersion)
    Require-Match (Join-Path $DocsRoot 'plugins/murtext/README_RU.md') `
        ('(?m)^Версия ядра:\s+\*\*{0}\*\*\s*$' -f $escapedMurTextCoreVersion) `
        'MurText parser-core documentation version'
    Require-Match (Join-Path $DocsRoot 'plugins/murtext/CORE_README_RU.md') `
        ('(?m)^# MurText {0} .*ядро парсера для MurCMS\s*$' -f $escapedMurTextCoreVersion) `
        'MurText core-reference version'
}

if ($null -ne $murTextPluginVersion -and $null -ne $murTextCoreVersion) {
    Require-Match (Join-Path $DocsRoot 'plugins/README.md') `
        ('(?m)^Каталог .* integration plugin `{0}` и parser core `{1}`:\s*$' -f $escapedMurTextPluginVersion, $escapedMurTextCoreVersion) `
        'Plugin-index MurText integration/core versions'
}

# Active documentation excludes the historical archive and release notes other than the current one.
$activeDocs = @()
if (Test-Path -LiteralPath $DocsRoot -PathType Container) {
    $activeDocs = @(
        Get-ChildItem -LiteralPath $DocsRoot -Recurse -File -Filter '*.md' |
            Where-Object {
                $relative = (Repo-Relative $_.FullName)
                -not $relative.StartsWith('docs/archive/', [System.StringComparison]::OrdinalIgnoreCase) -and
                (-not $relative.StartsWith('docs/releases/', [System.StringComparison]::OrdinalIgnoreCase) -or
                    ($null -ne $cmsVersion -and $relative -eq "docs/releases/$cmsVersion.md"))
            }
    )
} else {
    Add-Drift 'Active documentation directory is missing: docs/'
}

# Relative Markdown links outside fenced examples must resolve on disk.
$inlineLinkPattern = [regex]'!?\[[^\]\r\n]*\]\((?<target><[^>\r\n]+>|[^)\r\n]+)\)'
$referenceLinkPattern = [regex]'(?m)^\s*\[[^\]\r\n]+\]:\s*(?<target><[^>\r\n]+>|\S+)'
foreach ($document in $activeDocs) {
    $withoutFences = Remove-FencedCode (Read-Utf8 $document.FullName)
    $matches = @($inlineLinkPattern.Matches($withoutFences)) + @($referenceLinkPattern.Matches($withoutFences))
    foreach ($match in $matches) {
        $target = Normalize-LinkTarget $match.Groups['target'].Value
        if ([string]::IsNullOrWhiteSpace($target) -or
            $target.StartsWith('#') -or
            $target.StartsWith('/') -or
            $target.StartsWith('\') -or
            $target -match '^[a-z][a-z0-9+.-]*:') {
            continue
        }

        $separator = $target.IndexOfAny([char[]] '?#')
        if ($separator -ge 0) {
            $target = $target.Substring(0, $separator)
        }
        if ([string]::IsNullOrWhiteSpace($target)) {
            continue
        }

        try {
            $target = [System.Uri]::UnescapeDataString($target)
            $resolvedTarget = [System.IO.Path]::GetFullPath((Join-Path $document.DirectoryName $target))
        } catch {
            Add-Drift "Invalid relative Markdown link '$target' in $(Repo-Relative $document.FullName)."
            continue
        }

        if (-not (Test-Path -LiteralPath $resolvedTarget)) {
            Add-Drift "Broken relative Markdown link '$target' in $(Repo-Relative $document.FullName)."
        }
    }
}

# Stable role names and the documented legacy alias.
$userManagerPath = Join-Path $ProjectRoot 'root/murcms/app/Admin/AdminUserManager.php'
if (Test-Path -LiteralPath $userManagerPath -PathType Leaf) {
    $userManager = Read-Utf8 $userManagerPath
    $roleMatches = [regex]::Matches($userManager, "public\s+const\s+ROLE_[A-Z_]+\s*=\s*['`"](?<role>[^'`"]+)['`"]\s*;")
    $actualRoles = @($roleMatches | ForEach-Object { $_.Groups['role'].Value } | Sort-Object -Unique)
    $expectedRoles = @('admin', 'editor', 'super_admin')
    if (($actualRoles -join ',') -ne ($expectedRoles -join ',')) {
        Add-Drift "Admin role contract drifted: expected $($expectedRoles -join ', '); found $($actualRoles -join ', ')."
    }
    if ($userManager -notmatch "['`"]content['`"]\s*\?\s*self::ROLE_EDITOR") {
        Add-Drift "Legacy role alias 'content' no longer maps to 'editor' in AdminUserManager."
    }
} else {
    Add-Drift 'Admin role canonical source is missing: root/murcms/app/Admin/AdminUserManager.php'
}
foreach ($role in @('super_admin', 'admin', 'editor')) {
    Require-Text (Join-Path $DocsRoot 'admin/users.md') $role 'Admin users documentation'
}

# Safe snippet distribution default.
$snippetsDistPath = Join-Path $ProjectRoot 'root/data/config/snippets.dist.php'
if (-not (Test-Path -LiteralPath $snippetsDistPath -PathType Leaf)) {
    Add-Drift 'Snippet distribution config is missing: root/data/config/snippets.dist.php'
} elseif ((Read-Utf8 $snippetsDistPath) -notmatch "['`"]allow_php['`"]\s*=>\s*false\b") {
    Add-Drift 'Unsafe snippet distribution default: snippets.allow_php must be false.'
}

# Public packages must not ship a usable shared administrator.
$adminDistPath = Join-Path $ProjectRoot 'root/data/config/admin.dist.php'
if (-not (Test-Path -LiteralPath $adminDistPath -PathType Leaf)) {
    Add-Drift 'Admin distribution config is missing: root/data/config/admin.dist.php'
} else {
    $adminDistSource = Read-Utf8 $adminDistPath
    if ($adminDistSource -notmatch "['`"]users['`"]\s*=>\s*\[\s*\]") {
        Add-Drift 'Public admin distribution config contains a shared administrator.'
    }
    if ($adminDistSource -match "['`"]password_hash['`"]\s*=>") {
        Add-Drift 'Public admin distribution config contains a usable password hash.'
    }
}

# Mutable bundled-plugin configuration belongs in data/config, never in the plugin code tree.
foreach ($pluginId in @('redirects', 'murconsent', 'murtext')) {
    $distConfig = Join-Path $ProjectRoot "root/data/config/$pluginId.dist.php"
    if (-not (Test-Path -LiteralPath $distConfig -PathType Leaf)) {
        Add-Drift "Bundled plugin distribution config is missing: root/data/config/$pluginId.dist.php"
    }

    $pluginLocalConfig = Join-Path $ProjectRoot "root/murcms/plugins/$pluginId/config.php"
    if (Test-Path -LiteralPath $pluginLocalConfig -PathType Leaf) {
        Add-Drift "Mutable plugin config returned to the code tree: root/murcms/plugins/$pluginId/config.php"
    }
}

# MurText has one public asset pair and no plugin-local duplicate tree.
$murTextPublicAssets = Join-Path $ProjectRoot 'root/murcms/assets/plugins/murtext'
foreach ($assetName in @('admin.css', 'admin.js')) {
    if (-not (Test-Path -LiteralPath (Join-Path $murTextPublicAssets $assetName) -PathType Leaf)) {
        Add-Drift "Canonical MurText asset is missing: root/murcms/assets/plugins/murtext/$assetName"
    }
}
$murTextLocalAssets = Join-Path $ProjectRoot 'root/murcms/plugins/murtext/assets'
if (Test-Path -LiteralPath $murTextLocalAssets) {
    Add-Drift 'Duplicate MurText asset tree returned: root/murcms/plugins/murtext/assets/'
}

# Removed operational/audit/comparison/test-report/research artifacts must not re-enter active docs.
$obsoleteDocumentNames = @(
    'architecture-v1.md',
    'code-audit-2026-06-30.md',
    'murconsent-comparison-with-consenta.md',
    'murconsent-test-report.md',
    'parastas-sync-status.md',
    'sources-inventory.md',
    'technical-brief.md'
)
foreach ($document in $activeDocs) {
    if ($obsoleteDocumentNames -contains $document.Name) {
        Add-Drift "Obsolete artifact returned to active documentation: $(Repo-Relative $document.FullName)"
    }
}
$historicalBrief = Join-Path $DocsRoot 'archive/design/technical-brief.md'
if (-not (Test-Path -LiteralPath $historicalBrief -PathType Leaf)) {
    Add-Drift 'Historical technical brief is missing from docs/archive/design/technical-brief.md.'
}

# Current reference and checklists must describe portable requirements, not one workstation's result.
$machineStatePatterns = @(
    '(?i)\bextension\s+missing\b',
    '(?i)\bmissing\s+(?:php\s+)?extension\b',
    '(?i)\bsmoke(?:\s+test)?\s+failed\s+locally\b',
    '(?i)(?:расширение|extension).{0,40}(?:отсутствует|не установлено)',
    '(?i)(?:smoke|смоук).{0,40}(?:не прош[её]л|упал).{0,30}(?:локальн|на моей машине)'
)
foreach ($relativePath in @('docs/current-reference.md', 'docs/prerelease-checklist.md')) {
    $path = Join-Path $ProjectRoot $relativePath
    if (-not (Test-Path -LiteralPath $path -PathType Leaf)) {
        continue
    }
    $text = Remove-FencedCode (Read-Utf8 $path)
    foreach ($pattern in $machineStatePatterns) {
        if ($text -match $pattern) {
            Add-Drift "Machine-specific test state found in ${relativePath}: '$($Matches[0])'."
        }
    }
}

# Root-only packages contain root/, while repository-only tooling remains outside that payload.
$packagingCommonPath = Join-Path $ProjectRoot 'scripts/packaging-common.ps1'
if (-not (Test-Path -LiteralPath $packagingCommonPath -PathType Leaf)) {
    Add-Drift 'Shared packaging helper is missing: scripts/packaging-common.ps1'
} elseif ((Read-Utf8 $packagingCommonPath).IndexOf('root/murcms/app/version.php', [System.StringComparison]::Ordinal) -lt 0) {
    Add-Drift 'Packaging helper no longer reads the canonical root/murcms/app/version.php source.'
}

$rootPackScriptPath = Join-Path $ProjectRoot 'scripts/build-root-pack.ps1'
if (-not (Test-Path -LiteralPath $rootPackScriptPath -PathType Leaf)) {
    Add-Drift 'Root package builder is missing: scripts/build-root-pack.ps1'
} else {
    $rootPackScript = Read-Utf8 $rootPackScriptPath
    if ($rootPackScript -notmatch "Get-RepositoryFiles\s+.*-Scope\s+['`"]root['`"]") {
        Add-Drift 'Root package builder no longer has an explicit root/-only source boundary.'
    }
    if ($rootPackScript -notmatch 'Get-MurCmsVersion\s+-RepoRoot') {
        Add-Drift 'Root package builder no longer reads the canonical MurCMS version.'
    }
    if ($rootPackScript -match "murcms-\d+\.\d+\.\d+") {
        Add-Drift 'Root package builder contains a hard-coded MurCMS version.'
    }
}

$updatePackScriptPath = Join-Path $ProjectRoot 'scripts/build-update-pack.ps1'
if (-not (Test-Path -LiteralPath $updatePackScriptPath -PathType Leaf)) {
    Add-Drift 'Existing-site update package builder is missing: scripts/build-update-pack.ps1'
} else {
    $updatePackScript = Read-Utf8 $updatePackScriptPath
    if ($updatePackScript -notmatch 'Get-MurCmsVersion\s+-RepoRoot') {
        Add-Drift 'Update package builder no longer reads the canonical MurCMS version.'
    }
    if ($updatePackScript -match "murcms-\d+\.\d+\.\d+") {
        Add-Drift 'Update package builder contains a hard-coded MurCMS version.'
    }
}
$toolingBoundaryDocs = @(
    'docs/current-reference.md',
    'docs/installation-update.md',
    'docs/prerelease-checklist.md'
)
foreach ($relativePath in $toolingBoundaryDocs) {
    $path = Join-Path $ProjectRoot $relativePath
    if (-not (Test-Path -LiteralPath $path -PathType Leaf)) {
        continue
    }
    $text = Read-Utf8 $path
    $mentionsPackage = $text -match '(?i)\bpackage\b'
    $mentionsRepositoryTools = $text -match '(?i)(?:scripts/|scripts\\|tests/|tests\\)'
    $statesExclusion = $text -match '(?i)(?:не обязаны|не гарантиру(?:ется|ет)|не входит|outside|not (?:included|guaranteed))'
    if (-not ($mentionsPackage -and $mentionsRepositoryTools -and $statesExclusion)) {
        Add-Drift "Repository-tooling boundary is not stated clearly in $relativePath."
    }
}

if ($Drift.Count -gt 0) {
    Write-Host "Documentation drift detected ($($Drift.Count) issue(s)):" -ForegroundColor Red
    foreach ($issue in $Drift) {
        Write-Host " - $issue" -ForegroundColor Red
    }
    exit 1
}

Write-Host "Documentation anti-drift checks passed for $($activeDocs.Count) active Markdown files."
exit 0
