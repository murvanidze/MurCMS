<?php
declare(strict_types=1);

if (!defined('MUR_CMS_ENTRY')) {
    http_response_code(403);
    exit('No direct script access allowed.');
}

define('MUR_PUBLIC_PATH', dirname(__DIR__, 2));
define('MUR_ENGINE_PATH', dirname(__DIR__));
define('MUR_DATA_PATH', MUR_PUBLIC_PATH . '/data');
define('MUR_UPLOADS_PATH', MUR_PUBLIC_PATH . '/uploads');
define('MUR_BASE_PATH', MUR_PUBLIC_PATH);
define('MUR_CMS_BOOTSTRAPPED', true);

$murCmsVersion = require MUR_ENGINE_PATH . '/app/version.php';
define('MUR_CMS_VERSION', $murCmsVersion);
unset($murCmsVersion);

require MUR_ENGINE_PATH . '/app/Support/helpers.php';
require MUR_ENGINE_PATH . '/app/Core/Hooks.php';
require MUR_ENGINE_PATH . '/app/Core/Document.php';
require MUR_ENGINE_PATH . '/app/Core/DocumentParser.php';
require MUR_ENGINE_PATH . '/app/Core/Slugger.php';
require MUR_ENGINE_PATH . '/app/Core/ContentRepository.php';
require MUR_ENGINE_PATH . '/app/Core/SeoManager.php';
require MUR_ENGINE_PATH . '/app/Core/Menu.php';
require MUR_ENGINE_PATH . '/app/Core/PluginContext.php';
require MUR_ENGINE_PATH . '/app/Core/PluginManager.php';
require MUR_ENGINE_PATH . '/app/Core/FrontendTemplate.php';
require MUR_ENGINE_PATH . '/app/Core/TemplateContext.php';
require MUR_ENGINE_PATH . '/app/Core/TemplateManager.php';
require MUR_ENGINE_PATH . '/app/Core/Renderer.php';
require MUR_ENGINE_PATH . '/app/Core/Router.php';
require MUR_ENGINE_PATH . '/app/Admin/AdminUserManager.php';
require MUR_ENGINE_PATH . '/app/Admin/AdminAuth.php';
require MUR_ENGINE_PATH . '/app/Admin/FrontendAdminBar.php';
require MUR_ENGINE_PATH . '/app/Admin/AdminI18n.php';
require MUR_ENGINE_PATH . '/app/Admin/AdminRenderer.php';
require MUR_ENGINE_PATH . '/app/Admin/AdminMediaManager.php';
require MUR_ENGINE_PATH . '/app/Admin/AdminFileManager.php';
require MUR_ENGINE_PATH . '/app/Admin/AdminRouter.php';

$site = require MUR_DATA_PATH . '/config/site.php';
$languages = require MUR_DATA_PATH . '/config/languages.php';
$plugins = require MUR_DATA_PATH . '/config/plugins.php';
$admin = require MUR_DATA_PATH . '/config/admin.php';
$seo = require MUR_DATA_PATH . '/config/seo.php';
$theme = is_file(MUR_DATA_PATH . '/config/theme.php')
    ? require MUR_DATA_PATH . '/config/theme.php'
    : ['active' => 'default', 'templates' => ['default' => ['options' => []]]];

date_default_timezone_set($site['timezone'] ?? 'UTC');

return [
    'site' => $site,
    'languages' => $languages,
    'plugins' => $plugins,
    'admin' => $admin,
    'seo' => $seo,
    'theme' => $theme,
];
