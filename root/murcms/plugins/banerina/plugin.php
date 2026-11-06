<?php
declare(strict_types=1);

if (!defined('MUR_CMS_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('No direct script access allowed.');
}

use Mur\Core\PluginContext;
use MurCMS\BanerinaPlugin\ConfigStore;
use MurCMS\BanerinaPlugin\BanerinaPlugin;
use MurCMS\BanerinaPlugin\SnippetGateway;

require_once __DIR__ . '/src/ConfigStore.php';
require_once __DIR__ . '/src/SnippetGateway.php';
require_once __DIR__ . '/src/BanerinaPlugin.php';

return static function (PluginContext $context): void {
    $store = new ConfigStore($context, __DIR__);
    $snippets = new SnippetGateway($context);
    $banerina = new BanerinaPlugin($context, $store, $snippets);

    foreach (['content.home', 'content.document', 'layout.footer'] as $hook) {
        $context->hooks->add($hook, [$banerina, 'filter'], 20);
    }

    $path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
    $isAdminRequest = PHP_SAPI === 'cli' || (is_string($path) && str_starts_with($path, '/admin'));
    if (!$isAdminRequest) {
        return;
    }

    require_once __DIR__ . '/src/Translator.php';
    require_once __DIR__ . '/src/AdminUi.php';

    $admin = new \MurCMS\BanerinaPlugin\AdminUi(
        $context,
        $store,
        $snippets,
        $banerina,
        new \MurCMS\BanerinaPlugin\Translator(__DIR__ . '/languages'),
    );
    $context->hooks->add('admin.menu', [$admin, 'adminMenu']);
    $context->registerAdminRoute([
        'path' => '/admin/plugin/banerina',
        'role' => 'admin',
        'handler' => [$admin, 'adminRoute'],
    ]);
};
