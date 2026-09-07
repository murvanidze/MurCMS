<?php

declare(strict_types=1);

if (!defined('MUR_CMS_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('No direct script access allowed.');
}

use Mur\Core\PluginContext;
use MurCMS\MurConsent\Plugin;
use MurCMS\MurConsent\AdminUi;

require_once __DIR__ . '/src/LanguageResolver.php';
require_once __DIR__ . '/src/Config.php';
require_once __DIR__ . '/src/Services.php';
require_once __DIR__ . '/src/FrontendRenderer.php';
require_once __DIR__ . '/src/Plugin.php';

return static function (PluginContext $context): void {
    $configPath = $context->path('config/murconsent.php');
    $legacyPath = $context->path('config/privacy-consent.php');
    $defaultsPath = __DIR__ . '/defaults.php';

    $plugin = new Plugin($context, __DIR__, $configPath, $defaultsPath, $legacyPath);
    $plugin->registerFrontend();

    $path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
    $isAdmin = PHP_SAPI === 'cli' || (is_string($path) && str_starts_with($path, '/admin'));
    if (!$isAdmin) return;

    require_once __DIR__ . '/src/Diagnostics.php';
    require_once __DIR__ . '/src/AdminUi.php';
    $admin = new AdminUi($context, __DIR__, $configPath, $defaultsPath, $legacyPath);
    $context->hooks->add('admin.menu', [$admin, 'adminMenu']);
    $context->registerAdminRoute([
        'path' => '/admin/plugin/murconsent',
        'role' => 'admin',
        'handler' => [$admin, 'adminRoute'],
    ]);
};
