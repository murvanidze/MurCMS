<?php
declare(strict_types=1);

define('MUR_CMS_ENTRY', true);
$projectRoot = dirname(__DIR__);
$config = require $projectRoot . '/root/murcms/app/bootstrap.php';

use Mur\Core\ContentRepository;
use Mur\Core\Document;
use Mur\Core\Hooks;
use Mur\Core\Menu;
use Mur\Core\Renderer;
use Mur\Core\TemplateManager;
use Mur\Core\PluginContext;
use Mur\Core\PluginManager;
use Mur\Core\SeoManager;
use Mur\Admin\AdminAuth;
use Mur\Admin\AdminFileManager;
use Mur\Admin\AdminMediaManager;
use Mur\Admin\AdminUserManager;
use Mur\Admin\FrontendAdminBar;
use Mur\Admin\AdminI18n;
use Mur\Admin\AdminRenderer;
use Mur\Admin\AdminRouter;
use MurCMS\RedirectsPlugin\RedirectsPlugin;

require_once $projectRoot . '/root/murcms/plugins/redirects/src/RedirectsPlugin.php';

$production = in_array('--production', $argv, true);
$failures = [];
$warnings = [];
$checks = 0;

$check = static function (bool $condition, string $message) use (&$failures, &$checks): void {
    $checks++;
    if (!$condition) {
        $failures[] = $message;
    }
};

$check(version_compare(PHP_VERSION, '8.5.0', '>='), 'PHP 8.5 or newer is required.');
$check(class_exists(ZipArchive::class), 'PHP zip extension is required for bulk ZIP downloads and page-delete backups.');
$check(is_array($config['site'] ?? null), 'Site config was not loaded.');
$check(is_array($config['languages'] ?? null), 'Languages config was not loaded.');
$check(is_array($config['admin']['users'] ?? null), 'Admin users config was not loaded.');
$check(mur_favicon_config_path('/uploads/branding/favicon.svg') === 'branding/favicon.svg', 'Configured favicon path was not normalized.');
$check(mur_favicon_config_path('../favicon.svg') === '', 'Configured favicon path accepted traversal.');
$check(mur_favicon_config_path('favicon.jpg') === '', 'Configured favicon path accepted an unsupported extension.');
$check(str_contains(mur_favicon_link([], 'admin'), '/murcms/assets/site-icon.svg'), 'Admin favicon did not fall back to the MurCMS icon.');
$distributionSnippets = require $projectRoot . '/root/data/config/snippets.dist.php';
$check(($distributionSnippets['allow_php'] ?? null) === false, 'Distribution config enables PHP snippets by default.');
$distributionAdmin = require $projectRoot . '/root/data/config/admin.dist.php';
$check(($distributionAdmin['users'] ?? null) === [], 'Public distribution config must not contain a shared administrator.');
$check(
    array_values(array_filter((array) ($distributionAdmin['legacy_password_hashes'] ?? []), 'is_string')) !== [],
    'Distribution config must retain compromised credential hashes for production detection.',
);
$distributionPlugins = require $projectRoot . '/root/data/config/plugins.dist.php';
$check(count($distributionPlugins['enabled']) === 8 && in_array('berrymur', $distributionPlugins['enabled'], true) && !in_array('banerina', $distributionPlugins['enabled'], true), 'Distribution must contain eight bundled plugins including BerryMur, with Banerina distributed separately.');
$legacyConsentId = 'privacy' . '-consent';
$check(!in_array($legacyConsentId, (array) ($distributionPlugins['enabled'] ?? []), true), 'The retired consent plugin is still enabled in the distribution.');

$faviconNonce = bin2hex(random_bytes(5));
$faviconFirst = 'favicon-10-smoke-' . $faviconNonce . '.svg';
$faviconSecond = 'favicon-20-smoke-' . $faviconNonce . '.png';
$faviconIgnored = 'site-favicon-smoke-' . $faviconNonce . '.webp';
$faviconNestedDir = mur_uploads_path('smoke-favicon-' . $faviconNonce);
$faviconNestedName = 'favicon-nested-' . $faviconNonce . '.svg';
$faviconNested = $faviconNestedDir . '/' . $faviconNestedName;

try {
    mkdir($faviconNestedDir, 0775, true);
    file_put_contents(mur_uploads_path($faviconFirst), '<svg xmlns="http://www.w3.org/2000/svg"></svg>', LOCK_EX);
    file_put_contents(mur_uploads_path($faviconSecond), 'png', LOCK_EX);
    file_put_contents(mur_uploads_path($faviconIgnored), 'webp', LOCK_EX);
    file_put_contents($faviconNested, '<svg xmlns="http://www.w3.org/2000/svg"></svg>', LOCK_EX);

    $faviconCandidates = mur_auto_favicon_candidates();
    $autoFavicon = mur_favicon(['favicon_frontend' => ''], 'frontend');
    $manualFavicon = mur_favicon(['favicon_frontend' => $faviconSecond], 'frontend');
    $manualAdminFavicon = mur_favicon(['favicon_admin' => $faviconSecond], 'admin');

    $check($faviconCandidates !== [] && ($autoFavicon['path'] ?? '') === $faviconCandidates[0], 'Frontend favicon did not use the first automatic candidate.');
    $check(in_array($faviconFirst, $faviconCandidates, true) && in_array($faviconSecond, $faviconCandidates, true), 'Automatic favicon search missed favicon* files.');
    $check(!in_array($faviconIgnored, $faviconCandidates, true), 'Automatic favicon search accepted a filename that does not start with favicon.');
    $check(!in_array($faviconNestedName, $faviconCandidates, true), 'Automatic favicon search scanned a subdirectory.');
    $check(($manualFavicon['path'] ?? '') === $faviconSecond && ($manualAdminFavicon['path'] ?? '') === $faviconSecond, 'Manual favicon path did not override fallback behavior.');
    $check(($manualFavicon['type'] ?? '') === 'image/png', 'PNG favicon MIME type is incorrect.');
} finally {
    foreach ([mur_uploads_path($faviconFirst), mur_uploads_path($faviconSecond), mur_uploads_path($faviconIgnored), $faviconNested] as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
    if (is_dir($faviconNestedDir)) {
        rmdir($faviconNestedDir);
    }
}

if ($production) {
    $check(extension_loaded('mbstring'), 'The mbstring extension is required in production.');
    $check(extension_loaded('gd') && function_exists('imagewebp'), 'GD with WebP support is required in production.');
    $check(($config['site']['environment'] ?? '') === 'production', 'Set site.environment to production.');
    $check(str_starts_with((string) ($config['site']['base_url'] ?? ''), 'https://'), 'Production base_url must use HTTPS.');

    $distributionHashes = array_values(array_filter(array_map(
        static fn (mixed $user): string => is_array($user) ? (string) ($user['password_hash'] ?? '') : '',
        (array) ($distributionAdmin['users'] ?? []),
    )));
    $distributionHashes = array_values(array_unique(array_merge(
        $distributionHashes,
        array_map('strval', (array) ($distributionAdmin['legacy_password_hashes'] ?? [])),
    )));
    foreach (($config['admin']['users'] ?? []) as $user) {
        $check(
            !in_array((string) ($user['password_hash'] ?? ''), $distributionHashes, true),
            "Replace the distribution password for admin user '{$user['username']}'.",
        );
    }
}

$repo = new ContentRepository($projectRoot . '/root/data/content', $config['languages']);

foreach (($config['languages']['enabled'] ?? []) as $language) {
    $documents = $repo->all((string) $language);
    $ids = [];
    $paths = [];
    $translationKeys = [];

    foreach ($documents as $document) {
        if (!$document instanceof Document) {
            continue;
        }

        $id = $document->id();
        $check($id !== '', "Empty document ID in {$document->filePath}.");
        $check(!isset($ids[$id]), "Duplicate ID '{$id}' in language {$language}.");
        $ids[$id] = $document;

        $check($document->urlPath !== '', "Empty URL path for '{$id}' in language {$language}.");
        $check(!isset($paths[$document->urlPath]), "Duplicate URL '{$document->urlPath}' in language {$language}.");
        $paths[$document->urlPath] = true;

        $translationKey = $document->translationKey();
        if ($translationKey !== '') {
            $check(!isset($translationKeys[$translationKey]), "Duplicate translation key '{$translationKey}' in language {$language}.");
            $translationKeys[$translationKey] = true;
        }
    }

    foreach ($documents as $document) {
        if (!$document instanceof Document) {
            continue;
        }

        $parentId = $document->parentId();
        if ($parentId !== '') {
            $parent = $ids[$parentId] ?? null;
            $check($parent instanceof Document, "Missing parent '{$parentId}' for '{$document->id()}' in language {$language}.");
            $check(!$parent instanceof Document || $parent->collection === $document->collection, "Parent collection mismatch for '{$document->id()}'.");
        }

        if ($document->collection === 'pages') {
            foreach ($document->categoryIds() as $categoryId) {
                $category = $ids[$categoryId] ?? null;
                $check($category instanceof Document && $category->collection === 'categories', "Missing category '{$categoryId}' for '{$document->id()}'.");
            }
        }
    }
}

$indexNonce = bin2hex(random_bytes(5));
$indexRoot = sys_get_temp_dir() . '/murcms-index-' . $indexNonce;
$indexFiles = [
    $indexRoot . '/content/ru/pages/z-source.mur.md' => "id: source\ntitle: Source\nslug: source\nstatus: published\ntranslation_key: shared\n---\n",
    $indexRoot . '/content/ru/pages/a-first.mur.md' => "id: first\ntitle: First\nslug: first\nstatus: published\n---\n",
    $indexRoot . '/content/ro/pages/target.mur.md' => "id: target\ntitle: Target\nslug: target\nstatus: published\ntranslation_key: shared\n---\n",
];

try {
    foreach ($indexFiles as $file => $content) {
        $directory = dirname($file);
        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }
        file_put_contents($file, $content, LOCK_EX);
    }

    $indexRepo = new ContentRepository($indexRoot . '/content', ['default' => 'ru', 'enabled' => ['ru', 'ro']]);
    $indexDocuments = $indexRepo->all('ru');
    $sourceDocument = $indexRepo->findById('ru', 'source');
    $translation = $sourceDocument instanceof Document ? $indexRepo->findTranslation($sourceDocument, 'ro') : null;
    $check(array_map(static fn (Document $document): string => $document->id(), $indexDocuments) === ['first', 'source'], 'Content repository file order is not deterministic.');
    $check($translation instanceof Document && $translation->id() === 'target', 'Content repository translation index is incorrect.');
} finally {
    foreach (array_keys($indexFiles) as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
    foreach ([$indexRoot . '/content/ru/pages', $indexRoot . '/content/ro/pages', $indexRoot . '/content/ru', $indexRoot . '/content/ro', $indexRoot . '/content', $indexRoot] as $directory) {
        if (is_dir($directory)) {
            rmdir($directory);
        }
    }
}

$hooks = new Hooks();
$hooks->add('smoke', static fn (string $value): string => $value . 'b', 20);
$hooks->add('smoke', static fn (string $value): string => $value . 'a', 10);
$check($hooks->filter('smoke', '') === 'ab', 'Hook priority order is broken.');

$nativeBody = mur_render_body("# Native body");
$check(str_contains($nativeBody, '<h1>Native body</h1>'), 'Legacy mur_render_body() call is no longer compatible.');
$renderBodyHooks = new Hooks();
$renderBodyContext = null;
$renderBodyHooks->add('content.render_body', static function (mixed $value, array $context) use (&$renderBodyContext): string {
    $renderBodyContext = $context;
    return '<section>Hook body</section>';
});
$check(mur_render_body('Source body', $renderBodyHooks, ['surface' => 'document']) === '<section>Hook body</section>', 'content.render_body did not replace native rendering.');
$check(($renderBodyContext['source'] ?? null) === 'Source body' && ($renderBodyContext['surface'] ?? null) === 'document', 'content.render_body context is incomplete.');
$fallbackBodyHooks = new Hooks();
$fallbackBodyHooks->add('content.render_body', static fn (mixed $value): null => null);
$check(str_contains(mur_render_body("# Fallback body", $fallbackBodyHooks), '<h1>Fallback body</h1>'), 'A null content.render_body result did not fall back to native rendering.');

$legacyRouteHooks = new Hooks();
$legacyPublicContext = null;
$legacyNotFoundContext = null;
$legacyRouteHooks->add('route.public', static function (mixed $html, array $context) use (&$legacyPublicContext): mixed {
    $legacyPublicContext = $context;
    return $html;
});
$legacyRouteHooks->add('route.not_found', static function (mixed $html, array $context) use (&$legacyNotFoundContext): string {
    $legacyNotFoundContext = $context;
    return 'legacy-route-ok';
});
$legacyMenu = new Menu($projectRoot . '/root/data/menus', $repo);
$legacyTemplate = (new TemplateManager(
    $projectRoot . '/root/murcms/templates',
    $config['theme'],
    $config['site'],
    $config['languages'],
    $repo,
    $legacyMenu,
    $legacyRouteHooks,
))->load();
$legacyRenderer = new Renderer(
    $config['site'],
    $config['languages'],
    $repo,
    $legacyMenu,
    $legacyRouteHooks,
    $legacyTemplate,
    $config['theme'],
);
$legacyRouter = new \Mur\Core\Router(
    $config['site'],
    $config['languages'],
    $repo,
    $legacyRenderer,
    new SeoManager($config['site'], $config['languages'], $config['seo'], $repo, $legacyRouteHooks),
    $legacyRouteHooks,
);
ob_start();
$legacyRouter->handle('/legacy-source');
$legacyRouteOutput = ob_get_clean();
$check(
    $legacyRouteOutput === 'legacy-route-ok'
    && ($legacyPublicContext['language'] ?? null) === $config['languages']['default']
    && ($legacyNotFoundContext['path'] ?? null) === 'legacy-source',
    'Router did not pass legacy non-language URLs through public and 404 hooks with the default language.',
);

$redirectNonce = bin2hex(random_bytes(5));
$redirectRoot = sys_get_temp_dir() . '/murcms-redirects-' . $redirectNonce;
try {
    mkdir($redirectRoot . '/config', 0775, true);
    $redirectContext = new PluginContext(
        new Hooks(),
        ['name' => 'Redirect Smoke', 'base_url' => 'https://example.test'],
        ['default' => 'ru', 'enabled' => ['ru']],
        $redirectRoot,
        $projectRoot . '/root/murcms/plugins',
        $repo,
    );
    $redirectsPlugin = new RedirectsPlugin($redirectContext);
    $redirectRules = "/ru/old | /ru/new | 301\nregex:^/ru/legacy/(.+)$ | /ru/new/$1 | 308\n# comment\n";
    $parsedRedirects = $redirectsPlugin->parseRules($redirectRules);
    $literalRedirect = $redirectsPlugin->matchRules($redirectRules, '/ru/old?utm=1', 'example.test');
    $regexRedirect = $redirectsPlugin->matchRules($redirectRules, '/ru/legacy/item-1', 'example.test');
    $absoluteRedirect = $redirectsPlugin->matchRules('https://example.test/ru/old | /ru/new | 301', '/ru/old', null);
    $badRedirects = $redirectsPlugin->parseRules('/ru/bad | javascript:alert(1) | 301');
    $check(count($parsedRedirects['rules']) === 2 && $parsedRedirects['errors'] === [], 'Redirect rules did not parse valid literal and regex rows.');
    $check(($literalRedirect['location'] ?? '') === '/ru/new' && ($literalRedirect['status'] ?? 0) === 301, 'Literal redirect matching failed.');
    $check(($regexRedirect['location'] ?? '') === '/ru/new/item-1' && ($regexRedirect['status'] ?? 0) === 308, 'Regex redirect capture expansion failed.');
    $check(($absoluteRedirect['location'] ?? '') === '/ru/new', 'Absolute redirect source did not match site.base_url.');
    $check($badRedirects['errors'] !== [], 'Redirect validation accepted an unsafe target.');
} finally {
    foreach ([$redirectRoot . '/config/redirects.php', $redirectRoot . '/config/redirects.php.tmp'] as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
    foreach ([$redirectRoot . '/config', $redirectRoot] as $directory) {
        if (is_dir($directory)) {
            rmdir($directory);
        }
    }
}

$listingNonce = bin2hex(random_bytes(6));
$listingRoot = sys_get_temp_dir() . '/murcms-listing-' . $listingNonce;
$listingPages = $listingRoot . '/content/ru/pages';
$listingFiles = [
    $listingPages . '/listing.mur.md' => "id: listing\ntitle: Listing\nslug: listing\nstatus: published\ntemplate: listing\nlist.per_page: 1\nlist.position: shortcode\n---\nIntro\n\n[list]\n\nAfter\n",
    $listingPages . '/item-a.mur.md' => "id: item-a\ntitle: Item A\nslug: item-a\nstatus: published\nparent: listing\norder: 10\n---\n",
    $listingPages . '/item-b.mur.md' => "id: item-b\ntitle: Item B\nslug: item-b\nstatus: published\nparent: listing\norder: 20\n---\n",
    $listingPages . '/item-c.mur.md' => "id: item-c\ntitle: Item C\nslug: item-c\nstatus: published\nparent: listing\norder: 30\n---\n",
    $listingPages . '/titles.mur.md' => "id: titles\ntitle: Titles\nslug: titles\nstatus: published\ntemplate: listing\nlist.view: titles\nlist.show_image: true\nlist.show_description: true\n---\n",
    $listingPages . '/title-item.mur.md' => "id: title-item\ntitle: Title Item\nslug: title-item\nstatus: published\nparent: titles\nsummary: Description must not render.\nimage: /uploads/title-item.jpg\n---\n",
    $listingRoot . '/menus/ru/main.mur.md' => "id: main\ntitle: Main\nstatus: published\n---\n[\n# | Listing\n[\n# | Nested\npage:item-a | Deep item\n]\npage:listing | Listing hub\n]\n/ru/second/ | Short | Long mobile label | Responsive title | | |\n",
    $listingRoot . '/menus/ru/footer.mur.md' => "status: published\n---\n/ru/first/ | Footer link\n",
    $listingRoot . '/content/ru/footer/disclaimer.mur.md' => "---\nDisclaimer block\n",
    $listingRoot . '/content/ru/footer/copyright.mur.md' => "---\nCopyright block\n",
];
$previousGet = $_GET;
$previousUri = $_SERVER['REQUEST_URI'] ?? null;

try {
    mkdir($listingPages, 0775, true);
    mkdir($listingRoot . '/content/ru/footer', 0775, true);
    mkdir($listingRoot . '/menus/ru', 0775, true);
    foreach ($listingFiles as $file => $content) {
        file_put_contents($file, $content, LOCK_EX);
    }

    $listingLanguages = ['default' => 'ru', 'enabled' => ['ru'], 'labels' => ['ru' => 'RU']];
    $listingRepo = new ContentRepository($listingRoot . '/content', $listingLanguages);
    $listingDocument = $listingRepo->findById('ru', 'listing');
    $listingHooks = new Hooks();
    $listingMenu = new Menu($listingRoot . '/menus', $listingRepo);
    $listingSite = ['name' => 'Smoke', 'description' => '', 'base_url' => 'https://example.test'];
    $listingTemplate = (new TemplateManager(
        $projectRoot . '/root/murcms/templates',
        ['active' => 'default', 'templates' => ['default' => ['options' => [
            'block_layout' => ['footer' => ['ru' => ['disclaimer', 'menu:footer', 'copyright']]],
            'footer_menu_enabled' => true,
        ]]]],
        $listingSite,
        $listingLanguages,
        $listingRepo,
        $listingMenu,
        $listingHooks,
    ))->load();
    $listingRenderer = new Renderer(
        $listingSite,
        $listingLanguages,
        $listingRepo,
        $listingMenu,
        $listingHooks,
        $listingTemplate,
        ['active' => 'default', 'templates' => ['default' => ['options' => []]]],
    );
    $_GET = ['page' => '2'];
    $_SERVER['REQUEST_URI'] = '/ru/listing?page=2';
    $listingHtml = $listingDocument instanceof Document ? $listingRenderer->renderDocument($listingDocument) : '';
    $titlesDocument = $listingRepo->findById('ru', 'titles');
    $titlesHtml = $titlesDocument instanceof Document ? $listingRenderer->renderDocument($titlesDocument) : '';

    $check(substr_count($listingHtml, 'class="content-list-item"') === 1, 'Pagination did not limit the listing to one item.');
    $check(str_contains($listingHtml, 'class="pagination"'), 'Pagination navigation was not rendered.');
    $check(str_contains($listingHtml, 'aria-current="page">2</span>'), 'Pagination current page is incorrect.');
    $check(str_contains($listingHtml, 'rel="canonical" href="https://example.test/ru/listing?page=2"'), 'Paginated canonical URL is incorrect.');
    $check(str_contains($listingHtml, 'data-color-scheme="light"'), 'Default color scheme critical CSS was not inlined.');
    $check(str_contains($listingHtml, '--brand-image-height:50px'), 'Default brand image height is missing from the layout variables.');
    $check(str_contains($listingHtml, 'style data-template-critical'), 'Template critical CSS was not loaded from the template asset.');
    $check(str_contains($listingHtml, 'class="template-default ts-light theme-header-'), 'Short color-scheme body class is missing.');
    $check(str_contains($listingHtml, mur_favicon_link($listingSite, 'frontend')), 'Frontend favicon resolver is not used by the template.');
    $check(str_contains($listingHtml, 'class="site-identity"') && str_contains($listingHtml, 'class="site-icon"') && str_contains($listingHtml, '<span>MurCMS</span>'), 'Default frontend HTML brand is missing.');
    $check(str_contains($listingHtml, '/murcms/templates/default/themes/light/theme.css?v='), 'Default color scheme stylesheet was not linked.');
    $check(str_contains($listingHtml, '<use href="/murcms/templates/default/assets/icons.svg?v=') && str_contains($listingHtml, '#menu"></use>'), 'SVG menu icon was not rendered.');
    $check(str_contains($listingHtml, '<a href="/ru/listing"><span>Listing</span></a>'), 'Placeholder parent menu link did not inherit the first submenu URL.');
    $check(str_contains($listingHtml, 'id="site-submenu-0-0"'), 'Third-level menu markup was not rendered.');
    $check(str_contains($listingHtml, '<span class="menu-label-short">Short</span><span class="menu-label-long">Long mobile label</span>'), 'Responsive top-level menu labels were not rendered.');
    $check(str_contains($listingHtml, 'title="Responsive title"'), 'Responsive-label menu format shifted the title field incorrectly.');
    $check(strpos($listingHtml, '<p>Intro</p>') < strpos($listingHtml, 'class="content-list content-list-cards"'), 'List shortcode did not render after intro text.');
    $check(strpos($listingHtml, 'class="content-list content-list-cards"') < strpos($listingHtml, '<p>After</p>'), 'List shortcode did not render before following text.');
    $check(str_contains($listingHtml, '<article class="page-article">'), 'Document output is missing its article landmark.');
    $check(str_contains($listingHtml, '<div class="page-content"><p>Intro</p>'), 'Document body is missing its page-content wrapper.');
    $check(str_contains($listingHtml, '<nav id="site-nav" class="site-nav" aria-label="Main menu">'), 'Primary navigation landmark is missing or unlabeled.');
    $check(substr_count($listingHtml, '<main ') === 1, 'Document output must contain exactly one main landmark.');
    $check(strpos($listingHtml, 'Disclaimer block') < strpos($listingHtml, 'class="footer-menu"'), 'Footer menu was not inserted after the configured preceding block.');
    $check(strpos($listingHtml, 'class="footer-menu"') < strpos($listingHtml, 'Copyright block'), 'Footer menu was not inserted before the configured following block.');
    $check(substr_count($listingHtml, 'class="footer-menu"') === 1, 'Explicit footer menu token and legacy option rendered the menu twice.');
    $check(str_contains($titlesHtml, 'class="content-list content-list-titles"'), 'Titles listing view was not rendered.');
    $check(str_contains($titlesHtml, '>Title Item</a>'), 'Titles listing view did not render the item title.');
    $check(!str_contains($titlesHtml, 'class="content-list-image"'), 'Titles listing view rendered an image despite being titles-only.');
    $check(!str_contains($titlesHtml, 'class="content-list-description"'), 'Titles listing view rendered a description despite being titles-only.');
} finally {
    $_GET = $previousGet;
    if ($previousUri === null) {
        unset($_SERVER['REQUEST_URI']);
    } else {
        $_SERVER['REQUEST_URI'] = $previousUri;
    }

    foreach (array_keys($listingFiles) as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
    foreach ([$listingPages, $listingRoot . '/content/ru/footer', dirname($listingPages), dirname(dirname($listingPages)), $listingRoot . '/content', $listingRoot . '/menus/ru', $listingRoot . '/menus', $listingRoot] as $directory) {
        if (is_dir($directory)) {
            rmdir($directory);
        }
    }
}

$systemSnippetNonce = bin2hex(random_bytes(6));
$systemSnippetRoot = sys_get_temp_dir() . '/murcms-system-snippets-' . $systemSnippetNonce;
$systemSnippetData = $systemSnippetRoot . '/data';
$systemSnippetFiles = [
    $systemSnippetData . '/snippets/system-header-before.mur.md' => "id: system-header-before\nstatus: published\ntype: html\n---\n\n<span>GLOBAL-HEADER-BEFORE</span>\n",
    $systemSnippetData . '/snippets/ru/normal.mur.md' => "id: normal\nstatus: published\ntype: html\n---\n\n<span>NORMAL-SNIPPET</span>\n",
    $systemSnippetData . '/snippets/ru/alternate.mur.md' => "id: alternate\nstatus: published\ntype: html\n---\n\n<span>ALTERNATE-SNIPPET</span>\n",
];
try {
    foreach ([$systemSnippetData . '/content/ru/pages', $systemSnippetData . '/content/ru/footer', $systemSnippetData . '/menus/ru', $systemSnippetData . '/snippets/ru', $systemSnippetData . '/config'] as $directory) {
        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }
    }
    foreach ($systemSnippetFiles as $file => $content) {
        file_put_contents($file, $content, LOCK_EX);
    }
    $banerinaConfig = [
        'settings' => ['avoid_duplicates' => true, 'selection_mode' => 'random', 'diagnostics_logging' => false],
        'groups' => [
            'rotation' => ['id' => 'rotation', 'name' => 'Rotation', 'description' => '', 'active' => true, 'snippets' => ['normal', 'alternate']],
            'inactive' => ['id' => 'inactive', 'name' => 'Inactive', 'description' => '', 'active' => false, 'snippets' => ['normal']],
        ],
    ];
    file_put_contents(
        $systemSnippetData . '/config/banerina.php',
        "<?php\ndeclare(strict_types=1);\n\nif (!defined('MUR_CMS_BOOTSTRAPPED')) {\n    http_response_code(403);\n    exit('No direct script access allowed.');\n}\n\nreturn " . var_export($banerinaConfig, true) . ";\n",
        LOCK_EX,
    );

    $systemSnippetLanguages = ['default' => 'ru', 'enabled' => ['ru', 'ro'], 'labels' => ['ru' => 'RU', 'ro' => 'RO']];
    $systemSnippetRepo = new ContentRepository($systemSnippetData . '/content', $systemSnippetLanguages);
    $loadSystemSnippetHooks = static function () use ($projectRoot, $systemSnippetData, $systemSnippetLanguages, $systemSnippetRepo): Hooks {
        $hooks = new Hooks();
        $context = new PluginContext($hooks, ['name' => 'Smoke'], $systemSnippetLanguages, $systemSnippetData, $projectRoot . '/root/murcms/plugins', $systemSnippetRepo);
        $plugins = new PluginManager($projectRoot . '/root/murcms/plugins', ['enabled' => ['snippets', 'banerina']], $context);
        $plugins->loadEnabled();
        return $hooks;
    };

    $fallbackHooks = $loadSystemSnippetHooks();
    $check(str_contains($fallbackHooks->render('layout.header_before', ['language' => 'ru']), 'GLOBAL-HEADER-BEFORE'), 'System snippet did not fall back to global scope.');
    $check($fallbackHooks->render('layout.main_before', ['language' => 'ru']) === '', 'Missing system snippet produced output or a placeholder file.');
    $check(str_contains((string) $fallbackHooks->filter('content.document', '[snippet=normal]', ['language' => 'ru']), 'NORMAL-SNIPPET'), 'Ordinary snippet rendering regressed.');
    $check($fallbackHooks->has('snippets.render') && $fallbackHooks->has('snippets.registry'), 'Snippets public API hooks were not registered.');
    $snippetApiHtml = (string) $fallbackHooks->filter('snippets.render', '', ['id' => 'normal', 'language' => 'ru']);
    $check(str_contains($snippetApiHtml, 'NORMAL-SNIPPET'), 'snippets.render did not use the ordinary Snippets render pipeline.');
    $check($fallbackHooks->filter('snippets.render', '', ['id' => '../normal', 'language' => 'ru']) === '', 'snippets.render accepted an invalid snippet ID.');
    $snippetRegistry = $fallbackHooks->filter('snippets.registry', [], []);
    $normalRegistry = array_values(array_filter(
        is_array($snippetRegistry) ? $snippetRegistry : [],
        static fn (mixed $entry): bool => is_array($entry) && ($entry['id'] ?? '') === 'normal',
    ));
    $check(
        isset($normalRegistry[0])
        && in_array('ru', (array) ($normalRegistry[0]['scopes'] ?? []), true)
        && in_array('mur.md', (array) ($normalRegistry[0]['formats'] ?? []), true),
        'snippets.registry did not expose snippet scope and format metadata.',
    );
    $directPromo = (string) $fallbackHooks->filter('content.document', '[promo=normal]', ['language' => 'ru']);
    $check(
        str_contains($directPromo, 'NORMAL-SNIPPET')
        && str_contains($directPromo, 'class="mur-promo"')
        && str_contains($directPromo, 'data-promo-mode="direct"'),
        'Banerina did not render a direct snippet through Snippets.',
    );
    $rotationHooks = $loadSystemSnippetHooks();
    $rotationHtml = (string) $rotationHooks->filter('content.document', "[promo=rotation]\n[promo=rotation]", ['language' => 'ru']);
    $check(
        substr_count($rotationHtml, 'NORMAL-SNIPPET') === 1
        && substr_count($rotationHtml, 'ALTERNATE-SNIPPET') === 1
        && substr_count($rotationHtml, 'data-promo-group="rotation"') === 2,
        'Banerina group rotation did not avoid duplicates inside one request.',
    );
    $check(
        $rotationHooks->filter('content.document', '[promo=inactive]', ['language' => 'ru']) === '',
        'Banerina rendered an inactive group.',
    );

    $localHeaderFile = $systemSnippetData . '/snippets/ru/system-header-before.mur.md';
    file_put_contents($localHeaderFile, "id: system-header-before\nstatus: draft\ntype: html\n---\n\n<span>DRAFT-HEADER</span>\n", LOCK_EX);
    $draftHooks = $loadSystemSnippetHooks();
    $check($draftHooks->render('layout.header_before', ['language' => 'ru']) === '', 'A local draft system snippet did not stop fallback.');

    $newSystemMarkers = [
        'system-header-before' => 'SYSTEM-HEADER-BEFORE',
        'system-header-after' => 'SYSTEM-HEADER-AFTER',
        'system-main-before' => 'SYSTEM-MAIN-BEFORE',
        'system-main-after' => 'SYSTEM-MAIN-AFTER',
    ];
    foreach ($newSystemMarkers as $name => $marker) {
        file_put_contents($systemSnippetData . '/snippets/ru/' . $name . '.mur.md', "id: {$name}\nstatus: published\ntype: html\n---\n\n<span>{$marker}</span>\n", LOCK_EX);
    }
    $layoutHooks = $loadSystemSnippetHooks();
    foreach (['layout.header_before', 'layout.header_after', 'layout.main_before', 'layout.main_after'] as $hook) {
        $check($layoutHooks->has($hook), "Snippets plugin did not register {$hook}.");
    }
    $systemSnippetMenu = new Menu($systemSnippetData . '/menus', $systemSnippetRepo);
    $systemSnippetTemplate = (new TemplateManager(
        $projectRoot . '/root/murcms/templates',
        ['active' => 'default', 'templates' => ['default' => ['options' => []]]],
        ['name' => 'Smoke', 'description' => '', 'base_url' => 'https://example.test'],
        $systemSnippetLanguages,
        $systemSnippetRepo,
        $systemSnippetMenu,
        $layoutHooks,
    ))->load();
    $systemLayoutHtml = $systemSnippetTemplate->renderLayout('ru', null, '<p>SYSTEM-BODY</p>', ['title' => 'Smoke', 'description' => '']);
    $check(strpos($systemLayoutHtml, 'SYSTEM-HEADER-BEFORE') < strpos($systemLayoutHtml, '<header class="site-header">'), 'system-header-before is not immediately before the site header.');
    $check(strpos($systemLayoutHtml, '</header>') < strpos($systemLayoutHtml, 'SYSTEM-HEADER-AFTER') && strpos($systemLayoutHtml, 'SYSTEM-HEADER-AFTER') < strpos($systemLayoutHtml, 'SYSTEM-MAIN-BEFORE'), 'system-header-after is not between the site header and main-before position.');
    $check(strpos($systemLayoutHtml, 'SYSTEM-MAIN-BEFORE') < strpos($systemLayoutHtml, '<main id="main"'), 'system-main-before is not before main.');
    $check(strpos($systemLayoutHtml, '</main>') < strpos($systemLayoutHtml, 'SYSTEM-MAIN-AFTER') && strpos($systemLayoutHtml, 'SYSTEM-MAIN-AFTER') < strpos($systemLayoutHtml, '<footer class="site-footer">'), 'system-main-after is not between main and footer.');
    $check(strpos($systemLayoutHtml, 'class="skip-link"') < strpos($systemLayoutHtml, 'SYSTEM-HEADER-BEFORE'), 'System snippets moved the existing skip-link.');
} finally {
    if (is_dir($systemSnippetRoot)) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($systemSnippetRoot, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($systemSnippetRoot);
    }
}

$blockNonce = bin2hex(random_bytes(6));
$blockRoot = sys_get_temp_dir() . '/murcms-blocks-' . $blockNonce;
$blockFiles = [
    $blockRoot . '/content/ru/home/shared.mur.md' => "id: ignored-shared\nstatus: draft\n---\nShared default\n",
    $blockRoot . '/content/ru/home/nested/legacy.mur.md' => "id: ignored-legacy\nstatus: published\n---\nLegacy\n",
    $blockRoot . '/content/ro/home/local.mur.md' => "id: ignored-local\nstatus: published\n---\nLocal\n",
];

try {
    foreach ($blockFiles as $file => $content) {
        mkdir(dirname($file), 0775, true);
        file_put_contents($file, $content, LOCK_EX);
    }
    $blockLanguages = ['default' => 'ru', 'enabled' => ['ru', 'ro'], 'labels' => ['ru' => 'RU', 'ro' => 'RO']];
    $blockRepo = new ContentRepository($blockRoot . '/content', $blockLanguages);
    $selectedBlocks = $blockRepo->homeBlocks('ro', ['shared', 'local'], 'ru');
    $check(array_map(static fn (Document $block): string => substr($block->fileName, 0, -7), $selectedBlocks) === ['shared', 'local'], 'Configured home block filename order or default-language fallback is incorrect.');
    $check($selectedBlocks[0]->language === 'ru', 'Missing translated block did not fall back to the default language.');
    $check($blockRepo->homeBlocks('ro', [], 'ru') === [], 'Explicit empty block layout did not hide all blocks.');
    $check($blockRepo->homeBlocks('ru') === [], 'Theme blocks rendered without an explicit layout.');
} finally {
    foreach (array_keys($blockFiles) as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
    $directories = [
        $blockRoot . '/content/ru/home/nested', $blockRoot . '/content/ru/home', $blockRoot . '/content/ru',
        $blockRoot . '/content/ro/home', $blockRoot . '/content/ro', $blockRoot . '/content', $blockRoot,
    ];
    foreach ($directories as $directory) {
        if (is_dir($directory)) {
            rmdir($directory);
        }
    }
}

$backupNonce = bin2hex(random_bytes(6));
$backupSource = $projectRoot . "/root/data/content/.smoke-{$backupNonce}.mur.md";
$backupBase = $projectRoot . "/root/data/storage/backups/content/.smoke-{$backupNonce}.mur.md";
$backupLatest = $backupBase . '.bak.1';
$backupPrevious = $backupBase . '.bak.2';

try {
    file_put_contents($backupSource, "version one\n", LOCK_EX);
    $router = (new ReflectionClass(AdminRouter::class))->newInstanceWithoutConstructor();
    $backupMethod = new ReflectionMethod(AdminRouter::class, 'backupManagedFile');
    $backupMethod->invoke($router, $backupSource);
    file_put_contents($backupSource, "version two\n", LOCK_EX);
    $backupMethod->invoke($router, $backupSource);

    $check((file_get_contents($backupLatest) ?: '') === "version two\n", 'Latest document backup is incorrect.');
    $check((file_get_contents($backupPrevious) ?: '') === "version one\n", 'Previous document backup is incorrect.');
} finally {
    foreach ([$backupSource, $backupLatest, $backupPrevious] as $temporaryFile) {
        if (is_file($temporaryFile)) {
            unlink($temporaryFile);
        }
    }

    $backupDirectory = dirname($backupBase);
if (is_dir($backupDirectory) && (scandir($backupDirectory) ?: []) === ['.', '..']) {
        rmdir($backupDirectory);
    }
}

$adminAuth = new AdminAuth($config['admin']);
$adminUser = [
    'id' => 'smoke-admin',
    'username' => 'smoke-admin',
    'role' => 'admin',
    'csrf' => $adminAuth->csrfToken(),
];
$configuredUsers = array_values(array_filter((array) ($config['admin']['users'] ?? []), 'is_array'));
$configuredSuperAdmins = array_values(array_filter(
    $configuredUsers,
    static fn (array $user): bool => AdminUserManager::normalizeRole((string) ($user['role'] ?? '')) === AdminUserManager::ROLE_SUPER_ADMIN,
));
$check(count($configuredSuperAdmins) === 1, 'Admin config must contain exactly one super-admin.');
$configuredSuperAdminHash = (string) ($configuredSuperAdmins[0]['password_hash'] ?? '');
$configuredSuperAdminHashInfo = password_get_info($configuredSuperAdminHash);
$check(
    trim((string) ($configuredSuperAdmins[0]['username'] ?? '')) !== ''
    && ($configuredSuperAdminHashInfo['algoName'] ?? 'unknown') !== 'unknown',
    'The configured super-admin must have a username and a valid password hash.',
);
$check($adminAuth->can(['role' => 'super_admin'], 'admin'), 'Super-admin does not inherit admin permissions.');
$check($adminAuth->can(['role' => 'admin'], 'editor'), 'Admin does not inherit editor permissions.');
$check($adminAuth->can(['role' => 'content'], 'editor'), 'Legacy content role is not treated as editor.');
$check(!$adminAuth->can(['role' => 'editor'], 'admin'), 'Editor unexpectedly has admin permissions.');
$check(!$adminAuth->can(['role' => 'super_admin'], 'unknown-role'), 'Unknown required roles are not denied.');

$profileRouter = (new ReflectionClass(AdminRouter::class))->newInstanceWithoutConstructor();
$profileConfigProperty = new ReflectionProperty(AdminRouter::class, 'adminConfig');
$profileConfigProperty->setValue($profileRouter, [
    'users' => [[
        'id' => 'profile-user',
        'username' => 'profile-user',
        'admin_language' => 'ru',
        'password_hash' => password_hash('ProfilePassword!123', PASSWORD_DEFAULT),
    ]],
]);
$updateAdminUserMethod = new ReflectionMethod(AdminRouter::class, 'updateAdminUser');
$changedProfileHash = password_hash('ChangedProfile!456', PASSWORD_DEFAULT);
$updatedProfileUser = $updateAdminUserMethod->invoke(
    $profileRouter,
    'profile-user',
    ['admin_language' => 'ro', 'password_hash' => $changedProfileHash],
);
$updatedProfileConfig = $profileConfigProperty->getValue($profileRouter);
$check(
    is_array($updatedProfileUser)
    && (string) ($updatedProfileConfig['users'][0]['admin_language'] ?? '') === 'ro'
    && password_verify('ChangedProfile!456', (string) ($updatedProfileConfig['users'][0]['password_hash'] ?? '')),
    'Own-profile language or password changes were not applied to the persisted admin config.',
);
$adminRouterSource = file_get_contents($projectRoot . '/root/murcms/app/Admin/AdminRouter.php') ?: '';
$check(
    str_contains($adminRouterSource, 'opcache_invalidate($file, true)'),
    'PHP config writes do not invalidate OPcache.',
);

$superActor = ['id' => 'root-user', 'role' => 'super_admin'];
$managerActor = ['id' => 'manager-user', 'role' => 'admin'];
$editorActor = ['id' => 'editor-user', 'role' => 'editor'];
$userManager = new AdminUserManager([
    $superActor + ['username' => 'root', 'email' => 'root@example.test', 'display_name' => 'Root', 'active' => true, 'password_hash' => password_hash('RootPassword!123', PASSWORD_DEFAULT)],
    $managerActor + ['username' => 'manager', 'email' => 'manager@example.test', 'display_name' => 'Manager', 'active' => true, 'password_hash' => password_hash('ManagerPassword!123', PASSWORD_DEFAULT)],
    $editorActor + ['username' => 'editor', 'email' => 'editor@example.test', 'display_name' => 'Editor', 'active' => true, 'password_hash' => password_hash('EditorPassword!123', PASSWORD_DEFAULT)],
]);
$check($userManager->canManage($superActor, $managerActor) && $userManager->canManage($superActor, $editorActor), 'Super-admin management scope is incomplete.');
$check($userManager->canManage($managerActor, $editorActor) && !$userManager->canManage($managerActor, $superActor), 'Admin management scope is incorrect.');
$check(!$userManager->canManage($superActor, $superActor) && $userManager->creatableRoles($managerActor) === ['editor'], 'Self-management or admin role creation rules are incorrect.');
$createdEditor = $userManager->save($managerActor, [
    'username' => 'second.editor',
    'display_name' => 'Second Editor',
    'email' => 'second.editor@example.test',
    'phone' => '+373 60 000 000',
    'role' => 'editor',
    'admin_language' => 'ru',
    'active' => true,
    'password' => 'SecondEditor!123',
    'password_confirm' => 'SecondEditor!123',
]);
$check(
    AdminUserManager::normalizeRole((string) ($createdEditor['role'] ?? '')) === 'editor'
    && password_verify('SecondEditor!123', (string) ($createdEditor['password_hash'] ?? '')),
    'Admin could not create a valid editor account.',
);
$adminCreationRejected = false;
try {
    $userManager->save($managerActor, [
        'username' => 'forbidden-admin',
        'display_name' => 'Forbidden Admin',
        'email' => 'forbidden-admin@example.test',
        'role' => 'admin',
        'active' => true,
        'password' => 'ForbiddenAdmin!123',
        'password_confirm' => 'ForbiddenAdmin!123',
    ]);
} catch (RuntimeException) {
    $adminCreationRejected = true;
}
$check($adminCreationRejected, 'A regular admin was allowed to create another admin.');
$createdAdmin = $userManager->save($superActor, [
    'username' => 'second.admin',
    'display_name' => 'Second Admin',
    'email' => 'second.admin@example.test',
    'phone' => '',
    'role' => 'admin',
    'admin_language' => 'ru',
    'active' => true,
    'password' => 'SecondAdmin!123',
    'password_confirm' => 'SecondAdmin!123',
]);
$adminEditRejected = false;
try {
    $userManager->save($managerActor, [
        'id' => (string) ($createdAdmin['id'] ?? ''),
        'display_name' => 'Changed by admin',
        'email' => 'second.admin@example.test',
        'role' => 'editor',
        'admin_language' => 'ru',
        'active' => true,
    ]);
} catch (RuntimeException) {
    $adminEditRejected = true;
}
$check($adminEditRejected, 'A regular admin was allowed to edit another admin.');
$updatedAdmin = $userManager->save($superActor, [
    'id' => (string) ($createdAdmin['id'] ?? ''),
    'display_name' => 'Second Admin',
    'email' => 'second.admin@example.test',
    'phone' => '+373 22 000 000',
    'role' => 'admin',
    'admin_language' => 'ro',
    'active' => true,
    'password' => 'ChangedAdmin!456',
    'password_confirm' => 'ChangedAdmin!456',
]);
$check(
    password_verify('ChangedAdmin!456', (string) ($updatedAdmin['password_hash'] ?? ''))
    && (string) ($updatedAdmin['phone'] ?? '') === '+373 22 000 000',
    'Super-admin could not update an admin profile and password.',
);
$userManager->delete($superActor, (string) ($createdAdmin['id'] ?? ''));
$deletedEditor = $userManager->delete($managerActor, (string) ($createdEditor['id'] ?? ''));
$check((string) ($deletedEditor['username'] ?? '') === 'second.editor' && $userManager->find((string) ($createdEditor['id'] ?? '')) === null, 'Admin could not delete a managed editor.');
$disabledAuth = new AdminAuth([
    'users' => [[
        'id' => 'disabled-user',
        'username' => 'disabled-user',
        'email' => 'disabled@example.test',
        'display_name' => 'Disabled',
        'role' => 'editor',
        'active' => false,
        'password_hash' => password_hash('DisabledUser!123', PASSWORD_DEFAULT),
    ]],
]);
$_SESSION['mur_admin_user'] = ['id' => 'disabled-user', 'role' => 'editor'];
$check($disabledAuth->user() === null, 'A deactivated user retained an authenticated session.');
$versionedHash = password_hash('VersionedUser!123', PASSWORD_DEFAULT);
$versionedAuth = new AdminAuth([
    'users' => [[
        'id' => 'versioned-user',
        'username' => 'versioned-user',
        'email' => 'versioned@example.test',
        'display_name' => 'Versioned',
        'role' => 'editor',
        'active' => true,
        'password_hash' => $versionedHash,
    ]],
]);
$_SESSION['mur_admin_user'] = ['id' => 'versioned-user', 'role' => 'editor', 'auth_version' => hash('sha256', 'old-password-hash')];
$check($versionedAuth->user() === null, 'A password change did not invalidate an older authenticated session.');
$check((string) ini_get('session.use_strict_mode') === '1', 'Admin sessions do not reject uninitialized session IDs.');
$check((string) ini_get('session.use_only_cookies') === '1' && (string) ini_get('session.use_trans_sid') === '0', 'Admin session transport is not cookie-only.');
$frontendAdminBar = new FrontendAdminBar($adminAuth);
$adminBarDocument = new Document('pages', 'ru', '', 'about.mur.md', ['id' => 'about', 'status' => 'published'], '');
$check($frontendAdminBar->render([]) === '', 'Frontend admin bar rendered without an admin session cookie.');
$sessionName = (string) ($config['admin']['session_name'] ?? 'mur_admin');
$_COOKIE[$sessionName] = 'smoke-admin-session';
$configuredAdmin = (array) (($config['admin']['users'] ?? [])[0] ?? []);
$_SESSION['mur_admin_user'] = [
    'id' => (string) ($configuredAdmin['id'] ?? ''),
    'role' => (string) ($configuredAdmin['role'] ?? ''),
];
$adminBarHtml = $frontendAdminBar->render([
    'document' => $adminBarDocument,
    'meta' => ['title' => 'About', 'description' => 'About description'],
    'canonical' => 'https://example.test/ru/about',
    'language' => 'ru',
]);
$check(str_contains($adminBarHtml, '/admin/content/pages/edit?lang=ru&amp;id=about'), 'Frontend admin bar edit URL is incorrect.');
$check(str_contains($adminBarHtml, 'https://example.test/ru/about'), 'Frontend admin bar did not render canonical metadata.');
unset($_SESSION['mur_admin_user'], $_COOKIE[$sessionName]);
$adminHooks = new Hooks();
$adminHooks->add('admin.editor_tools', static function (string $html, array $context): string {
    return $html . '<span data-smoke-editor="' . mur_escape((string) ($context['editor'] ?? ''))
        . '" data-smoke-textarea="' . mur_escape((string) ($context['textarea'] ?? '')) . '"></span>';
});
$adminRepo = new ContentRepository($projectRoot . '/root/data/content', $config['languages']);
$adminSeo = new SeoManager($config['site'], $config['languages'], $config['seo'], $adminRepo, $adminHooks);
$legacySeoConfig = $config['seo'];
$legacySeoConfig['robots']['content'] = "User-agent: *\nHost: obsolete.example\nSitemap: {SITEMAP_URL}\n";
$legacySeo = new SeoManager($config['site'], $config['languages'], $legacySeoConfig, $adminRepo, $adminHooks);
$check(!str_contains($legacySeo->robotsTemplate(), 'Host:'), 'Legacy Host directive is still shown in the robots editor.');
$check(!str_contains($legacySeo->robots(), 'Host:'), 'Legacy Host directive is still emitted in public robots.txt.');
$check(str_contains($legacySeo->robots(), 'Sitemap: ' . rtrim((string) $config['site']['base_url'], '/') . '/sitemap.xml'), 'Robots sitemap placeholder was not expanded.');
$adminPluginContext = new PluginContext($adminHooks, $config['site'], $config['languages'], $projectRoot . '/root/data', $projectRoot . '/root/murcms/plugins', $adminRepo);
$murTextAdminUi = $projectRoot . '/root/murcms/plugins/murtext/src/admin_ui.php';
if (is_file($murTextAdminUi)) {
    require_once $projectRoot . '/root/murcms/plugins/murtext/src/version.php';
    require_once $projectRoot . '/root/murcms/plugins/murtext/src/config_store.php';
    require_once $murTextAdminUi;
    $murTextPluginPath = $projectRoot . '/root/murcms/plugins/murtext';
    $murTextAdmin = new \MurCMS\MurTextPlugin\AdminUi(
        $adminPluginContext,
        $murTextPluginPath,
        new \MurCMS\MurTextPlugin\ConfigStore($adminPluginContext, $murTextPluginPath),
    );
    $murTextEditorTools = $murTextAdmin->editorTools('', ['editor' => 'document', 'textarea' => 'body']);
    $check(!str_contains($murTextEditorTools, '<form method="dialog">'), 'MurText editor help dialog must not nest a form inside editor forms.');
    $check(str_contains($murTextEditorTools, 'data-murtext-close'), 'MurText editor help dialog is missing a non-form close control.');
}
$adminPlugins = new PluginManager($projectRoot . '/root/murcms/plugins', $config['plugins'], $adminPluginContext);
$adminPlugins->loadEnabled();
$adminRouter = new AdminRouter(
    $config['site'],
    $config['languages'],
    $config['admin'],
    $config['theme'],
    $adminRepo,
    $adminHooks,
    $adminPlugins,
    $adminSeo,
    $adminAuth,
    new AdminI18n($projectRoot . '/root/murcms/admin/lang', $config['admin']),
    new AdminRenderer($config['site'], new AdminI18n($projectRoot . '/root/murcms/admin/lang', $config['admin'])),
);
$check(in_array('murconsent', $adminPlugins->loaded(), true), 'MurConsent was not loaded by PluginManager.');
$check(in_array('banerina', $adminPlugins->loaded(), true), 'Banerina was not loaded by PluginManager.');
$check(\MurCMS\MurConsent\Plugin::VERSION === '1.0.3', 'The current MurConsent 1.0.3 plugin is not installed.');
$check(\MurCMS\BanerinaPlugin\BanerinaPlugin::VERSION === '0.1.1', 'The current Banerina 0.1.1 plugin is not installed.');
$check(\MurCMS\MurTextPlugin\Version::PLUGIN === '0.2.4', 'The current MurText 0.2.4 integration plugin is not installed.');
$check(
    is_file($projectRoot . '/root/murcms/assets/plugins/banerina/admin.css')
    && is_file($projectRoot . '/root/murcms/assets/plugins/banerina/admin.js'),
    'Banerina admin assets are missing from the public asset tree.',
);
$check(
    is_file($projectRoot . '/root/murcms/assets/plugins/murtext/admin.css')
    && is_file($projectRoot . '/root/murcms/assets/plugins/murtext/admin.js')
    && !is_dir($projectRoot . '/root/murcms/plugins/murtext/assets'),
    'MurText admin assets are missing from the public asset tree or duplicated inside the plugin package.',
);
$murConsentDefaultHooks = new Hooks();
$murConsentDefaultContext = new PluginContext(
    $murConsentDefaultHooks,
    $config['site'],
    $config['languages'],
    $projectRoot . '/root/data',
    $projectRoot . '/root/murcms/plugins',
    $adminRepo,
);
$murConsentDefaultPlugin = new \MurCMS\MurConsent\Plugin(
    $murConsentDefaultContext,
    $projectRoot . '/root/murcms/plugins/murconsent',
    $projectRoot . '/root/data/config/murconsent-smoke-missing.php',
    $projectRoot . '/root/murcms/plugins/murconsent/defaults.php',
    $projectRoot . '/root/data/config/privacy-consent-smoke-missing.php',
);
$murConsentDefaultPlugin->registerFrontend();
$check(
    !$murConsentDefaultHooks->has('layout.head')
    && !$murConsentDefaultHooks->has('layout.footer')
    && !$murConsentDefaultHooks->has('layout.body_end'),
    'Default MurConsent configuration changes public output before an optional service is configured.',
);
$navGroupsMethod = new ReflectionMethod(AdminRouter::class, 'navGroups');
$adminNavGroups = $navGroupsMethod->invoke($adminRouter, $adminUser);
$adminGroupLabels = array_map(static fn (array $group): string => (string) ($group['label'] ?? ''), $adminNavGroups);
$settingsGroupIndex = array_search('Настройки', $adminGroupLabels, true);
$templateGroupIndex = array_search('Шаблон', $adminGroupLabels, true);
$templateGroup = $templateGroupIndex !== false ? $adminNavGroups[$templateGroupIndex] : [];
$templateUrls = array_map(static fn (array $item): string => (string) ($item['url'] ?? ''), (array) ($templateGroup['items'] ?? []));
$check($settingsGroupIndex !== false && $templateGroupIndex !== false && $templateGroupIndex === $settingsGroupIndex + 1, 'Template navigation group is missing or is not placed after Settings.');
$check($templateUrls === ['/admin/template', '/admin/template/home', '/admin/template/footer'], 'Template navigation group routes are incorrect.');
$adminPageHtml = (new AdminRenderer($config['site'], new AdminI18n($projectRoot . '/root/murcms/admin/lang', $config['admin'])))->page(
    'Dashboard',
    '<p>Smoke</p>',
    $adminNavGroups,
    $adminUser,
    'dashboard',
);
$check(str_contains($adminPageHtml, 'class="admin-site-name"') && str_contains($adminPageHtml, mur_escape((string) $config['site']['name'])), 'Admin sidebar does not identify the current site.');
$check(str_contains($adminPageHtml, 'href="/" target="_blank" rel="noreferrer">Frontend</a>'), 'Admin sidebar does not expose the frontend link.');
$check(str_contains($adminPageHtml, 'class="admin-brand-icon"') && str_contains($adminPageHtml, '/murcms/assets/site-icon.svg'), 'Admin sidebar icon is missing.');
$check(str_contains($adminPageHtml, mur_favicon_link($config['site'], 'admin')), 'Admin favicon resolver is not used by the admin renderer.');
$check(str_contains($adminPageHtml, '/murcms/assets/admin-theme.js?v='), 'Shared admin interactions are not loaded outside template pages.');
$siteSettingsMethod = new ReflectionMethod(AdminRouter::class, 'siteSettings');
$siteSettingsHtml = (string) $siteSettingsMethod->invoke($adminRouter, 'GET', $adminUser);
$frontendSettingsHtml = (string) (new ReflectionMethod(AdminRouter::class, 'languageSettings'))->invoke($adminRouter, 'GET', $adminUser);
$adminSettingsHtml = (string) (new ReflectionMethod(AdminRouter::class, 'adminSettings'))->invoke($adminRouter, 'GET', $adminUser);
$check(str_contains($frontendSettingsHtml, 'name="favicon_frontend"') && str_contains($adminSettingsHtml, 'name="favicon_admin"') && !str_contains($siteSettingsHtml, 'name="favicon_'), 'Favicon controls are not located in frontend/admin settings.');
$check(str_contains($frontendSettingsHtml, 'favicon*') && str_contains($frontendSettingsHtml, 'PNG/SVG/WebP'), 'Frontend settings do not explain favicon discovery.');
$dashboardMethod = new ReflectionMethod(AdminRouter::class, 'dashboard');
$dashboardHtml = (string) $dashboardMethod->invoke($adminRouter);
foreach (['pages', 'home-blocks', 'footer-blocks', 'sitemap-urls', 'plugins', 'cms-version'] as $dashboardStat) {
    $check(str_contains($dashboardHtml, 'data-dashboard-stat="' . $dashboardStat . '"'), "Dashboard statistic '{$dashboardStat}' is missing.");
}
$check(str_contains($dashboardHtml, '<strong>' . mur_escape(MUR_CMS_VERSION) . '</strong><span>Версия MurCMS</span>'), 'Dashboard CMS version card has an incorrect value or label.');
$check(!str_contains($dashboardHtml, 'published documents') && !str_contains($dashboardHtml, 'Текущий этап'), 'Dashboard still contains the obsolete status panels.');
$navGroupsMethod = new ReflectionMethod(AdminRouter::class, 'navGroups');
$requiredRoleMethod = new ReflectionMethod(AdminRouter::class, 'requiredRole');
$editorNav = $navGroupsMethod->invoke($adminRouter, ['id' => 'editor-smoke', 'role' => 'editor']);
$editorNavHtml = json_encode($editorNav, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
foreach (['/admin/content/pages', '/admin/content/categories', '/admin/media', '/admin/plugin/blocks', '/admin/plugin/gallery'] as $editorRoute) {
    $check(str_contains($editorNavHtml, $editorRoute), "Editor navigation is missing {$editorRoute}.");
}
$check(
    !str_contains($editorNavHtml, '/admin/content/snippets')
    && !str_contains($editorNavHtml, '/admin/settings/users')
    && !str_contains($editorNavHtml, '/admin/seo')
    && !str_contains($editorNavHtml, '/admin/template')
    && !str_contains($editorNavHtml, '/admin/plugin/banerina')
    && !str_contains($editorNavHtml, '/admin/plugin/murconsent'),
    'Editor navigation exposes privileged routes.',
);
$adminNavHtml = json_encode($navGroupsMethod->invoke($adminRouter, $adminUser), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
$check(str_contains($adminNavHtml, '/admin/settings/users'), 'Admin navigation does not expose user management.');
$check(str_contains($adminNavHtml, '/admin/plugin/banerina'), 'Admin navigation does not expose Banerina.');
$check(str_contains($adminNavHtml, '/admin/plugin/murconsent'), 'Admin navigation does not expose MurConsent.');
$check(
    $requiredRoleMethod->invoke($adminRouter, 'content/pages') === 'editor'
    && $requiredRoleMethod->invoke($adminRouter, 'settings/profile') === 'editor'
    && $requiredRoleMethod->invoke($adminRouter, 'settings/users') === 'admin',
    'Admin route role requirements are incorrect.',
);

$superUser = array_replace($configuredSuperAdmins[0], ['csrf' => $adminUser['csrf']]);
$pluginRouteDefinitionMethod = new ReflectionMethod(AdminRouter::class, 'pluginRouteDefinition');
$bundledAdminRouteRoles = [
    'plugin/blocks' => 'editor',
    'plugin/gallery' => 'editor',
    'plugin/tags' => 'editor',
    'plugin/redirects' => 'admin',
    'plugin/banerina' => 'admin',
    'plugin/murconsent' => 'admin',
    'plugin/murtext' => 'admin',
];
foreach ($bundledAdminRouteRoles as $pluginRoute => $expectedRole) {
    $definition = $pluginRouteDefinitionMethod->invoke($adminRouter, $pluginRoute);
    $check(
        is_array($definition) && ($definition['role'] ?? '') === $expectedRole,
        "Bundled admin route '{$pluginRoute}' does not declare role '{$expectedRole}'.",
    );
}

$dispatchMethod = new ReflectionMethod(AdminRouter::class, 'dispatch');
$editorUser = ['id' => 'editor-route-smoke', 'role' => 'editor', 'csrf' => $adminUser['csrf']];
$redirectsDenied = (string) $dispatchMethod->invoke($adminRouter, 'plugin/redirects', $editorUser, 'GET');
$check(
    str_contains($redirectsDenied, 'Доступ запрещен') && !str_contains($redirectsDenied, 'name="rules"'),
    'Editor can read the Redirects admin route through a direct URL.',
);
$check(
    str_contains((string) $dispatchMethod->invoke($adminRouter, 'plugin/redirects', $adminUser, 'GET'), 'name="rules"')
    && str_contains((string) $dispatchMethod->invoke($adminRouter, 'plugin/redirects', $superUser, 'GET'), 'name="rules"'),
    'Admin or super-admin cannot open the Redirects admin route.',
);
$banerinaDenied = (string) $dispatchMethod->invoke($adminRouter, 'plugin/banerina', $editorUser, 'GET');
$check(
    str_contains($banerinaDenied, 'Доступ запрещен') && !str_contains($banerinaDenied, 'banerina-admin-head'),
    'Editor can read the Banerina admin route through a direct URL.',
);
$check(
    str_contains((string) $dispatchMethod->invoke($adminRouter, 'plugin/banerina', $adminUser, 'GET'), 'banerina-admin-head')
    && str_contains((string) $dispatchMethod->invoke($adminRouter, 'plugin/banerina', $superUser, 'GET'), 'banerina-admin-head'),
    'Admin or super-admin cannot open the Banerina admin route.',
);

$routeAuthHooks = new Hooks();
$routeAuthContext = new PluginContext(
    $routeAuthHooks,
    $config['site'],
    $config['languages'],
    $projectRoot . '/root/data',
    $projectRoot . '/root/murcms/plugins',
    $adminRepo,
);
$protectedHandlerCalls = [];
$routeAuthContext->registerAdminRoute([
    'path' => '/admin/plugin/protected-smoke',
    'role' => 'admin',
    'handler' => static function (mixed $html, array $context) use (&$protectedHandlerCalls): string {
        $protectedHandlerCalls[] = (string) ($context['method'] ?? '') . ':' . (string) (($context['user']['role'] ?? ''));
        return '<section data-protected-route>protected</section>';
    },
]);
$routeAuthContext->registerAdminRoute([
    'path' => '/admin/plugin/implicit-editor-smoke',
    'handler' => static fn (): string => '<section data-implicit-editor-route>implicit</section>',
]);
$routeAuthHooks->add('admin.route', static function (mixed $html, array $context): mixed {
    return ($context['route'] ?? '') === 'plugin/legacy-smoke'
        ? '<section data-legacy-route>legacy</section>'
        : $html;
});
$routeAuthPlugins = new PluginManager($projectRoot . '/root/murcms/plugins', ['enabled' => []], $routeAuthContext);
$routeAuthI18n = new AdminI18n($projectRoot . '/root/murcms/admin/lang', $config['admin']);
$routeAuthRouter = new AdminRouter(
    $config['site'],
    $config['languages'],
    $config['admin'],
    $config['theme'],
    $adminRepo,
    $routeAuthHooks,
    $routeAuthPlugins,
    new SeoManager($config['site'], $config['languages'], $config['seo'], $adminRepo, $routeAuthHooks),
    $adminAuth,
    $routeAuthI18n,
    new AdminRenderer($config['site'], $routeAuthI18n),
);
foreach (['GET', 'POST', 'DELETE'] as $deniedMethod) {
    $deniedPage = (string) $dispatchMethod->invoke($routeAuthRouter, 'plugin/protected-smoke', $editorUser, $deniedMethod);
    $check(
        str_contains($deniedPage, 'Доступ запрещен') && !str_contains($deniedPage, 'data-protected-route'),
        "Editor reached a protected plugin handler through {$deniedMethod}.",
    );
}
$check($protectedHandlerCalls === [], 'Protected plugin handler ran before the core role check.');
$adminProtectedPage = (string) $dispatchMethod->invoke($routeAuthRouter, 'plugin/protected-smoke', $adminUser, 'PATCH');
$superProtectedPage = (string) $dispatchMethod->invoke($routeAuthRouter, 'plugin/protected-smoke', $superUser, 'PUT');
$check(
    str_contains($adminProtectedPage, 'data-protected-route')
    && str_contains($superProtectedPage, 'data-protected-route')
    && $protectedHandlerCalls === ['PATCH:admin', 'PUT:super_admin'],
    'Admin route handler access does not follow the admin/super-admin hierarchy or preserve the HTTP method.',
);
$check(
    str_contains((string) $dispatchMethod->invoke($routeAuthRouter, 'plugin/implicit-editor-smoke', $editorUser, 'GET'), 'data-implicit-editor-route')
    && str_contains((string) $dispatchMethod->invoke($routeAuthRouter, 'plugin/legacy-smoke', $editorUser, 'GET'), 'data-legacy-route'),
    'Plugin routes without an explicit role no longer use the legacy editor fallback.',
);

$usersPageMethod = new ReflectionMethod(AdminRouter::class, 'usersPage');
$usersPageHtml = (string) $usersPageMethod->invoke($adminRouter, $superUser);
$check(str_contains($usersPageHtml, 'Пользователи админ-панели') && str_contains($usersPageHtml, 'Супер-администратор'), 'Users page does not render the current super-admin.');
$check(str_contains($usersPageHtml, '/admin/settings/users/edit') && str_contains($usersPageHtml, 'Права редактора'), 'Users page is missing creation controls or the editor permission summary.');

$previousUserEditorGet = $_GET;
try {
    $_GET = [];
    $userEditorMethod = new ReflectionMethod(AdminRouter::class, 'userEditor');
    $adminCreateUserHtml = (string) $userEditorMethod->invoke($adminRouter, 'GET', $adminUser);
    $check(
        str_contains($adminCreateUserHtml, '<option value="editor" selected>Редактор</option>')
        && !str_contains($adminCreateUserHtml, '<option value="admin"'),
        'A regular admin can choose a privileged role while creating a user.',
    );
    $superCreateUserHtml = (string) $userEditorMethod->invoke($adminRouter, 'GET', $superUser);
    $check(
        str_contains($superCreateUserHtml, '<option value="admin" selected>Администратор</option>')
        && str_contains($superCreateUserHtml, '<option value="editor">Редактор</option>'),
        'Super-admin cannot choose admin and editor roles.',
    );
} finally {
    $_GET = $previousUserEditorGet;
}

$profileSettingsMethod = new ReflectionMethod(AdminRouter::class, 'profileSettings');
$profileSettingsHtml = (string) $profileSettingsMethod->invoke($adminRouter, 'GET', $superUser);
$check(
    str_contains($profileSettingsHtml, 'name="display_name"')
    && str_contains($profileSettingsHtml, 'name="email" type="email"')
    && str_contains($profileSettingsHtml, 'name="phone" type="tel"')
    && str_contains($profileSettingsHtml, 'Супер-администратор'),
    'Admin profile does not expose contact fields and the role.',
);
$documentsTableMethod = new ReflectionMethod(AdminRouter::class, 'documentsTable');
$documentsTableHtml = (string) $documentsTableMethod->invoke($adminRouter, 'pages', 'GET', $adminUser);
$check(str_contains($documentsTableHtml, 'class="admin-table admin-documents-table"'), 'Document lists do not use the compact table layout.');
$check(str_contains($documentsTableHtml, 'data-language="ru"') && !str_contains($documentsTableHtml, 'data-language="ro"'), 'Pages list does not default to the primary language.');
$check(str_contains($documentsTableHtml, 'class="admin-document-cell"') && str_contains($documentsTableHtml, 'class="admin-document-id"') && str_contains($documentsTableHtml, 'class="admin-document-url"'), 'Document title, ID and URL were not combined into one cell.');
$check(str_contains($documentsTableHtml, '<span class="visually-hidden">Выбор</span></th><th>Страница</th><th>Изменено</th><th>Статус</th><th>Действия</th>'), 'Document list columns are incorrect.');
$check(str_contains($documentsTableHtml, 'name="selected[]" value="ru:') && str_contains($documentsTableHtml, 'aria-label="Выбрать страницу'), 'Pages list row checkboxes are missing.');
$check(str_contains($documentsTableHtml, 'class="admin-form admin-page-filters"') && str_contains($documentsTableHtml, 'name="filter"') && str_contains($documentsTableHtml, 'name="status"') && str_contains($documentsTableHtml, 'name="per_page"'), 'Pages list filter and page-size controls are missing.');
$check(str_contains($documentsTableHtml, '<option value="" selected>RU — основной язык</option>') && str_contains($documentsTableHtml, '<option value="all">Все языки (без пакетных действий)</option>') && str_contains($documentsTableHtml, 'value="category:ru:company"') && str_contains($documentsTableHtml, 'value="parent:ru:services"'), 'Pages list language or structural filter options are incomplete.');
$check(str_contains($documentsTableHtml, 'data-selection-action="all"') && str_contains($documentsTableHtml, 'data-selection-action="invert"') && str_contains($documentsTableHtml, 'data-selection-count'), 'Pages bulk selection controls are missing.');
$check(str_contains($documentsTableHtml, 'formaction="/admin/content/pages/bulk/download"') && str_contains($documentsTableHtml, 'name="bulk_status"'), 'Pages bulk download or status control is missing.');
$check(str_contains($documentsTableHtml, 'id="page-bulk-parent-dialog"') && str_contains($documentsTableHtml, 'id="page-bulk-category-dialog"'), 'Pages bulk parent or category dialog is missing.');
$check(str_contains($documentsTableHtml, 'type="checkbox" name="bulk_categories[]" value="company"') && !str_contains($documentsTableHtml, 'type="radio" name="bulk_category"'), 'Pages bulk categories are not a multiple checkbox selection.');
$check(strpos($documentsTableHtml, 'value="delete" data-selection-requires>Удалить</button>') > strpos($documentsTableHtml, 'data-dialog-open="page-bulk-category-dialog"'), 'Pages bulk delete is not the final operation.');
$categoriesTableHtml = (string) $documentsTableMethod->invoke($adminRouter, 'categories', 'GET', $adminUser);
$check(!str_contains($categoriesTableHtml, 'admin-page-filters'), 'Pages filter panel leaked into the categories list.');
$check(!str_contains($categoriesTableHtml, 'name="selected[]"'), 'Pages row checkboxes leaked into the categories list.');
preg_match('~<tr data-document-id="company-news">(.*?)</tr>~s', $categoriesTableHtml, $nestedCategoryRow);
$check(
    preg_match('~<td class="admin-document-cell"><div class="admin-document-content admin-tree-depth-1">.*?Новости компании.*?class="admin-document-id".*?company-news.*?class="admin-document-url".*?/ru/category/company/news.*?</div></td><td class="admin-document-status">~s', (string) ($nestedCategoryRow[1] ?? '')) === 1,
    'Category hierarchy depth is not applied to the complete informational block.',
);
$hierarchyRowsMethod = new ReflectionMethod(AdminRouter::class, 'hierarchyRows');
$hierarchyDocuments = [
    new Document('categories', 'ru', '', 'root.mur.md', ['id' => 'root', 'title' => 'Root', 'status' => 'published'], ''),
    new Document('categories', 'ru', '', 'level-1.mur.md', ['id' => 'level-1', 'title' => 'Level 1', 'parent' => 'root', 'status' => 'published'], ''),
    new Document('categories', 'ru', '', 'level-2.mur.md', ['id' => 'level-2', 'title' => 'Level 2', 'parent' => 'level-1', 'status' => 'published'], ''),
    new Document('categories', 'ru', '', 'level-3.mur.md', ['id' => 'level-3', 'title' => 'Level 3', 'parent' => 'level-2', 'status' => 'published'], ''),
];
$hierarchyDepths = [];
foreach ((array) $hierarchyRowsMethod->invoke($adminRouter, $hierarchyDocuments) as [$hierarchyDocument, $hierarchyDepth]) {
    $hierarchyDepths[$hierarchyDocument->id()] = $hierarchyDepth;
}
$check($hierarchyDepths === ['root' => 0, 'level-1' => 1, 'level-2' => 2, 'level-3' => 3], 'Existing category hierarchy calculation no longer preserves levels zero through three.');
$pageMatchesListFilter = new ReflectionMethod(AdminRouter::class, 'pageMatchesListFilter');
$filterSample = new Document('pages', 'ru', '', 'filter-sample.mur.md', [
    'id' => 'filter-sample',
    'status' => 'draft',
    'parent' => 'services',
    'categories' => 'company, company-news',
], '');
$check(
    $pageMatchesListFilter->invoke($adminRouter, $filterSample, ['type' => 'category', 'value' => 'company', 'status' => 'draft'])
    && !$pageMatchesListFilter->invoke($adminRouter, $filterSample, ['type' => 'category', 'value' => 'company', 'status' => 'published']),
    'Pages list category/status matching is incorrect.',
);
$previousPageListGet = $_GET;
try {
    $_GET = ['filter' => 'language:ro'];
    $filteredByLanguageHtml = (string) $documentsTableMethod->invoke($adminRouter, 'pages', 'GET', $adminUser);
    $check(str_contains($filteredByLanguageHtml, 'data-language="ro"') && !str_contains($filteredByLanguageHtml, 'data-language="ru"'), 'Pages list language filter is incorrect.');

    $_GET = ['filter' => 'all'];
    $allLanguagesHtml = (string) $documentsTableMethod->invoke($adminRouter, 'pages', 'GET', $adminUser);
    $check(str_contains($allLanguagesHtml, 'data-language="ru"') && str_contains($allLanguagesHtml, 'data-language="ro"'), 'All-languages pages mode does not show every language.');
    $check(!str_contains($allLanguagesHtml, 'name="selected[]"') && !str_contains($allLanguagesHtml, 'class="admin-bulk-form"') && str_contains($allLanguagesHtml, 'Режим всех языков предназначен для просмотра.'), 'All-languages pages mode still exposes bulk selection.');

    $_GET = ['filter' => 'parent:ru:services', 'status' => 'published'];
    $filteredByParentHtml = (string) $documentsTableMethod->invoke($adminRouter, 'pages', 'GET', $adminUser);
    $check(str_contains($filteredByParentHtml, 'data-document-id="service-sites"') && str_contains($filteredByParentHtml, 'data-document-id="service-seo"') && !str_contains($filteredByParentHtml, 'data-document-id="about"'), 'Pages list parent/status filter is incorrect.');

    $_GET = ['filter' => 'parent-root:ru', 'status' => 'draft'];
    $filteredDraftHtml = (string) $documentsTableMethod->invoke($adminRouter, 'pages', 'GET', $adminUser);
    $check(str_contains($filteredDraftHtml, 'Страницы по выбранному фильтру не найдены.'), 'Pages list empty filtered state is missing.');

    $_GET = ['filter' => 'all', 'per_page' => '10', 'page' => '2'];
    $secondPageHtml = (string) $documentsTableMethod->invoke($adminRouter, 'pages', 'GET', $adminUser);
    $check(substr_count($secondPageHtml, 'data-document-id="') === 10, 'Pages list does not limit the global second page to ten rows.');
    $check(str_contains($secondPageHtml, 'Показано: 11–20 из 24'), 'Pages list pagination summary is incorrect.');
    $check(substr_count($secondPageHtml, 'class="admin-pagination ') === 2 && str_contains($secondPageHtml, 'aria-current="page">2</span>'), 'Pages list top and bottom pagination are missing.');

    $_GET = ['filter' => 'language:ro', 'per_page' => '10'];
    $filteredPaginationHtml = (string) $documentsTableMethod->invoke($adminRouter, 'pages', 'GET', $adminUser);
    $check(substr_count($filteredPaginationHtml, 'data-document-id="') === 10 && str_contains($filteredPaginationHtml, 'data-language="ro"') && !str_contains($filteredPaginationHtml, 'data-language="ru"'), 'Filtered pages pagination is incorrect.');
    $check(str_contains($filteredPaginationHtml, 'href="/admin/content/pages?filter=language%3Aro&amp;per_page=10&amp;page=2"'), 'Pages pagination does not preserve the structural filter and page size.');
    $check(str_contains($filteredPaginationHtml, 'href="/admin/content/pages?per_page=10">Сбросить</a>'), 'Pages filter reset does not preserve the page size.');

    $_GET = ['filter' => 'all', 'per_page' => '10', 'page' => '99'];
    $clampedPageHtml = (string) $documentsTableMethod->invoke($adminRouter, 'pages', 'GET', $adminUser);
    $check(substr_count($clampedPageHtml, 'data-document-id="') === 4 && str_contains($clampedPageHtml, 'aria-current="page">3</span>'), 'Pages list does not clamp an out-of-range page to the final page.');
} finally {
    $_GET = $previousPageListGet;
}
$previousAdminGet = $_GET;
try {
    $_GET = ['lang' => 'ru'];
    $documentEditorMethod = new ReflectionMethod(AdminRouter::class, 'documentEditor');
    $documentEditorHtml = (string) $documentEditorMethod->invoke($adminRouter, 'pages', 'GET', $adminUser);
    $check(str_contains($documentEditorHtml, 'data-smoke-editor="document" data-smoke-textarea="body"'), 'admin.editor_tools is missing from the document editor.');
    $check(str_contains($documentEditorHtml, 'type="checkbox" name="categories[]"'), 'Document editor does not allow multiple categories.');

    $fileCollectionMethod = new ReflectionMethod(AdminRouter::class, 'fileCollection');
    $fileEditorMethod = new ReflectionMethod(AdminRouter::class, 'managedFileEditor');
    $_GET = ['file' => 'content/ru/home/hero.mur.md'];
    $homeCollection = $fileCollectionMethod->invoke($adminRouter, 'home');
    $fileEditorHtml = (string) $fileEditorMethod->invoke($adminRouter, $homeCollection, 'GET', $adminUser);
    $check(str_contains($fileEditorHtml, 'data-smoke-editor="file" data-smoke-textarea="content"'), 'admin.editor_tools is missing from the file editor.');
    $check(str_contains($fileEditorHtml, 'action="/admin/files/edit?scope=home&amp;file=content%2Fru%2Fhome%2Fhero.mur.md"'), 'File editor form action does not preserve the edited file path and scope.');
    $check(!str_contains($fileEditorHtml, 'Формат logos:'), 'Generic content block editor showed a logos-specific syntax hint.');

    $_GET = ['file' => 'blocks/ru/logos/partners.mur.md'];
    $blocksCollection = $fileCollectionMethod->invoke($adminRouter, 'blocks');
    $logosEditorHtml = (string) $fileEditorMethod->invoke($adminRouter, $blocksCollection, 'GET', $adminUser);
    $check(str_contains($logosEditorHtml, 'logos — <code>image | alt | url | width | height | link_title | target</code>'), 'Blocks plugin editor is missing its pipe syntax hint.');

    $_GET = ['file' => 'menus/ru/main.mur.md'];
    $menuEditorMethod = new ReflectionMethod(AdminRouter::class, 'genericFileEditor');
    $menuEditorHtml = (string) $menuEditorMethod->invoke($adminRouter, 'GET', $adminUser, 'menus');
    $check(str_contains($menuEditorHtml, 'href | короткий label | длинный label | title | li_class | span_class | attrs'), 'Menu editor is missing its responsive-label syntax hint.');
    $check(str_contains($menuEditorHtml, '<code>[</code> перед родителем'), 'Menu editor describes the submenu opening bracket in the wrong position.');

    $_GET = ['lang' => 'ru'];
    $snippetEditorMethod = new ReflectionMethod(AdminRouter::class, 'snippetsEditor');
    $snippetEditorHtml = (string) $snippetEditorMethod->invoke($adminRouter, 'GET', $adminUser);
    $check(str_contains($snippetEditorHtml, 'data-smoke-editor="snippet" data-smoke-textarea="body"'), 'admin.editor_tools is missing from the snippet editor.');
    $_GET = ['tab' => 'system'];
    $snippetEditorHtml = (string) $snippetEditorMethod->invoke($adminRouter, 'GET', $adminUser);
    $check(substr_count($snippetEditorHtml, 'data-system-snippet=') === 11 && str_contains($snippetEditorHtml, 'aria-label="Область системных snippets"'), 'System snippet registry is not scope-aware or does not contain 11 positions.');
    foreach (['system-header-before', 'system-header-after', 'system-main-before', 'system-main-after'] as $systemSnippetName) {
        $check(str_contains($snippetEditorHtml, 'data-system-snippet="' . $systemSnippetName . '"'), "System snippet registry is missing {$systemSnippetName}.");
    }
    $systemSnippetDescriptions = new ReflectionMethod(AdminRouter::class, 'systemSnippetDescriptions');
    $check(count((array) $systemSnippetDescriptions->invoke($adminRouter)) === 11, 'System snippet description registry does not contain exactly 11 positions.');

    $registryGlobalFile = mur_path('snippets/system-main-after.mur.md');
    $registryLocalFile = mur_path('snippets/ro/system-main-after.mur.md');
    $registryGlobalOriginal = is_file($registryGlobalFile) ? file_get_contents($registryGlobalFile) : null;
    $registryLocalOriginal = is_file($registryLocalFile) ? file_get_contents($registryLocalFile) : null;
    try {
        file_put_contents($registryGlobalFile, "id: system-main-after\nstatus: published\ntype: html\n---\n\nregistry fallback\n", LOCK_EX);
        if (is_file($registryLocalFile)) {
            unlink($registryLocalFile);
        }
        $_GET = ['tab' => 'system', 'system_scope' => 'ro'];
        $fallbackRegistryHtml = (string) $snippetEditorMethod->invoke($adminRouter, 'GET', $adminUser);
        $check(str_contains($fallbackRegistryHtml, 'Fallback global:') && str_contains($fallbackRegistryHtml, 'Создать переопределение'), 'System snippet registry does not distinguish fallback from an exact scope file.');
        $check(str_contains($fallbackRegistryHtml, 'lang=ro&amp;name=system-main-after'), 'System snippet override action does not prefill the selected scope and name.');

        file_put_contents($registryLocalFile, "id: system-main-after\nstatus: draft\ntype: html\n---\n\nlocal draft\n", LOCK_EX);
        $exactRegistryHtml = (string) $snippetEditorMethod->invoke($adminRouter, 'GET', $adminUser);
        $check(str_contains($exactRegistryHtml, 'Файл существует · Черновик') && str_contains($exactRegistryHtml, 'Редактировать'), 'System snippet registry does not report an exact draft file correctly.');
    } finally {
        if ($registryGlobalOriginal === null) {
            if (is_file($registryGlobalFile)) unlink($registryGlobalFile);
        } else {
            file_put_contents($registryGlobalFile, $registryGlobalOriginal, LOCK_EX);
        }
        if ($registryLocalOriginal === null) {
            if (is_file($registryLocalFile)) unlink($registryLocalFile);
        } else {
            file_put_contents($registryLocalFile, $registryLocalOriginal, LOCK_EX);
        }
    }
} finally {
    $_GET = $previousAdminGet;
}
$themeSettings = new ReflectionMethod(AdminRouter::class, 'themeSettings');
$themeGeneralHtml = (string) $themeSettings->invoke($adminRouter, 'GET', $adminUser, 'general');
$themeHomeHtml = (string) $themeSettings->invoke($adminRouter, 'GET', $adminUser, 'home');
$themeFooterHtml = (string) $themeSettings->invoke($adminRouter, 'GET', $adminUser, 'footer');
$check(str_contains($themeGeneralHtml, 'value="memorial-gold"'), 'Admin did not discover the memorial-gold color scheme.');
$check(str_contains($themeGeneralHtml, 'value="memorial-elegant"'), 'Admin did not discover the memorial-elegant color scheme.');
$check(str_contains($themeGeneralHtml, 'name="brand_mode"'), 'Theme settings do not expose the header brand mode.');
$check(str_contains($themeGeneralHtml, 'name="brand_html"'), 'Theme settings do not expose header brand HTML.');
$check(str_contains($themeGeneralHtml, mur_escape(mur_default_brand_html())), 'Theme settings do not expose the default MurCMS HTML brand.');
$check(str_contains($themeGeneralHtml, 'name="brand_image"'), 'Theme settings do not expose the header brand image path.');
$check(str_contains($themeGeneralHtml, 'name="brand_image_height"'), 'Theme settings do not expose the header brand image height.');
$check(
    str_contains($themeGeneralHtml, 'name="theme_action" value="custom_css"')
    && str_contains($themeGeneralHtml, 'name="critical_css"')
    && str_contains($themeGeneralHtml, 'name="additional_css"')
    && str_contains($themeGeneralHtml, 'admin-custom-css-textarea'),
    'Template General does not expose a separate custom CSS form.',
);
$check(str_contains($themeGeneralHtml, '<h2>Меню</h2>') && !str_contains($themeGeneralHtml, 'name="block_ids['), 'General template page does not isolate settings and menus.');
$customCssNonce = bin2hex(random_bytes(6));
$customCssTemplate = 'smoke-css-' . $customCssNonce;
$customCssTemplateDir = $projectRoot . '/root/murcms/templates/' . $customCssTemplate;
$customCssDir = mur_uploads_path('murcms-custom/' . $customCssTemplate);
$customCriticalFile = $customCssDir . '/critical.css';
$customAdditionalFile = $customCssDir . '/custom.css';
$previousCustomCssPost = $_POST;
try {
    mkdir($customCssTemplateDir, 0775, true);
    file_put_contents($customCssTemplateDir . '/template.php', "<?php\n", LOCK_EX);
    $saveThemeCustomCss = new ReflectionMethod(AdminRouter::class, 'saveThemeCustomCss');
    $readThemeCustomCss = new ReflectionMethod(AdminRouter::class, 'themeCustomCss');
    $themeConfigBeforeCustomCss = file_get_contents(mur_path('config/theme.php'));

    $_POST = [
        '_csrf' => $adminAuth->csrfToken(),
        'template' => $customCssTemplate,
        'critical_css' => '',
        'additional_css' => '',
    ];
    $saveThemeCustomCss->invoke($adminRouter, $adminUser);
    $check(!is_dir($customCssDir), 'Empty custom CSS save created a placeholder directory.');

    $criticalCss = "body { color: red; }\r\n/* </StYlE><script>unsafe()</script> */\r\n";
    $additionalCss = ".card { border: 0; }\n";
    $_POST['critical_css'] = $criticalCss;
    $_POST['additional_css'] = $additionalCss;
    $customCssNotice = (string) $saveThemeCustomCss->invoke($adminRouter, $adminUser);
    $check(str_contains($customCssNotice, 'сохранены') && file_get_contents($customCriticalFile) === $criticalCss && file_get_contents($customAdditionalFile) === $additionalCss, 'Custom CSS was not saved exactly to template-specific files.');
    $reopenedCustomCss = (array) $readThemeCustomCss->invoke($adminRouter, $customCssTemplate);
    $check(($reopenedCustomCss['critical'] ?? null) === $criticalCss && ($reopenedCustomCss['additional'] ?? null) === $additionalCss, 'Saved custom CSS was not reopened unchanged for the textareas.');
    $check(file_get_contents(mur_path('config/theme.php')) === $themeConfigBeforeCustomCss, 'Saving custom CSS modified general theme configuration.');
    $check(
        mur_template_custom_css_path($customCssTemplate, 'critical.css') !== mur_template_custom_css_path('default', 'critical.css'),
        'Custom CSS paths are not isolated by frontend template.',
    );

    $customHeadHooks = new Hooks();
    $customHeadHooks->add('layout.head', static fn (string $html): string => $html . '<link data-smoke-plugin-style>');
    $customTemplateContext = new \Mur\Core\TemplateContext(
        $customCssTemplate,
        $customCssTemplateDir,
        ['options' => []],
        $config['site'],
        $config['languages'],
        $adminRepo,
        new Menu($projectRoot . '/root/data/menus', $adminRepo),
        $customHeadHooks,
    );
    $customTemplate = new \Mur\Templates\DefaultTemplate($customTemplateContext);
    $customCssHtml = $customTemplate->renderLayout('ru', null, '<p>Custom CSS smoke</p>', ['title' => 'CSS', 'description' => 'CSS']);
    $pluginCssPosition = strpos($customCssHtml, 'data-smoke-plugin-style');
    $criticalCssPosition = strpos($customCssHtml, 'data-murcms-custom-critical');
    $additionalCssPosition = strpos($customCssHtml, 'data-murcms-custom-css');
    $check($pluginCssPosition !== false && $criticalCssPosition !== false && $additionalCssPosition !== false && $pluginCssPosition < $criticalCssPosition && $criticalCssPosition < $additionalCssPosition, 'Custom CSS priority in head is not plugin assets -> Critical -> Additional.');
    $check(str_contains($customCssHtml, '<\\/style><script>') && !str_contains($customCssHtml, '</StYlE><script>'), 'Literal closing style sequence was not neutralized in custom Critical CSS.');
    $check(!str_contains($customCssHtml, 'href="/uploads/murcms-custom/' . $customCssTemplate . '/critical.css') && preg_match('~href="/uploads/murcms-custom/' . preg_quote($customCssTemplate, '~') . '/custom\.css\?v=\d+"~', $customCssHtml) === 1, 'Custom Critical CSS caused a request or Additional CSS lacks cache busting.');
    $check(!str_contains($customCssHtml, 'media="print"'), 'Custom CSS uses a delayed media=print loading pattern.');

    $savedCritical = file_get_contents($customCriticalFile);
    $savedAdditional = file_get_contents($customAdditionalFile);
    $_POST['_csrf'] = 'invalid';
    $_POST['critical_css'] = 'changed';
    $saveThemeCustomCss->invoke($adminRouter, $adminUser);
    $check(file_get_contents($customCriticalFile) === $savedCritical, 'Invalid CSRF changed custom CSS.');

    $_POST['_csrf'] = $adminAuth->csrfToken();
    $saveThemeCustomCss->invoke($adminRouter, ['id' => 'smoke-editor', 'role' => 'editor', 'csrf' => $adminAuth->csrfToken()]);
    $check(file_get_contents($customCriticalFile) === $savedCritical, 'Editor role changed admin-only custom CSS.');

    $_POST['critical_css'] = str_repeat('a', 65537);
    $_POST['additional_css'] = 'changed';
    $limitNotice = (string) $saveThemeCustomCss->invoke($adminRouter, $adminUser);
    $check(str_contains($limitNotice, '64 KB') && file_get_contents($customCriticalFile) === $savedCritical && file_get_contents($customAdditionalFile) === $savedAdditional, 'Critical CSS size limit did not preserve existing files.');

    $_POST['critical_css'] = (string) $savedCritical;
    $_POST['additional_css'] = str_repeat('b', 262145);
    $additionalLimitNotice = (string) $saveThemeCustomCss->invoke($adminRouter, $adminUser);
    $check(str_contains($additionalLimitNotice, '256 KB') && file_get_contents($customCriticalFile) === $savedCritical && file_get_contents($customAdditionalFile) === $savedAdditional, 'Additional CSS size limit did not preserve existing files.');

    $_POST['template'] = '../' . $customCssTemplate;
    $_POST['critical_css'] = 'unsafe';
    $unsafeNotice = (string) $saveThemeCustomCss->invoke($adminRouter, $adminUser);
    $check(str_contains($unsafeNotice, 'Недопустимое имя') && file_get_contents($customCriticalFile) === $savedCritical, 'Custom CSS accepted an unsafe template path.');

    $_POST['template'] = $customCssTemplate;
    $_POST['critical_css'] = '';
    $_POST['additional_css'] = '';
    $saveThemeCustomCss->invoke($adminRouter, $adminUser);
    $check(!is_file($customCriticalFile) && !is_file($customAdditionalFile), 'Empty custom CSS fields did not delete existing CSS files.');
    $emptyCustomCssHtml = $customTemplate->renderLayout('ru', null, '<p>Empty CSS smoke</p>', ['title' => 'CSS', 'description' => 'CSS']);
    $check(!str_contains($emptyCustomCssHtml, 'data-murcms-custom-critical') && !str_contains($emptyCustomCssHtml, 'data-murcms-custom-css'), 'Deleted custom CSS is still emitted on frontend.');
} finally {
    $_POST = $previousCustomCssPost;
    foreach ([$customCriticalFile, $customAdditionalFile, $customCssTemplateDir . '/template.php'] as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
    if (is_dir($customCssDir)) {
        rmdir($customCssDir);
    }
    if (is_dir($customCssTemplateDir)) {
        rmdir($customCssTemplateDir);
    }
    $customCssRoot = mur_uploads_path('murcms-custom');
    if (is_dir($customCssRoot) && (glob($customCssRoot . '/*', GLOB_NOSORT) ?: []) === []) {
        rmdir($customCssRoot);
    }
}
$check(str_contains($themeHomeHtml, 'name="block_ids[ru]"') && str_contains($themeHomeHtml, 'name="block_ids[ro]"'), 'Flexible home page does not expose per-language block layouts.');
$check(str_contains($themeHomeHtml, 'data-theme-block-create="home"') && str_contains($themeHomeHtml, 'action="/admin/template/home"') && str_contains($themeHomeHtml, 'name="block_folder" list="theme-block-folders" value="home"'), 'Home template page actions are incorrect.');
$check(str_contains($themeHomeHtml, 'data-theme-block-clone=') && !str_contains($themeHomeHtml, 'name="block_kind" value="footer"'), 'Home template page contains incorrect block controls.');
$check(str_contains($themeFooterHtml, 'name="block_kind" value="footer"') && str_contains($themeFooterHtml, 'action="/admin/template/footer"') && str_contains($themeFooterHtml, 'name="block_folder" list="theme-block-folders" value="footer"'), 'Footer template page actions are incorrect.');
$check(!str_contains($themeFooterHtml, 'name="brand_mode"') && !str_contains($themeFooterHtml, '<h2>Меню</h2>'), 'Footer template page leaked general template settings.');
$adminLanguagesProperty = new ReflectionProperty(AdminRouter::class, 'languages');
$originalAdminLanguages = $adminLanguagesProperty->getValue($adminRouter);
$mirrorAdminLanguages = $originalAdminLanguages;
$mirrorAdminLanguages['structure'] = 'mirror';
$adminLanguagesProperty->setValue($adminRouter, $mirrorAdminLanguages);
$mirrorThemeSettingsHtml = (string) $themeSettings->invoke($adminRouter, 'GET', $adminUser, 'home');
$check(str_contains($mirrorThemeSettingsHtml, 'name="block_ids[ru]"') && !str_contains($mirrorThemeSettingsHtml, 'name="block_ids[ro]"'), 'Mirror theme settings did not collapse block layouts to the default language.');
$adminLanguagesProperty->setValue($adminRouter, $originalAdminLanguages);
$documentDeleteConfirmed = new ReflectionMethod(AdminRouter::class, 'documentDeleteConfirmed');
$check(
    $documentDeleteConfirmed->invoke($adminRouter, 'Y') === true
    && $documentDeleteConfirmed->invoke($adminRouter, 'y') === true
    && $documentDeleteConfirmed->invoke($adminRouter, '1') === true,
    'Document deletion confirmation does not accept Y/y/1.',
);
$check($documentDeleteConfirmed->invoke($adminRouter, 'about') === false, 'Document deletion confirmation still accepts a document ID.');
$aboutDocument = $adminRepo->findById('ru', 'about');
if ($aboutDocument instanceof Document) {
    $deleteDocumentForm = new ReflectionMethod(AdminRouter::class, 'deleteDocumentForm');
    $deleteDocumentHtml = (string) $deleteDocumentForm->invoke($adminRouter, 'pages', $aboutDocument, $adminUser);
    $check(str_contains($deleteDocumentHtml, 'pattern="[Yy1]"') && str_contains($deleteDocumentHtml, 'Y</code> или <code>1'), 'Document deletion form does not explain the short confirmation.');
}
$parseBlockIdList = new ReflectionMethod(AdminRouter::class, 'parseBlockIdList');
$parsedFooterLayout = $parseBlockIdList->invoke($adminRouter, "footer-main\nmenu:footer\nfooter-main\ninvalid:name", 'footer');
$check($parsedFooterLayout === ['footer-main', 'menu:footer'], 'Footer block layout did not preserve a valid menu token or remove invalid/duplicate entries.');
$check($parseBlockIdList->invoke($adminRouter, "home-hero\nmenu:main", 'home') === ['home-hero'], 'Home block layout accepted a footer-only menu token.');
$previousPostForBlocks = $_POST;
$blockActionNonce = bin2hex(random_bytes(4));
$createdBlockId = 'smoke-block-' . $blockActionNonce;
$clonedBlockId = $createdBlockId . '-copy';
$createdBlockRelative = 'content/ru/home/smoke-tests/' . $createdBlockId . '.mur.md';
$clonedBlockRelative = 'content/ro/footer/smoke-tests/' . $clonedBlockId . '.mur.md';
$emptyFolderRelative = 'content/ru/home/smoke-empty-' . $blockActionNonce;

try {
    $_POST = ['_csrf' => $adminAuth->csrfToken(), 'block_language' => 'ru', 'block_folder' => 'home/smoke-tests', 'block_id' => $createdBlockId];
    $createThemeBlock = new ReflectionMethod(AdminRouter::class, 'createThemeBlock');
    $createThemeBlock->invoke($adminRouter, $adminUser);
    $check(is_file(mur_path($createdBlockRelative)), 'Theme block creation did not create a nested block file.');
    $createdBlockContent = is_file(mur_path($createdBlockRelative)) ? (string) file_get_contents(mur_path($createdBlockRelative)) : '';
    $check(!preg_match('/^(?:id|status|order):/m', $createdBlockContent), 'New theme block still contains legacy layout metadata.');

    $_POST = ['_csrf' => $adminAuth->csrfToken(), 'block_language' => 'ro', 'source_file' => $createdBlockRelative, 'block_target' => 'footer/smoke-tests/' . $clonedBlockId];
    $cloneThemeBlock = new ReflectionMethod(AdminRouter::class, 'cloneThemeBlock');
    $cloneThemeBlock->invoke($adminRouter, $adminUser);
    $check(is_file(mur_path($clonedBlockRelative)), 'Theme block duplication did not create the requested file.');
    $clonedBlockContent = is_file(mur_path($clonedBlockRelative)) ? (string) file_get_contents(mur_path($clonedBlockRelative)) : '';
    $check(!preg_match('/^(?:id|status|order|footer\.position):/m', $clonedBlockContent), 'Duplicated theme block retained legacy layout metadata.');

    $_POST = ['_csrf' => $adminAuth->csrfToken(), 'block_language' => 'ru', 'block_folder' => 'home/smoke-empty-' . $blockActionNonce];
    $createThemeBlockFolder = new ReflectionMethod(AdminRouter::class, 'createThemeBlockFolder');
    $createThemeBlockFolder->invoke($adminRouter, $adminUser);
    $check(is_dir(mur_path($emptyFolderRelative)), 'Theme block folder creation failed.');
} finally {
    $_POST = $previousPostForBlocks;
    foreach ([$createdBlockRelative, $clonedBlockRelative] as $relative) {
        if (is_file(mur_path($relative))) {
            unlink(mur_path($relative));
        }
    }
    foreach (['content/ru/home/smoke-tests', 'content/ro/footer/smoke-tests', $emptyFolderRelative] as $relative) {
        if (is_dir(mur_path($relative))) {
            rmdir(mur_path($relative));
        }
    }
}
$saveDocument = new ReflectionMethod(AdminRouter::class, 'saveDocument');
$smokeId = 'smoke-save-' . bin2hex(random_bytes(4));
$smokeCategoryId = $smokeId . '-cat';
$bulkDeleteId = $smokeId . '-delete';
$pageFile = $projectRoot . '/root/data/content/ru/pages/' . $smokeId . '.mur.md';
$categoryFile = $projectRoot . '/root/data/content/ru/categories/' . $smokeCategoryId . '.mur.md';
$bulkDeleteFile = $projectRoot . '/root/data/content/ru/pages/' . $bulkDeleteId . '.mur.md';
$pageBackupBase = $projectRoot . '/root/data/storage/backups/content/ru/pages/' . $smokeId . '.mur.md';
$bulkDeleteBackupBase = $projectRoot . '/root/data/storage/backups/content/ru/pages/' . $bulkDeleteId . '.mur.md';
$bulkCreatedArchives = [];

try {
    $previousPost = $_POST;

    $_POST = [
        '_csrf' => $adminUser['csrf'],
        'lang' => 'ru',
        'original_language' => 'ru',
        'original_id' => '',
        'id' => $smokeId,
        'title' => 'Smoke Save Page',
        'slug' => $smokeId,
        'status' => 'published',
        'template' => 'listing',
        'seo_title' => 'Smoke SEO Title',
        'page_header' => 'false',
        'list_view' => 'titles',
        'list_per_page' => '12',
        'list_position' => 'shortcode',
        'body' => "# Smoke Save Page\n\n[list]\n",
    ];
    $result = $saveDocument->invoke($adminRouter, 'pages', $adminUser);
    $check(is_file($pageFile), 'Admin page save did not create a page file.');
    $check(str_contains((string) file_get_contents($pageFile), 'seo.title: Smoke SEO Title'), 'Admin page save did not persist a PHP-normalized seo.title field.');
    $check(str_contains((string) file_get_contents($pageFile), 'page.header: false'), 'Admin page save did not persist a PHP-normalized page.header field.');
    $check(str_contains((string) file_get_contents($pageFile), 'list.view: titles'), 'Admin page save did not persist a PHP-normalized list.view field.');
    $check(str_contains((string) file_get_contents($pageFile), 'list.per_page: 12'), 'Admin page save did not persist a PHP-normalized list.per_page field.');
    $check(str_contains((string) file_get_contents($pageFile), 'list.position: shortcode'), 'Admin page save did not persist list.position.');
    $check(str_contains((string) $result, 'сохран'), 'Admin page save did not return a success notice.');

    $adminRepo->refresh();
    $_POST['original_id'] = $smokeId;
    $_POST['title'] = 'Smoke Save Page Updated';
    $_POST['body'] = "# Smoke Save Page Updated\n";
    $saveDocument->invoke($adminRouter, 'pages', $adminUser);
    $check(str_contains((string) file_get_contents($pageFile), 'Smoke Save Page Updated'), 'Admin page update did not replace page content.');
    $check(is_file($pageBackupBase . '.bak.1'), 'Admin page update did not create a backup.');

    $adminRepo->refresh();
    $_POST = [
        '_csrf' => $adminUser['csrf'],
        'lang' => 'ru',
        'original_language' => 'ru',
        'original_id' => '',
        'id' => $smokeCategoryId,
        'title' => 'Smoke Save Category',
        'slug' => $smokeCategoryId,
        'status' => 'published',
        'template' => 'category',
        'list.position' => 'top',
        'body' => "# Smoke Save Category\n",
    ];
    $saveDocument->invoke($adminRouter, 'categories', $adminUser);
    $check(is_file($categoryFile), 'Admin category save did not create a category file.');
    $check(str_contains((string) file_get_contents($categoryFile), 'list.position: top'), 'Admin category save did not persist list.position.');

    $adminRepo->refresh();
    $documentTablePost = new ReflectionMethod(AdminRouter::class, 'documentTablePost');
    $_POST = [
        '_csrf' => $adminUser['csrf'],
        'selected' => ['ru:' . $smokeId],
        'bulk_action' => 'status',
        'bulk_status' => 'draft',
    ];
    $documentTablePost->invoke($adminRouter, 'pages', $adminUser);
    $check(str_contains((string) file_get_contents($pageFile), 'status: draft'), 'Pages bulk status action did not update the selected page.');

    $_POST = [
        '_csrf' => $adminUser['csrf'],
        'selected' => ['ru:' . $smokeId],
        'bulk_action' => 'category',
        'bulk_categories' => ['company', $smokeCategoryId],
    ];
    $documentTablePost->invoke($adminRouter, 'pages', $adminUser);
    $pageAfterBulkCategories = (string) file_get_contents($pageFile);
    $check(
        str_contains($pageAfterBulkCategories, 'categories: company, ' . $smokeCategoryId),
        'Pages bulk category action did not add all selected categories.',
    );

    $_POST = [
        'selected' => ['ru:' . $smokeId, 'ro:about'],
    ];
    $mixedLanguageRejected = false;
    try {
        (new ReflectionMethod(AdminRouter::class, 'selectedDocuments'))->invoke($adminRouter, 'pages');
    } catch (RuntimeException $exception) {
        $mixedLanguageRejected = str_contains($exception->getMessage(), 'RU: 1')
            && str_contains($exception->getMessage(), 'RO: 1');
    }
    $check($mixedLanguageRejected, 'Pages bulk actions did not reject a mixed-language selection with per-language counts.');

    $_POST = [
        '_csrf' => $adminUser['csrf'],
        'selected' => ['ru:' . $smokeId],
        'bulk_action' => 'parent',
        'bulk_parent' => 'ru:services',
    ];
    $documentTablePost->invoke($adminRouter, 'pages', $adminUser);
    $check(str_contains((string) file_get_contents($pageFile), 'parent: services'), 'Pages bulk parent action did not update the selected page.');

    file_put_contents(
        $bulkDeleteFile,
        "id: {$bulkDeleteId}\ntitle: Bulk Delete Smoke\nslug: {$bulkDeleteId}\nstatus: draft\n---\n\nBulk delete.\n",
        LOCK_EX,
    );
    $adminRepo->refresh();
    $archivePattern = $projectRoot . '/root/data/storage/backups/bulk-pages/pages-*.zip';
    $archivesBefore = glob($archivePattern) ?: [];
    $_POST = [
        '_csrf' => $adminUser['csrf'],
        'selected' => ['ru:' . $bulkDeleteId],
        'bulk_action' => 'delete_confirm',
        'confirm' => 'Y',
    ];
    $deleteResult = (string) $documentTablePost->invoke($adminRouter, 'pages', $adminUser);
    $archivesAfter = glob($archivePattern) ?: [];
    $bulkCreatedArchives = array_values(array_diff($archivesAfter, $archivesBefore));
    $check(!is_file($bulkDeleteFile) && str_contains($deleteResult, 'Резервный ZIP'), 'Pages bulk delete did not delete the page after creating a backup notice.');
    $check(count($bulkCreatedArchives) === 1 && is_file($bulkCreatedArchives[0]), 'Pages bulk delete did not create one ZIP backup.');
    if ($bulkCreatedArchives !== []) {
        $zip = new ZipArchive();
        $opened = $zip->open($bulkCreatedArchives[0]) === true;
        $hasDeletedPage = $opened && $zip->locateName('ru/pages/' . $bulkDeleteId . '.mur.md') !== false;
        if ($opened) {
            $zip->close();
        }
        $check($hasDeletedPage, 'Pages bulk-delete ZIP does not contain the deleted source file.');
    }
} finally {
    $_POST = $previousPost ?? [];
    foreach ([
        $pageFile,
        $categoryFile,
        $bulkDeleteFile,
        $pageBackupBase . '.bak.1',
        $pageBackupBase . '.bak.2',
        $bulkDeleteBackupBase . '.bak.1',
        $bulkDeleteBackupBase . '.bak.2',
        ...$bulkCreatedArchives,
    ] as $temporaryFile) {
        if (is_file($temporaryFile)) {
            unlink($temporaryFile);
        }
    }
    foreach ([dirname($pageBackupBase), $projectRoot . '/root/data/storage/backups/bulk-pages'] as $backupDirectory) {
        if (is_dir($backupDirectory) && (scandir($backupDirectory) ?: []) === ['.', '..']) {
            rmdir($backupDirectory);
        }
    }
    $adminRepo->refresh();
}

$mediaNonce = bin2hex(random_bytes(4));
$mediaSourceRelative = 'smoke-media-' . $mediaNonce;
$mediaTargetRelative = $mediaSourceRelative . '/target';
$mediaSourceDirectory = $projectRoot . '/root/uploads/' . $mediaSourceRelative;
$mediaTargetDirectory = $projectRoot . '/root/uploads/' . $mediaTargetRelative;
$validPng = sys_get_temp_dir() . '/murcms-valid-' . $mediaNonce . '.png';
$fakePng = sys_get_temp_dir() . '/murcms-fake-' . $mediaNonce . '.png';
$previousMediaGet = $_GET;
$previousMediaPost = $_POST;
try {
    mkdir($mediaTargetDirectory, 0775, true);
    for ($index = 1; $index <= 12; $index++) {
        file_put_contents(
            $mediaSourceDirectory . '/file-' . str_pad((string) $index, 2, '0', STR_PAD_LEFT) . '.txt',
            'media smoke ' . $index,
            LOCK_EX,
        );
    }

    $mediaManagerMethod = new ReflectionMethod(AdminRouter::class, 'mediaManager');
    $_POST = [];
    $_GET = ['dir' => $mediaSourceRelative, 'view' => 'list', 'per_page' => '10'];
    $mediaListHtml = (string) $mediaManagerMethod->invoke($adminRouter, 'GET', $adminUser);
    $check(substr_count($mediaListHtml, 'name="selected_files[]"') === 10, 'Media list pagination does not show ten selectable files.');
    $check(str_contains($mediaListHtml, 'name="convert_webp" value="1" checked'), 'Media upload WebP conversion is not enabled by default.');
    $check(str_contains($mediaListHtml, 'data-selection-action="all"') && str_contains($mediaListHtml, 'data-selection-action="invert"'), 'Media bulk selection controls are missing.');
    $check(str_contains($mediaListHtml, 'data-media-urls-open') && str_contains($mediaListHtml, 'id="media-bulk-urls-dialog"') && str_contains($mediaListHtml, 'formaction="/admin/media/bulk/download"') && str_contains($mediaListHtml, 'Переместить в папку'), 'Media bulk operations or URL dialog are incomplete.');
    $check(str_contains($mediaListHtml, 'Файлы: 1–10 из 12') && str_contains($mediaListHtml, 'per_page=10&amp;page=2'), 'Media pagination summary or next-page link is incorrect.');
    $check(str_contains($mediaListHtml, 'id="media-bulk-move-dialog"') && str_contains($mediaListHtml, 'value="' . $mediaTargetRelative . '"'), 'Media move dialog does not contain the uploads folder tree.');

    $_GET = ['dir' => $mediaSourceRelative, 'view' => 'gallery', 'per_page' => '10'];
    $mediaGalleryHtml = (string) $mediaManagerMethod->invoke($adminRouter, 'GET', $adminUser);
    $check(substr_count($mediaGalleryHtml, 'name="selected_files[]"') === 10 && str_contains($mediaGalleryHtml, 'class="admin-media-card-select"'), 'Media gallery does not render selectable paginated cards.');

    $_GET = [];
    $_POST = [
        '_csrf' => $adminUser['csrf'],
        'dir' => $mediaSourceRelative,
        'view' => 'list',
        'per_page' => '10',
        'page' => '1',
        'action' => 'bulk_urls',
        'selected_files' => [$mediaSourceRelative . '/file-01.txt', $mediaSourceRelative . '/file-02.txt'],
    ];
    $mediaUrlsHtml = (string) $mediaManagerMethod->invoke($adminRouter, 'POST', $adminUser);
    $check(str_contains($mediaUrlsHtml, '/uploads/' . $mediaSourceRelative . '/file-01.txt') && str_contains($mediaUrlsHtml, '/uploads/' . $mediaSourceRelative . '/file-02.txt'), 'Media bulk URL list is incorrect.');

    $_POST = [
        '_csrf' => $adminUser['csrf'],
        'dir' => $mediaSourceRelative,
        'view' => 'list',
        'action' => 'bulk_move',
        'target_dir' => $mediaTargetRelative,
        'selected_files' => [$mediaSourceRelative . '/file-01.txt'],
    ];
    $mediaManagerMethod->invoke($adminRouter, 'POST', $adminUser);
    $check(!is_file($mediaSourceDirectory . '/file-01.txt') && is_file($mediaTargetDirectory . '/file-01.txt'), 'Media bulk move did not move the selected file.');

    $_POST = [
        '_csrf' => $adminUser['csrf'],
        'dir' => $mediaSourceRelative,
        'view' => 'list',
        'action' => 'bulk_delete_confirm',
        'confirm' => '1',
        'selected_files' => [$mediaSourceRelative . '/file-02.txt'],
    ];
    $mediaManagerMethod->invoke($adminRouter, 'POST', $adminUser);
    $check(!is_file($mediaSourceDirectory . '/file-02.txt'), 'Media bulk delete did not delete the selected file.');

    $mediaManager = new AdminMediaManager($projectRoot . '/root/uploads');
    $check(in_array($mediaTargetRelative, $mediaManager->directories(), true), 'Media directory tree does not include nested uploads folders.');
    $imageValidation = new ReflectionMethod(AdminMediaManager::class, 'isValidImageUpload');
    file_put_contents($validPng, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true), LOCK_EX);
    file_put_contents($fakePng, '<?php echo "not an image";', LOCK_EX);
    $check($imageValidation->invoke($mediaManager, $validPng, 'png') === true, 'Valid PNG upload was rejected by content validation.');
    $check($imageValidation->invoke($mediaManager, $validPng, 'jpg') === false, 'Image upload validation accepted a mismatched extension.');
    $check($imageValidation->invoke($mediaManager, $fakePng, 'png') === false, 'Image upload validation accepted non-image content.');
} finally {
    $_GET = $previousMediaGet;
    $_POST = $previousMediaPost;
    foreach ([$validPng, $fakePng] as $temporaryImage) {
        if (is_file($temporaryImage)) {
            unlink($temporaryImage);
        }
    }
    foreach ([$mediaSourceDirectory, $mediaTargetDirectory] as $directory) {
        if (!is_dir($directory)) {
            continue;
        }
        foreach (scandir($directory) ?: [] as $name) {
            if ($name !== '.' && $name !== '..' && is_file($directory . '/' . $name)) {
                unlink($directory . '/' . $name);
            }
        }
    }
    if (is_dir($mediaTargetDirectory)) {
        rmdir($mediaTargetDirectory);
    }
    if (is_dir($mediaSourceDirectory)) {
        rmdir($mediaSourceDirectory);
    }
}

$htaccess = file_get_contents($projectRoot . '/root/.htaccess') ?: '';
$check(str_contains($htaccess, 'RewriteRule ^data'), '.htaccess does not block the data directory.');
$check(str_contains($htaccess, 'murcms/(?:app|admin|plugins)'), '.htaccess does not block engine PHP directories.');
$check(str_contains($htaccess, 'murcms/templates/.+\.php'), '.htaccess does not block frontend template PHP files.');
$check(str_contains($htaccess, '\.mur\.md$'), '.htaccess does not block .mur.md files.');
$check(is_file($projectRoot . '/root/uploads/.htaccess'), 'Upload execution protection is missing.');
$frontController = file_get_contents($projectRoot . '/root/index.php') ?: '';
$check(
    str_contains($frontController, "!isset(\$types[\$extension])")
    && str_contains($frontController, "preg_match('~(?:^|[\\\\\\\\/])\\\\.[^\\\\\\\\/]+~', \$file)"),
    'Development asset server does not reject hidden or non-allowlisted files.',
);
$check(
    str_contains($frontController, "header_remove('X-Powered-By')")
    && str_contains($frontController, "header('X-Content-Type-Options: nosniff')")
    && str_contains($frontController, "header('Referrer-Policy: strict-origin-when-cross-origin')")
    && str_contains($frontController, "header('X-Frame-Options: SAMEORIGIN')"),
    'Dynamic public responses do not set the baseline security headers.',
);
$adminCss = file_get_contents($projectRoot . '/root/murcms/assets/admin.css') ?: '';
$check(str_contains($adminCss, '.visually-hidden'), 'Admin visually-hidden utility is missing.');
$check(str_contains($adminCss, '.admin-bulk-actions') && str_contains($adminCss, '.admin-media-card-select'), 'Admin bulk-action or media-selection styling is missing.');
$check(
    preg_match('~\.admin-row-actions\s*\{[^}]*display:\s*inline-flex;[^}]*flex-wrap:\s*nowrap;[^}]*min-width:\s*max-content;~s', $adminCss) === 1
    && preg_match('~\.admin-documents-table \.admin-document-actions\s*\{[^}]*width:\s*1%;[^}]*white-space:\s*nowrap;~s', $adminCss) === 1,
    'Shared row actions can wrap or the action cell no longer sizes to its content.',
);
$check(
    str_contains($adminCss, '--admin-logo-green: #73ad00')
    && str_contains($adminCss, '--admin-accent: #517a00')
    && str_contains($adminCss, '--admin-accent-strong: #365200')
    && str_contains($adminCss, '.admin-role-badge'),
    'Admin palette is not derived from the MurCMS logo green or user-role styling is missing.',
);
$tagsPluginSource = file_get_contents($projectRoot . '/root/murcms/plugins/tags/plugin.php') ?: '';
$blocksPluginSource = file_get_contents($projectRoot . '/root/murcms/plugins/blocks/plugin.php') ?: '';
$galleryPluginSource = file_get_contents($projectRoot . '/root/murcms/plugins/gallery/plugin.php') ?: '';
$redirectsPluginSource = file_get_contents($projectRoot . '/root/murcms/plugins/redirects/src/RedirectsPlugin.php') ?: '';
$murConsentAdminSource = file_get_contents($projectRoot . '/root/murcms/plugins/murconsent/src/AdminUi.php') ?: '';
$banerinaStoreSource = file_get_contents($projectRoot . '/root/murcms/plugins/banerina/src/ConfigStore.php') ?: '';
$check(
    str_contains($tagsPluginSource, "'role' => 'editor'")
    && str_contains($blocksPluginSource, "'role' => 'editor'")
    && str_contains($galleryPluginSource, "'role' => 'editor'"),
    'Content plugins are not assigned to the editor role.',
);
$check(
    str_contains($tagsPluginSource, 'AdminUserManager::ROLE_ADMIN')
    && str_contains($redirectsPluginSource, 'AdminUserManager::ROLE_ADMIN')
    && str_contains($murConsentAdminSource, 'AdminUserManager::ROLE_ADMIN'),
    'Bundled plugin defense-in-depth checks do not include super-admin inheritance.',
);
$check(
    str_contains($murConsentAdminSource, '. "if (!defined(\'MUR_CMS_BOOTSTRAPPED\')) {\\n"'),
    'MurConsent config writer does not preserve the direct-access guard.',
);
$check(
    str_contains($banerinaStoreSource, ". \"if (!defined('MUR_CMS_BOOTSTRAPPED')) {\\n\"")
    && str_contains($banerinaStoreSource, 'file_put_contents($tmp, $php, LOCK_EX)')
    && str_contains($banerinaStoreSource, '@rename($tmp, $this->configPath)'),
    'Banerina config writer is missing the direct-access guard or atomic replacement.',
);
$adminThemeJs = file_get_contents($projectRoot . '/root/murcms/assets/admin-theme.js') ?: '';
$adminMediaJs = file_get_contents($projectRoot . '/root/murcms/assets/admin-media.js') ?: '';
$check(
    str_contains($adminThemeJs, '[data-selection-scope]')
    && str_contains($adminThemeJs, '[data-dialog-open]')
    && str_contains($adminThemeJs, 'name="selected[]"')
    && str_contains($adminThemeJs, 'name="selected_files[]"')
    && !str_contains($adminThemeJs, 'name$="[]"'),
    'Admin selection or dialog behavior is missing or captures unrelated checkbox arrays.',
);
$check(str_contains($adminMediaJs, '[data-webp-conversion]') && str_contains($adminMediaJs, '[data-media-urls-open]') && str_contains($adminMediaJs, 'event.preventDefault()'), 'Media WebP or URL-dialog behavior is missing.');

$adminRouterSource = file_get_contents($projectRoot . '/root/murcms/app/Admin/AdminRouter.php') ?: '';
$check(!str_contains($adminRouterSource, "'content/blocks'") && !str_contains($adminRouterSource, "'plugin/blocks'") && !str_contains($adminRouterSource, "'plugin/gallery'"), 'AdminRouter still contains plugin-specific Blocks/Gallery routes.');
$check(str_contains($adminThemeJs, '[data-file-replace-open]') && str_contains($adminThemeJs, '[data-mur-file-upload]') && str_contains($adminThemeJs, 'new FormData(form)'), 'AJAX upload/replace enhancement is missing.');

$breadcrumbHtml = (new AdminRenderer($config['site'], new AdminI18n($projectRoot . '/root/murcms/admin/lang', $config['admin'])))->page(
    'Страницы', '<p>test</p>', $adminNavGroups, $adminUser, 'content-pages',
);
$check(
    str_contains($breadcrumbHtml, 'class="admin-breadcrumbs"')
    && str_contains($breadcrumbHtml, 'Панель управления')
    && str_contains($breadcrumbHtml, 'Контент')
    && str_contains($breadcrumbHtml, 'Страницы'),
    'Central admin breadcrumbs do not include dashboard, sidebar group and current page.',
);
$dashboardBreadcrumbHtml = (new AdminRenderer($config['site'], new AdminI18n($projectRoot . '/root/murcms/admin/lang', $config['admin'])))->page(
    'Dashboard', '<p>test</p>', $adminNavGroups, $adminUser, 'dashboard',
);
preg_match('~<nav class="admin-breadcrumbs"[^>]*>(.*?)</nav>~s', $dashboardBreadcrumbHtml, $dashboardBreadcrumbMatch);
$dashboardBreadcrumb = (string) ($dashboardBreadcrumbMatch[1] ?? '');
$check(str_contains($dashboardBreadcrumb, 'Панель управления') && substr_count($dashboardBreadcrumb, '<li>') === 1, 'Dashboard breadcrumb is duplicated.');

$previousFileUiGet = $_GET;
try {
    $_GET = [];
    $fileCollectionPageMethod = new ReflectionMethod(AdminRouter::class, 'fileCollectionPage');
    $blocksFilePage = (string) $fileCollectionPageMethod->invoke($adminRouter, 'blocks', $adminUser);
    $galleryFilePage = (string) $fileCollectionPageMethod->invoke($adminRouter, 'gallery', $adminUser);
    $pagesFileUi = (string) $documentsTableMethod->invoke($adminRouter, 'pages', 'GET', $adminUser);
    foreach ([$blocksFilePage, $galleryFilePage, $pagesFileUi] as $fileUi) {
        $check(str_contains($fileUi, 'data-file-replace-open') && str_contains($fileUi, 'data-mur-file-upload'), 'A file-backed admin collection is missing Replace or Upload UI.');
        $check(str_contains($fileUi, 'name="language"'), 'A multilingual file uploader does not show its target language.');
        $check(str_contains($fileUi, '2 MB') && str_contains($fileUi, 'не проверяется'), 'A file-backed upload UI is missing its size or no-content-validation warning.');
    }

    $emptyHooks = new Hooks();
    $emptyContext = new PluginContext($emptyHooks, $config['site'], $config['languages'], $projectRoot . '/root/data', $projectRoot . '/root/murcms/plugins', $adminRepo);
    $emptyPluginManager = new PluginManager($projectRoot . '/root/murcms/plugins', ['enabled' => []], $emptyContext);
    $emptyRouter = new AdminRouter(
        $config['site'], $config['languages'], $config['admin'], $config['theme'], $adminRepo, $emptyHooks,
        $emptyPluginManager, new SeoManager($config['site'], $config['languages'], $config['seo'], $adminRepo, $emptyHooks),
        $adminAuth, new AdminI18n($projectRoot . '/root/murcms/admin/lang', $config['admin']),
        new AdminRenderer($config['site'], new AdminI18n($projectRoot . '/root/murcms/admin/lang', $config['admin'])),
    );
    $emptyNav = json_encode($navGroupsMethod->invoke($emptyRouter, $adminUser), JSON_UNESCAPED_SLASHES) ?: '';
    $check(!str_contains($emptyNav, '/admin/plugin/blocks') && !str_contains($emptyNav, '/admin/plugin/gallery'), 'Disabled Blocks/Gallery still appear in admin navigation.');
    $check($fileCollectionMethod->invoke($emptyRouter, 'blocks') === null && $fileCollectionMethod->invoke($emptyRouter, 'gallery') === null, 'Disabled Blocks/Gallery still expose file collection routes through core.');

    $previousFilePost = $_POST;
    $previousFileUploads = $_FILES;
    $previousAccept = $_SERVER['HTTP_ACCEPT'] ?? null;
    try {
        $_SERVER['HTTP_ACCEPT'] = 'application/json';
        $_FILES = [];
        $fileMutationMethod = new ReflectionMethod(AdminRouter::class, 'fileMutationAction');
        $_POST = ['scope' => 'pages', '_csrf' => 'invalid'];
        $invalidCsrf = json_decode((string) $fileMutationMethod->invoke($adminRouter, 'upload', 'POST', $adminNavGroups, $adminUser), true);
        $check(($invalidCsrf['ok'] ?? true) === false && str_contains((string) ($invalidCsrf['message'] ?? ''), 'CSRF'), 'File upload action did not reject an invalid CSRF token.');

        $_POST = ['scope' => 'home', '_csrf' => $adminAuth->csrfToken()];
        $editorUser = ['id' => 'smoke-editor', 'role' => 'editor', 'csrf' => $adminAuth->csrfToken()];
        $forbiddenUpload = json_decode((string) $fileMutationMethod->invoke($adminRouter, 'upload', 'POST', $adminNavGroups, $editorUser), true);
        $check(($forbiddenUpload['ok'] ?? true) === false && str_contains((string) ($forbiddenUpload['message'] ?? ''), 'прав'), 'File upload action did not enforce the collection role.');
    } finally {
        $_POST = $previousFilePost;
        $_FILES = $previousFileUploads;
        if ($previousAccept === null) unset($_SERVER['HTTP_ACCEPT']); else $_SERVER['HTTP_ACCEPT'] = $previousAccept;
    }
} finally {
    $_GET = $previousFileUiGet;
}

$fileManagerNonce = bin2hex(random_bytes(5));
$fileManagerDirectory = 'storage/smoke-file-manager-' . $fileManagerNonce;
$fileManagerAbsolute = mur_data_path($fileManagerDirectory);
$temporaryUploads = [];
$managedFiles = [];
$managedBackups = [];
$fileManager = new AdminFileManager(mur_data_path());
$makeUpload = static function (string $name, string $contents) use (&$temporaryUploads): array {
    $tmp = tempnam(sys_get_temp_dir(), 'murcms-upload-');
    if ($tmp === false) {
        throw new RuntimeException('Unable to create smoke upload file.');
    }
    file_put_contents($tmp, $contents);
    $temporaryUploads[] = $tmp;
    return ['name' => $name, 'tmp_name' => $tmp, 'size' => strlen($contents), 'error' => UPLOAD_ERR_OK];
};
try {
    $original = "id: smoke\n---\n\nOriginal\n";
    $first = $fileManager->upload($makeUpload('sample.mur.md', $original), $fileManagerDirectory);
    $managedFiles[] = $first['path'];
    $check($first['filename'] === 'sample.mur.md' && file_get_contents($first['path']) === $original, 'Single .mur.md upload changed the filename or contents.');

    $second = $fileManager->upload($makeUpload('sample.mur.md', "second\n"), $fileManagerDirectory);
    $managedFiles[] = $second['path'];
    $check($second['filename'] === 'sample-1.mur.md' && file_get_contents($second['path']) === "second\n", 'Upload conflict did not use the -1 suffix or preserve contents.');
    $third = $fileManager->upload($makeUpload('sample.mur.md', "third\n"), $fileManagerDirectory);
    $managedFiles[] = $third['path'];
    $check($third['filename'] === 'sample-2.mur.md' && file_get_contents($third['path']) === "third\n", 'Second upload conflict did not use the -2 suffix or preserve contents.');

    $replacement = "id: replacement\n---\n\nReplacement\n";
    $replaced = $fileManager->replace($makeUpload('different-name.mur.md', $replacement), $fileManagerDirectory . '/sample.mur.md');
    $managedBackups[] = $replaced['backup'];
    $check($replaced['filename'] === 'sample.mur.md' && file_get_contents($first['path']) === $replacement, 'Replace did not preserve the target filename and exact uploaded contents.');
    $check(is_file($replaced['backup']) && file_get_contents($replaced['backup']) === $original, 'Replace backup is missing or does not contain the previous version.');

    $beforeInvalidReplace = file_get_contents($first['path']);
    try {
        $fileManager->replace($makeUpload('invalid.txt', 'bad'), $fileManagerDirectory . '/sample.mur.md');
        $check(false, 'Replace accepted a non-.mur.md filename.');
    } catch (RuntimeException) {
        $check(file_get_contents($first['path']) === $beforeInvalidReplace, 'Invalid Replace changed the original file.');
    }
    try {
        $fileManager->upload($makeUpload('too-large.mur.md', str_repeat('x', AdminFileManager::MAX_UPLOAD_BYTES + 1)), $fileManagerDirectory);
        $check(false, 'Upload accepted a file larger than 2 MB.');
    } catch (RuntimeException) {
        $check(true, 'Upload rejected a file larger than 2 MB.');
    }
    try {
        $fileManager->upload($makeUpload('safe.mur.md', 'safe'), '../outside');
        $check(false, 'Upload accepted a path traversal target.');
    } catch (RuntimeException) {
        $check(true, 'Upload rejected path traversal.');
    }
    try {
        $fileManager->upload(['name' => ['one.mur.md', 'two.mur.md'], 'tmp_name' => [], 'error' => UPLOAD_ERR_OK], $fileManagerDirectory);
        $check(false, 'Upload accepted multiple files in one operation.');
    } catch (RuntimeException) {
        $check(true, 'Upload rejected multiple files.');
    }
} finally {
    foreach ($temporaryUploads as $tmp) {
        if (is_file($tmp)) unlink($tmp);
    }
    foreach ($managedFiles as $file) {
        if (is_file($file)) unlink($file);
    }
    foreach ($managedBackups as $file) {
        if (is_file($file)) unlink($file);
    }
    if (is_dir($fileManagerAbsolute)) rmdir($fileManagerAbsolute);
}

$frontendCss = file_get_contents($projectRoot . '/root/murcms/templates/default/assets/app.css') ?: '';
$colorSchemes = mur_color_scheme_names('default');
foreach (['auto', 'dark', 'light', 'memorial-gold'] as $colorScheme) {
    $check(in_array($colorScheme, $colorSchemes, true), "Color scheme '{$colorScheme}' was not discovered.");
    $check(is_file(mur_color_scheme_path('default', $colorScheme, 'critical.css')), "Critical CSS is missing for '{$colorScheme}'.");
    $check(is_file(mur_color_scheme_path('default', $colorScheme, 'theme.css')), "Theme CSS is missing for '{$colorScheme}'.");
}
$check(mur_color_scheme_name('default', '../invalid') === 'light', 'Invalid color scheme did not fall back to light.');
$check(!str_contains($frontendCss, '.ts-memorial-gold'), 'Color scheme CSS leaked into shared app.css.');

$contentFiles = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($projectRoot . '/root/data', FilesystemIterator::SKIP_DOTS)
);
foreach ($contentFiles as $file) {
    if (!$file instanceof SplFileInfo || !$file->isFile() || !str_ends_with($file->getFilename(), '.mur.md')) {
        continue;
    }

    $source = file_get_contents($file->getPathname()) ?: '';
    $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($projectRoot) + 1));
    $legacySource = preg_replace('~/murcms/templates/[a-z0-9_-]+/assets/~', '/murcms-template-assets', $source) ?? $source;
    $check(!preg_match('~(?<!/murcms)/assets/~', $legacySource), "Legacy /assets URL found in {$relative}.");

    preg_match_all('~/(?:murcms/assets|murcms/templates/[a-z0-9_-]+/assets)/[A-Za-z0-9_./-]+~', $source, $matches);
    foreach (array_unique($matches[0] ?? []) as $assetUrl) {
        $assetPath = $projectRoot . '/root/' . ltrim($assetUrl, '/');
        $check(is_file($assetPath), "Missing local asset '{$assetUrl}' referenced by {$relative}.");
    }
}

$phpFiles = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($projectRoot . '/root', FilesystemIterator::SKIP_DOTS)
);
foreach ($phpFiles as $file) {
    if (!$file instanceof SplFileInfo || !$file->isFile() || $file->getExtension() !== 'php') {
        continue;
    }

    $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($projectRoot) + 1));
    if (in_array($relative, ['root/index.php', 'root/murcms/app/bootstrap.php'], true)) {
        continue;
    }

    $source = file_get_contents($file->getPathname()) ?: '';
    $check(str_contains($source, 'MUR_CMS_BOOTSTRAPPED'), "Direct-access guard is missing in {$relative}.");
}

foreach (($config['admin']['users'] ?? []) as $user) {
    $hash = (string) ($user['password_hash'] ?? '');
    $check(($user['username'] ?? '') !== '', 'Admin username is empty.');
    $check(($user['active'] ?? false) === true, "Admin user '{$user['username']}' is inactive.");
    $check(in_array(AdminUserManager::normalizeRole((string) ($user['role'] ?? '')), ['super_admin', 'admin', 'editor'], true), "Admin user '{$user['username']}' has an invalid role.");
    $check(filter_var((string) ($user['email'] ?? ''), FILTER_VALIDATE_EMAIL) !== false && array_key_exists('phone', $user), "Admin user '{$user['username']}' has an incomplete profile.");
    $check((password_get_info($hash)['algoName'] ?? 'unknown') !== 'unknown', "Invalid password hash for '{$user['username']}'.");
}

if (!$production && (!extension_loaded('gd') || !function_exists('imagewebp'))) {
    $warnings[] = 'GD with WebP support is not installed; production readiness check will fail.';
}
if (!$production && !extension_loaded('mbstring')) {
    $warnings[] = 'mbstring is not installed; production readiness check will fail.';
}
if (!extension_loaded('imagick')) {
    $warnings[] = 'Imagick is not installed; it is optional but recommended.';
}

foreach ($warnings as $warning) {
    fwrite(STDOUT, "WARNING: {$warning}\n");
}

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}\n");
    }
    fwrite(STDERR, sprintf("Smoke test failed: %d checks, %d failures.\n", $checks, count($failures)));
    exit(1);
}

fwrite(STDOUT, sprintf("Smoke test passed: %d checks.\n", $checks));
