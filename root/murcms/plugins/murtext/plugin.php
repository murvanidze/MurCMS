<?php

declare(strict_types=1);

if (!defined('MUR_CMS_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('No direct script access allowed.');
}

use Mur\Core\PluginContext;

require_once __DIR__ . '/src/version.php';
require_once __DIR__ . '/src/config_store.php';
require_once __DIR__ . '/src/plugin_adapter.php';

return static function (PluginContext $context): void {
    $configStore = new \MurCMS\MurTextPlugin\ConfigStore($context, __DIR__);
    $runtime = new \MurCMS\MurTextPlugin\PluginAdapter($context, __DIR__, $configStore);
    $context->hooks->add('content.render_body', [$runtime, 'renderBody'], 10);

    $path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
    $isAdminRequest = PHP_SAPI === 'cli' || (is_string($path) && str_starts_with($path, '/admin'));

    if (!$isAdminRequest) {
        return;
    }

    require_once __DIR__ . '/src/admin_ui.php';
    $admin = new \MurCMS\MurTextPlugin\AdminUi($context, __DIR__, $configStore);
    $context->hooks->add('admin.document_fields', [$admin, 'adminDocumentFields']);
    $context->hooks->add('admin.document_meta', [$admin, 'saveDocumentMeta']);
    $context->hooks->add('admin.known_meta_keys', [$admin, 'knownMetaKeys']);
    $context->hooks->add('admin.editor_tools', [$admin, 'editorTools']);
    $context->hooks->add('admin.menu', [$admin, 'adminMenu']);
    $context->registerAdminRoute([
        'path' => '/admin/plugin/murtext',
        'role' => 'admin',
        'handler' => [$admin, 'adminRoute'],
    ]);
    $context->registerAdminRoute([
        'path' => '/admin/plugin/murtext/help',
        'role' => 'admin',
        'handler' => [$admin, 'fullHelp'],
    ]);
};
