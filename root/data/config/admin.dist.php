<?php
declare(strict_types=1);

if (!defined('MUR_CMS_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('No direct script access allowed.');
}

return [
    'session_name' => 'mur_admin',
    'login_path' => '/admin/login',
    'after_login_path' => '/admin/',
    'ui' => [
        'default_language' => 'ru',
        'help_enabled' => true,
        'available_languages' => ['ru', 'ro', 'en', 'uk'],
    ],
    // Public distributions intentionally contain no usable administrator.
    // Create data/config/admin.php locally with scripts/create-admin-config.ps1.
    'users' => [],
    'legacy_password_hashes' => [
        '$2y$12$GXnZ3lthdZKxeeN7zY.mg.crw18S9czth.4V52jv5QxPuVBCh38tS',
    ],
];
