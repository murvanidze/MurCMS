<?php
declare(strict_types=1);

// All mutation tests run against a disposable installation, never the working site.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
$project = dirname(__DIR__);
$fixture = $project . '/_dist/qa-042-' . bin2hex(random_bytes(6));
mkdir($fixture, 0775, true);
$copyTree = static function (string $source, string $target, callable $allow) use (&$copyTree): void {
    foreach (new DirectoryIterator($source) as $entry) {
        if ($entry->isDot() || $entry->isLink()) { continue; }
        $to = $target . '/' . $entry->getFilename();
        if ($entry->isDir()) {
            if (!is_dir($to)) { mkdir($to, 0775, true); }
            $copyTree($entry->getPathname(), $to, $allow);
        } elseif ($allow($entry->getPathname())) {
            if (!is_dir(dirname($to))) { mkdir(dirname($to), 0775, true); }
            copy($entry->getPathname(), $to);
        }
    }
};
$copyTree($project . '/root/murcms', $fixture . '/murcms', static fn ($file) => true);
$copyTree($project . '/root/data', $fixture . '/data', static fn ($file) => str_ends_with($file, '.dist.php') || (str_ends_with($file, '.mur.md') && !str_contains(str_replace('\\', '/', $file), '/storage/')));
foreach (glob($fixture . '/data/config/*.dist.php') ?: [] as $dist) { copy($dist, str_replace('.dist.php', '.php', $dist)); }
mkdir($fixture . '/uploads', 0775, true);
$checks = 0;
$failures = [];
$check = static function (bool $condition, string $message) use (&$checks, &$failures): void { $checks++; if (!$condition) { $failures[] = $message; } };
$throws = static function (callable $action): bool { try { $action(); } catch (Throwable) { return true; } return false; };
try {
    define('MUR_CMS_ENTRY', true);
    $config = require $fixture . '/murcms/app/bootstrap.php';
    $config['site']['timezone'] = 'Europe/Chisinau';
    date_default_timezone_set('Europe/Chisinau');
    $repository = new \Mur\Core\ContentRepository(mur_path('content'), $config['languages']);
    $hooks = new \Mur\Core\Hooks();
    $context = new \Mur\Core\PluginContext($hooks, $config['site'], $config['languages'], mur_data_path(), mur_engine_path('plugins'), $repository);
    $plugins = new \Mur\Core\PluginManager(mur_engine_path('plugins'), ['enabled' => [...$config['plugins']['enabled'], 'banerina']], $context);
    $plugins->loadEnabled();
    $auth = new \Mur\Admin\AdminAuth($config['admin']);
    $token = $auth->csrfToken();
    $user = ['id' => 'test', 'role' => 'super_admin', 'csrf' => $token, 'username' => 'QA'];
    $i18n = new \Mur\Admin\AdminI18n(mur_engine_path('admin/lang'), $config['admin']);
    $seo = new \Mur\Core\SeoManager($config['site'], $config['languages'], $config['seo'], $repository, $hooks);
    $router = new \Mur\Admin\AdminRouter($config['site'], $config['languages'], $config['admin'], $config['theme'], $repository, $hooks, $plugins, $seo, $auth, $i18n, new \Mur\Admin\AdminRenderer($config['site'], $i18n));
    $call = static fn (string $name, ...$args) => (new ReflectionMethod($router, $name))->invoke($router, ...$args);
    $parser = new \Mur\Core\DocumentParser();
    $check(count($config['plugins']['enabled']) === 8 && !in_array('banerina', $config['plugins']['enabled'], true), 'Eight standard plugins, Banerina separate');
    $check(count($plugins->loaded()) === 9, 'All bundled and separate plugins load together');
    foreach ($plugins->loaded() as $plugin) {
        $info = $plugins->metadata($plugin);
        $check(isset($info['name'], $info['version'], $info['description']), 'Plugin metadata: ' . $plugin);
    }
    mkdir(mur_engine_path('plugins/qa-disabled'));
    file_put_contents(mur_engine_path('plugins/qa-disabled/plugin.php'), '<?php throw new Exception("must not execute");');
    foreach (['{broken', 'null', '42', '{"version":[],"name":"<script>"}', "\xff"] as $json) {
        file_put_contents(mur_engine_path('plugins/qa-disabled/info.json'), $json);
        $info = $plugins->metadata('qa-disabled');
        $check(!isset($info['version']), 'Malformed plugin metadata degrades safely');
        $html = $call('pluginsPage', 'GET', $user);
        $check(str_contains($html, 'qa-disabled') && !str_contains($html, '<script>'), 'Disabled plugin lists without execution / unsafe HTML');
    }
    unlink(mur_engine_path('plugins/qa-disabled/info.json'));
    $check($plugins->metadata('qa-disabled') === [] && $plugins->metadata('../snippets') === [], 'Missing metadata and invalid ID');
    $check(\Mur\Core\DocumentDates::parse('2024-02-29 12:34:56', 'Europe/Chisinau') !== null, 'Leap date accepted');
    foreach (['2023-02-29 12:34:56', '2026-04-31 10:00:00', '2026-03-29 03:30:00', '2026-01-01 24:00:00'] as $invalid) {
        $check(\Mur\Core\DocumentDates::parse($invalid, 'Europe/Chisinau') === null, 'Invalid calendar / DST date rejected: ' . $invalid);
    }
    $dateFields = ['year' => 2024, 'month' => 2, 'day' => 29, 'hour' => 12, 'minute' => 34, 'second' => 56];
    $check(\Mur\Core\DocumentDates::fromFields($dateFields, 'Europe/Chisinau') === '2024-02-29 12:34:56', 'Six fields serialize exactly');
    $check($throws(fn () => \Mur\Core\DocumentDates::fromFields($dateFields + ['junk' => 1], 'invalid/zone')), 'Invalid zone rejected');
    $check($throws(fn () => $parser->serialize(['bad' => "one\nstatus: published"], 'body')), 'Metadata cannot inject header lines');
    $pagePost = ['_csrf' => $token, 'lang' => 'ru', 'id' => 'qa-page', 'original_id' => '', 'original_language' => 'ru', 'title' => 'QA Page', 'slug' => 'qa-page', 'status' => 'published', 'template' => 'page', 'extra_meta' => "parser: murtext\nmurtext.pretty: 1\ncustom.future: keep\ndate: 2020-01-02", 'body' => '__ Body'];
    $_POST = $pagePost;
    $call('saveDocument', 'pages', $user);
    $file = mur_path('content/ru/pages/qa-page.mur.md');
    $document = $parser->parse($file, 'pages', 'ru');
    $created = $document->meta['updated_at'];
    $check(isset($document->meta['custom.future']) && $document->meta['parser'] === 'murtext', 'Page unknown / parser metadata preserved');
    $check(\Mur\Core\DocumentDates::parse($created, 'Europe/Chisinau') !== null, 'First save creates updated_at');
    $_POST['original_id'] = 'qa-page';
    $_POST['updated'] = $dateFields;
    $_POST['extra_meta'] .= "\nupdated_at: 1900-01-01";
    $call('saveDocument', 'pages', $user);
    $check($parser->parse($file, 'pages', 'ru')->meta['updated_at'] === $created, 'Unchecked date preserved, extra_meta cannot bypass permission');
    $_POST['update_updated_at'] = '1';
    $call('saveDocument', 'pages', $user);
    $check($parser->parse($file, 'pages', 'ru')->meta['updated_at'] === '2024-02-29 12:34:56', 'Checked saves shown date, never submit time');
    $_POST['updated']['year'] = 2023;
    $before = file_get_contents($file);
    $result = $call('saveDocument', 'pages', $user);
    $check(str_contains($result, 'Некорректная') && file_get_contents($file) === $before, 'Invalid leap edit leaves file intact');
    unset($_POST['update_updated_at']);
    $_POST['updated']['year'] = 2022;
    $call('saveDocument', 'pages', $user);
    $check($parser->parse($file, 'pages', 'ru')->meta['updated_at'] === '2024-02-29 12:34:56', 'Unchecking after editing preserves old date');
    $repository->refresh();
    $_GET = ['lang' => 'ru', 'id' => 'qa-page']; $_POST = [];
    foreach (['pages', 'categories'] as $collection) {
        $html = $call('documentEditor', $collection, 'GET', $user);
        $check(strpos($html, 'name="title"') < strpos($html, 'name="body"') && strpos($html, 'name="body"') < strpos($html, 'name="slug"'), 'Editor first: ' . $collection);
        $check(!str_contains($html, 'data-date-permission checked') && substr_count($html, 'data-date-part=') === 6, 'Date GUI has six fields and no default permission');
    }
    $seo->saveSitemapSettings(['enabled' => true, 'include_home' => true, 'include_documents' => true, 'include_lastmod' => true]);
    $check(str_contains($seo->sitemapXml(), '<lastmod>2024-02-29T12:34:56+02:00</lastmod>'), 'Sitemap uses actual updated_at with zone');
    $seo->saveSitemapSettings(['enabled' => true, 'include_home' => true, 'include_documents' => true, 'include_lastmod' => false]);
    $check(!str_contains($seo->sitemapXml(), '<lastmod>'), 'Existing lastmod choice respected');
    $settings = ['home' => ['ru' => ['title' => 'Русский title', 'description' => 'RU description'], 'ro' => ['title' => 'Titlu RO', 'description' => '']]];
    $meta = \Mur\Core\SeoManager::homeMeta($settings, $config['site'], $config['languages'], 'ro');
    $check($meta === ['title' => 'Titlu RO', 'description' => 'RU description'], 'Home SEO resolves each field per language');
    $meta = \Mur\Core\SeoManager::homeMeta([], $config['site'], $config['languages'], 'ro');
    $check($meta['title'] === $config['site']['name'], 'Legacy home SEO fallback');
    $menu = new \Mur\Core\Menu(mur_path('menus'), $repository);
    $template = (new \Mur\Core\TemplateManager(mur_engine_path('templates'), $config['theme'], $config['site'], $config['languages'], $repository, $menu, $hooks))->load();
    $renderer = new \Mur\Core\Renderer($config['site'], $config['languages'], $repository, $menu, $hooks, $template, $config['theme'], $settings);
    $homeHtml = $renderer->renderHome('ro');
    $check(str_contains($homeHtml, '<title>Titlu RO</title>') && str_contains($homeHtml, 'content="RU description"'), 'Renderer consumes per-language home SEO config');
    $ordinary = new \Mur\Core\Document('pages', 'ru', $file, 'qa-page.mur.md', ['id' => 'qa-article', 'title' => 'QA', 'updated_at' => '2024-02-29 12:34:56'], 'Body', 'qa-article');
    $check(!str_contains($renderer->renderDocument($ordinary), 'dateModified'), 'Ordinary pages do not receive article modified metadata');
    $article = new \Mur\Core\Document('pages', 'ru', $file, 'qa-page.mur.md', $ordinary->meta + ['seo.type' => 'Article'], 'Body', 'qa-article');
    $articleHtml = $renderer->renderDocument($article);
    $check(str_contains($articleHtml, 'article:modified_time') && str_contains($articleHtml, '"dateModified":"2024-02-29T12:34:56+02:00"'), 'Explicit Article receives valid modified-date JSON-LD');
    $_POST = ['_csrf' => $token, 'lang' => 'all', 'name' => 'qa-snippet', 'status' => 'published', 'type' => 'html', 'extra_meta' => "parser: murtext\nmurtext.pretty: 1\ncustom.future: keep", 'body' => '__ Snippet']; $_GET = [];
    $call('saveSnippet', $user);
    $snippetFile = mur_path('snippets/qa-snippet.mur.md');
    $first = file_get_contents($snippetFile);
    $call('saveSnippet', $user);
    $snippet = $parser->parse($snippetFile, 'snippet', 'all');
    $check($snippet->meta['parser'] === 'murtext' && $snippet->meta['custom.future'] === 'keep' && file_get_contents($snippetFile) === $first, 'Snippet round trip preserves metadata before separator');
    $html = $hooks->filter('snippets.render', '', ['id' => 'qa-snippet', 'language' => '../config']);
    $check(str_contains($html, '<div>Snippet</div>'), 'Snippets API uses MurText and safe language fallback');
    $_GET = ['lang' => 'all', 'name' => 'qa-snippet']; $_POST = [];
    $html = $call('snippetsEditor', 'GET', $user);
    $check(strpos($html, 'name="name"') < strpos($html, 'name="body"') && strpos($html, 'name="body"') < strpos($html, 'name="lang"'), 'Snippet name and body first');
    $check(strpos($html, '>Редактор</a>') < strpos($html, '>Мои сниппеты</a>') && strpos($html, '>Системные</a>') < strpos($html, '>Загрузка файла</a>'), 'Four tabs ordered');
    $_GET = ['tab' => 'mine'];
    $check(!str_contains($call('snippetsEditor', 'GET', $user), '<th>Назначение</th>'), 'Mine list omits purpose');
    $_GET = ['tab' => 'upload'];
    $html = $call('snippetsEditor', 'GET', $user);
    $check(substr_count($html, 'type="radio" name="language"') === 3 && str_contains($html, 'Global'), 'Upload has language / Global radio controls');
    $_GET = ['tab' => 'system', 'system_scope' => 'ro'];
    $html = $call('snippetsEditor', 'GET', $user);
    $check(substr_count($html, 'data-system-snippet=') === 11 && !str_contains($html, '<select name="system_scope"'), 'System positions persist with one-click scope links');
    $check($call('requiredRole', 'template/functions') === 'super_admin' && $call('requiredRole', 'content/snippets/delete') === 'admin', 'Sensitive routes enforce roles');
    $nav = $call('navGroups', $user);
    $pluginGroup = array_values(array_filter($nav, static fn ($group) => $group['label'] === 'Плагины'))[0];
    $check($pluginGroup['items'][0]['id'] === 'plugins', 'Plugin management first');
    $functions = new \Mur\Core\SiteFunctions(mur_data_path(), 'default');
    $functions->save("<?php\nfunction qa_original() {}\n");
    $functions->save("<?php\nfunction qa_current() {}\n");
    $check($functions->hasBackup(), 'Site PHP backup created');
    $check($throws(fn () => $functions->save('<?php function broken(')) && str_contains($functions->read(), 'qa_current'), 'Invalid PHP rejected without replacing working file');
    $check($throws(fn () => $functions->save('<?php_not_an_open_tag')), 'PHP-looking plain text is rejected');
    $functions->restore();
    $check(str_contains($functions->read(), 'qa_original'), 'Site PHP previous revision restored');
    $functions->load();
    $functions->load();
    $check(function_exists('Mur\\Core\\qa_original') || function_exists('qa_original'), 'Site PHP require_once executes once');
    $check($throws(fn () => new \Mur\Core\SiteFunctions(mur_data_path(), '../other')), 'Site PHP rejects template traversal');
    $check(\Mur\Core\TemplateManager::resolvedName(mur_engine_path('templates'), ['active' => 'missing']) === 'default', 'Site PHP shares real template fallback');
    $before = $functions->read(); $_POST = ['_csrf' => 'wrong', 'code' => '<?php function hacked() {}'];
    $call('siteFunctionsEditor', 'POST', $user);
    $check($functions->read() === $before, 'Site PHP CSRF rejection');
    $_POST['_csrf'] = $token;
    $call('siteFunctionsEditor', 'POST', array_replace($user, ['role' => 'admin']));
    $check($functions->read() === $before, 'Site PHP role rejection');
    $_POST = ['_csrf' => $token, 'file' => 'content/ru/home/hero.mur.md', 'title' => 'Home title', 'extra_meta' => "parser: murtext\ncustom.future: home", 'content' => '__ Home body']; $_GET = ['file' => $_POST['file']];
    $collection = $call('fileCollection', 'home');
    $call('managedFileEditor', $collection, 'POST', $user);
    $home = $parser->parse(mur_path($_POST['file']), 'home', 'ru');
    $check($home->meta['parser'] === 'murtext' && $home->meta['custom.future'] === 'home' && $home->body === "__ Home body\n", 'Home editor stores metadata separately from body');
    $murText = new \MurCMS\MurTextPlugin\AdminUi($context, mur_engine_path('plugins/murtext'), new \MurCMS\MurTextPlugin\ConfigStore($context, mur_engine_path('plugins/murtext')));
    $html = $murText->fullHelp(null, ['user' => $user]);
    $check(str_contains($html, 'Однострочный DIV') && !str_contains($html, '<script>'), 'Full help available as escaped read-only text');
    require_once mur_engine_path('plugins/murtext/core/murtext.php');
    foreach (['__ Plain' => '<div>Plain</div>', '__(notice) Notice' => '<div class="notice">Notice</div>', '__bold__' => '<strong>bold</strong>', "details\nsummary More\nText\n/details" => '<details>', "table\n|: Head :|\n| Value |\n/table" => '<table>'] as $source => $expected) {
        $check(str_contains(\MurCMS\MurText\MurText::toHtml($source), $expected), 'Verified MurText help syntax: ' . $source);
    }
    $check(\MurCMS\MurText\MurText::version() === '0.2.0', 'Parser core unchanged');
    $banerinaStore = new \MurCMS\BanerinaPlugin\ConfigStore($context, mur_engine_path('plugins/banerina'));
    $gateway = new \MurCMS\BanerinaPlugin\SnippetGateway($context);
    $runtime = new \MurCMS\BanerinaPlugin\BanerinaPlugin($context, $banerinaStore, $gateway);
    $banerinaAdmin = new \MurCMS\BanerinaPlugin\AdminUi($context, $banerinaStore, $gateway, $runtime, new \MurCMS\BanerinaPlugin\Translator(mur_engine_path('plugins/banerina/languages')));
    $groupsPanel = new ReflectionMethod($banerinaAdmin, 'groupsPanel');
    foreach ([0, 1, 12] as $count) {
        $ids = $count === 0 ? [] : array_map(static fn ($n) => 'qa-' . $n, range(1, $count));
        $html = $groupsPanel->invoke($banerinaAdmin, ['qa' => ['id' => 'qa', 'name' => 'QA', 'active' => $count !== 0, 'snippets' => $ids]], '', $user);
        $check(str_contains($html, '<strong>' . $count . '</strong>'), 'Banerina bold count: ' . $count);
        foreach ($ids as $id) { $check(str_contains($html, '<code>' . $id . '</code>'), 'Banerina shows ' . $id); }
        $check(str_contains($html, 'aria-label=') && str_contains($html, '#admin-icon-'), 'Banerina accessible actions / status');
    }
} finally {
    if (session_status() === PHP_SESSION_ACTIVE) { session_destroy(); }
    $resolved = realpath($fixture);
    $allowed = realpath($project . '/_dist');
    if ($resolved !== false && $allowed !== false && str_starts_with($resolved, $allowed . DIRECTORY_SEPARATOR)) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($resolved, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $entry) { $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname()); }
        rmdir($resolved);
    }
}
foreach ($failures as $failure) { fwrite(STDERR, 'FAIL: ' . $failure . "\n"); }
echo 'Release 0.4.2 checks: ' . $checks . ', failures: ' . count($failures) . ".\n";
exit($failures === [] ? 0 : 1);
