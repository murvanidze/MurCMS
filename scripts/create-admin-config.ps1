param(
    [string]$Username = 'admin',
    [string]$DisplayName = 'Administrator',
    [string]$Email = 'admin@example.test',
    [ValidateSet('ru', 'ro', 'en', 'uk')][string]$Language = 'ru'
)

$ErrorActionPreference = 'Stop'
$ProjectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$GeneratorPath = Join-Path $PSScriptRoot 'create-admin-config.php'

if (-not (Get-Command php -ErrorAction SilentlyContinue)) {
    throw 'PHP was not found in PATH.'
}

$passwordSecure = Read-Host 'New super-admin password (14+ characters)' -AsSecureString
$confirmationSecure = Read-Host 'Confirm the new super-admin password' -AsSecureString

function Convert-SecureStringToPlainText([Security.SecureString]$Value) {
    $pointer = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($Value)
    try {
        return [Runtime.InteropServices.Marshal]::PtrToStringBSTR($pointer)
    } finally {
        [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($pointer)
    }
}

$password = Convert-SecureStringToPlainText $passwordSecure
$confirmation = Convert-SecureStringToPlainText $confirmationSecure

try {
    $payload = @{
        username = $Username
        display_name = $DisplayName
        email = $Email
        language = $Language
        password = $password
        password_confirm = $confirmation
    } | ConvertTo-Json -Compress

    $phpCommand = (Get-Command php).Source
    $processInfo = [Diagnostics.ProcessStartInfo]::new()
    $processInfo.FileName = $phpCommand
    $processInfo.Arguments = '"' + $GeneratorPath.Replace('"', '\"') + '"'
    $processInfo.WorkingDirectory = $ProjectRoot
    $processInfo.UseShellExecute = $false
    $processInfo.RedirectStandardInput = $true
    $processInfo.RedirectStandardOutput = $true
    $processInfo.RedirectStandardError = $true
    $processInfo.CreateNoWindow = $true

    $process = [Diagnostics.Process]::new()
    $process.StartInfo = $processInfo
    if (-not $process.Start()) {
        throw 'Could not start the administrator config generator.'
    }
    $process.StandardInput.Write($payload)
    $process.StandardInput.Close()
    $stdout = $process.StandardOutput.ReadToEnd()
    $stderr = $process.StandardError.ReadToEnd()
    $process.WaitForExit()

    if ($process.ExitCode -ne 0) {
        throw $stderr.Trim()
    }

    Write-Host $stdout.Trim()
} finally {
    $payload = $null
    $password = $null
    $confirmation = $null
}
