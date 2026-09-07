<?php
declare(strict_types=1);

if (!defined('MUR_CMS_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('No direct script access allowed.');
}

return [
    'name' => 'MurCMS site',
    'description' => '',
    'base_url' => 'https://example.com',
    'timezone' => 'Europe/Chisinau',
    'environment' => 'production',
    'home_redirect_status' => 302,
    'favicon_frontend' => '',
    'favicon_admin' => '',
];
