<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

$projectRoot = dirname(__DIR__);
$defaultTarget = $projectRoot . '/root/data/config/admin.php';
$target = $defaultTarget;

foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--target=')) {
        $candidate = substr($argument, strlen('--target='));
        if ($candidate === '') {
            fwrite(STDERR, "The --target path must not be empty.\n");
            exit(2);
        }
        $target = $candidate;
        continue;
    }

    fwrite(STDERR, "Unknown argument.\n");
    exit(2);
}

$payload = stream_get_contents(STDIN);
try {
    $input = json_decode($payload, true, 16, JSON_THROW_ON_ERROR);
} catch (JsonException) {
    fwrite(STDERR, "Could not read administrator setup input.\n");
    exit(2);
}

if (!is_array($input)) {
    fwrite(STDERR, "Administrator setup input must be an object.\n");
    exit(2);
}

$username = strtolower(trim((string) ($input['username'] ?? 'admin')));
$displayName = trim((string) ($input['display_name'] ?? 'Administrator'));
$email = strtolower(trim((string) ($input['email'] ?? 'admin@example.test')));
$language = trim((string) ($input['language'] ?? 'ru'));
$password = (string) ($input['password'] ?? '');
$confirmation = (string) ($input['password_confirm'] ?? '');

if (!preg_match('/^[a-z0-9][a-z0-9._-]{2,63}$/', $username)) {
    fwrite(STDERR, "Username must contain 3-64 lowercase Latin letters, digits, dots, hyphens, or underscores.\n");
    exit(2);
}
if ($displayName === '' || strlen($displayName) > 120) {
    fwrite(STDERR, "Display name must contain 1-120 bytes.\n");
    exit(2);
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
    fwrite(STDERR, "A valid administrator e-mail is required.\n");
    exit(2);
}
if (!in_array($language, ['ru', 'ro', 'en', 'uk'], true)) {
    fwrite(STDERR, "Administrator language must be ru, ro, en, or uk.\n");
    exit(2);
}
if ($password !== $confirmation) {
    fwrite(STDERR, "Password confirmation does not match.\n");
    exit(2);
}
if (
    strlen($password) < 14
    || strlen($password) > 200
    || !preg_match('/[a-z]/', $password)
    || !preg_match('/[A-Z]/', $password)
    || !preg_match('/[0-9]/', $password)
    || !preg_match('/[^A-Za-z0-9]/', $password)
) {
    fwrite(STDERR, "Password must contain 14-200 characters with lowercase, uppercase, digit, and special characters.\n");
    exit(2);
}

$defaultsPath = $projectRoot . '/root/data/config/admin.dist.php';
if (!is_file($defaultsPath)) {
    fwrite(STDERR, "Distribution admin config is missing.\n");
    exit(2);
}
if (is_file($target)) {
    fwrite(STDERR, "Refusing to overwrite an existing admin config.\n");
    exit(3);
}

if (!defined('MUR_CMS_BOOTSTRAPPED')) {
    define('MUR_CMS_BOOTSTRAPPED', true);
}
$config = require $defaultsPath;
if (!is_array($config)) {
    fwrite(STDERR, "Distribution admin config is invalid.\n");
    exit(2);
}

$passwordHash = password_hash($password, PASSWORD_DEFAULT);
$password = '';
$confirmation = '';
if (!is_string($passwordHash) || $passwordHash === '') {
    fwrite(STDERR, "Could not hash the administrator password.\n");
    exit(2);
}

$config['users'] = [[
    'id' => 'admin',
    'username' => $username,
    'email' => $email,
    'phone' => '',
    'display_name' => $displayName,
    'role' => 'super_admin',
    'admin_language' => $language,
    'active' => true,
    'password_hash' => $passwordHash,
]];

$targetDirectory = dirname($target);
if (!is_dir($targetDirectory)) {
    fwrite(STDERR, "Target config directory does not exist.\n");
    exit(2);
}

$contents = "<?php\ndeclare(strict_types=1);\n\n"
    . "if (!defined('MUR_CMS_BOOTSTRAPPED')) {\n"
    . "    http_response_code(403);\n"
    . "    exit('No direct script access allowed.');\n"
    . "}\n\nreturn " . var_export($config, true) . ";\n";
$temporaryPath = $target . '.tmp-' . bin2hex(random_bytes(6));

if (file_put_contents($temporaryPath, $contents, LOCK_EX) === false) {
    fwrite(STDERR, "Could not write the temporary admin config.\n");
    exit(2);
}
if (!rename($temporaryPath, $target)) {
    @unlink($temporaryPath);
    fwrite(STDERR, "Could not install the admin config.\n");
    exit(2);
}

fwrite(STDOUT, "Created a unique super-admin config at {$target}.\n");
