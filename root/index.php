<?php
declare(strict_types=1);

define('MUR_CMS_ENTRY', true);

if (PHP_SAPI === 'cli-server') {
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    $file = realpath(__DIR__ . '/' . ltrim((string) $path, '/'));
    $staticPaths = array_filter([
        realpath(__DIR__ . '/murcms/assets'),
        realpath(__DIR__ . '/uploads'),
    ]);
    $templatesPath = realpath(__DIR__ . '/murcms/templates');

    if ($file !== false && is_file($file)) {
        foreach ($staticPaths as $staticPath) {
            if (str_starts_with($file, $staticPath . DIRECTORY_SEPARATOR)) {
                mur_serve_dev_asset($file);
                return true;
            }
        }

        $normalizedPath = str_replace('\\', '/', (string) $path);
        if (
            $templatesPath !== false
            && str_starts_with($file, $templatesPath . DIRECTORY_SEPARATOR)
            && preg_match('~^/murcms/templates/[a-z0-9_-]+/(?:assets|themes/[a-z0-9][a-z0-9_-]*)/~', $normalizedPath)
        ) {
            mur_serve_dev_asset($file);
            return true;
        }
    }
}

function mur_serve_dev_asset(string $file): void
{
    $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    $types = [
        'css' => 'text/css; charset=utf-8',
        'js' => 'application/javascript; charset=utf-8',
        'mjs' => 'application/javascript; charset=utf-8',
        'svg' => 'image/svg+xml; charset=utf-8',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'avif' => 'image/avif',
        'ico' => 'image/x-icon',
        'csv' => 'text/csv; charset=utf-8',
        'txt' => 'text/plain; charset=utf-8',
        'pdf' => 'application/pdf',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
    ];

    if (!isset($types[$extension]) || preg_match('~(?:^|[\\\\/])\\.[^\\\\/]+~', $file) === 1) {
        http_response_code(404);
        return;
    }

    $mtime = filemtime($file) ?: time();
    $size = filesize($file) ?: 0;
    $etag = '"' . sha1($file . '|' . $mtime . '|' . $size) . '"';

    header('Content-Type: ' . $types[$extension]);
    header('Cache-Control: public, max-age=31536000, immutable');
    header('ETag: ' . $etag);
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
    header('X-Content-Type-Options: nosniff');

    if (trim($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
        http_response_code(304);
        return;
    }

    $compressible = in_array($extension, ['css', 'js', 'mjs', 'svg', 'json', 'txt'], true);
    $acceptsGzip = str_contains($_SERVER['HTTP_ACCEPT_ENCODING'] ?? '', 'gzip');

    if ($compressible && $acceptsGzip && function_exists('gzencode')) {
        $contents = file_get_contents($file);

        if ($contents !== false) {
            $gzipped = gzencode($contents, 6);

            if ($gzipped !== false) {
                header('Content-Encoding: gzip');
                header('Vary: Accept-Encoding');
                header('Content-Length: ' . strlen($gzipped));
                echo $gzipped;
                return;
            }
        }
    }

    header('Content-Length: ' . $size);
    readfile($file);
}

if (
    PHP_SAPI === 'cli-server'
    && extension_loaded('zlib')
    && str_contains($_SERVER['HTTP_ACCEPT_ENCODING'] ?? '', 'gzip')
    && (string) ini_get('zlib.output_compression') !== '1'
) {
    ob_start('ob_gzhandler');
}

header_remove('X-Powered-By');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('X-Frame-Options: SAMEORIGIN');

$config = require __DIR__ . '/murcms/app/bootstrap.php';

use Mur\Core\ContentRepository;
use Mur\Core\Hooks;
use Mur\Core\Menu;
use Mur\Core\PluginContext;
use Mur\Core\PluginManager;
use Mur\Core\Renderer;
use Mur\Core\Router;
use Mur\Core\SeoManager;
use Mur\Core\TemplateManager;
use Mur\Admin\AdminAuth;
use Mur\Admin\FrontendAdminBar;
use Mur\Admin\AdminI18n;
use Mur\Admin\AdminRenderer;
use Mur\Admin\AdminRouter;

$hooks = new Hooks();
$content = new ContentRepository(mur_path('content'), $config['languages']);
$seo = new SeoManager($config['site'], $config['languages'], $config['seo'], $content, $hooks);
$menu = new Menu(mur_path('menus'), $content);
$pluginContext = new PluginContext($hooks, $config['site'], $config['languages'], mur_path(), mur_engine_path('plugins'), $content);
$pluginManager = new PluginManager(mur_engine_path('plugins'), $config['plugins'], $pluginContext);
$pluginManager->loadEnabled();

$adminI18n = new AdminI18n(mur_engine_path('admin/lang'), $config['admin']);
$adminAuth = new AdminAuth($config['admin']);
$frontendAdminBar = new FrontendAdminBar($adminAuth);
$hooks->add('layout.body_end', static fn (string $html, array $context): string => $html . $frontendAdminBar->render($context));
$adminRenderer = new AdminRenderer($config['site'], $adminI18n);
$adminRouter = new AdminRouter($config['site'], $config['languages'], $config['admin'], $config['theme'], $content, $hooks, $pluginManager, $seo, $adminAuth, $adminI18n, $adminRenderer);

if ($adminRouter->matches($_SERVER['REQUEST_URI'] ?? '/')) {
    $adminRouter->handle($_SERVER['REQUEST_URI'] ?? '/');
    return;
}

$siteFunctions = new \Mur\Core\SiteFunctions(mur_data_path(), TemplateManager::resolvedName(mur_engine_path('templates'), $config['theme']));
$siteFunctions->load();
$template = (new TemplateManager(mur_engine_path('templates'), $config['theme'], $config['site'], $config['languages'], $content, $menu, $hooks))->load();
$renderer = new Renderer($config['site'], $config['languages'], $content, $menu, $hooks, $template, $config['theme'], $config['seo']);
$router = new Router($config['site'], $config['languages'], $content, $renderer, $seo, $hooks);

$router->handle($_SERVER['REQUEST_URI'] ?? '/');
