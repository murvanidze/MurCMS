<?php
declare(strict_types=1);

namespace Mur\Admin;

if (!defined('MUR_CMS_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('No direct script access allowed.');
}

use Mur\Core\ContentRepository;
use Mur\Core\Document;
use Mur\Core\DocumentParser;
use Mur\Core\Hooks;
use Mur\Core\PluginManager;
use Mur\Core\SeoManager;

final class AdminRouter
{
    private const CUSTOM_CRITICAL_CSS_LIMIT = 65536;
    private const CUSTOM_ADDITIONAL_CSS_LIMIT = 262144;

    private DocumentParser $parser;
    private AdminMediaManager $media;
    private AdminFileManager $files;
    private AdminUserManager $users;

    public function __construct(
        private array $site,
        private array $languages,
        private array $adminConfig,
        private array $themeConfig,
        private readonly ContentRepository $content,
        private readonly Hooks $hooks,
        private readonly PluginManager $plugins,
        private readonly SeoManager $seo,
        private readonly AdminAuth $auth,
        private readonly AdminI18n $i18n,
        private readonly AdminRenderer $renderer,
    ) {
        $this->parser = new DocumentParser();
        $this->media = new AdminMediaManager(mur_uploads_path());
        $this->files = new AdminFileManager(mur_data_path());
        $this->users = new AdminUserManager((array) ($this->adminConfig['users'] ?? []));
    }

    public function matches(string $requestUri): bool
    {
        $path = parse_url($requestUri, PHP_URL_PATH);
        $path = is_string($path) ? '/' . trim($path, '/') : '/';

        return $path === '/admin' || str_starts_with($path, '/admin/');
    }

    public function handle(string $requestUri): void
    {
        $this->auth->start();
        $this->securityHeaders();
        $path = parse_url($requestUri, PHP_URL_PATH);
        $path = is_string($path) ? '/' . trim($path, '/') : '/';
        $route = trim(substr($path, strlen('/admin')), '/');
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

        if ($route === 'login') {
            $this->i18n->setLanguage((string) ($this->adminConfig['ui']['default_language'] ?? 'ru'));
            echo $this->login($method);
            return;
        }

        if ($route === 'logout') {
            $this->logout($method);
            return;
        }

        $user = $this->auth->user();

        if ($user === null) {
            $this->redirect('/admin/login');
            return;
        }

        $user['csrf'] = $this->auth->csrfToken();
        $this->i18n->setLanguage((string) ($user['admin_language'] ?? $this->adminConfig['ui']['default_language'] ?? 'ru'));
        $route = $route === '' ? 'dashboard' : $route;

        if (str_ends_with($route, '/download')) {
            $this->downloadRoute($route, $user);
            return;
        }

        echo $this->dispatch($route, $user, $method);
    }

    private function login(string $method): string
    {
        if ($this->auth->isLoggedIn()) {
            $this->redirect('/admin/');
            return '';
        }

        if ($method !== 'POST') {
            return $this->renderer->login('', $this->auth->csrfToken());
        }

        $token = (string) ($_POST['_csrf'] ?? '');
        $login = trim((string) ($_POST['login'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');

        if (!$this->auth->verifyCsrf($token)) {
            return $this->renderer->login('Сессия устарела. Обновите страницу и попробуйте снова.', $this->auth->csrfToken(), $login);
        }

        if (!$this->auth->canAttemptLogin()) {
            return $this->renderer->login('Слишком много попыток входа. Подождите несколько минут.', $this->auth->csrfToken(), $login);
        }

        if ($this->auth->login($login, $password)) {
            $this->redirect('/admin/');
            return '';
        }

        return $this->renderer->login('Неверный логин/e-mail или пароль.', $this->auth->csrfToken(), $login);
    }

    private function logout(string $method): void
    {
        if ($method === 'POST' && $this->auth->verifyCsrf((string) ($_POST['_csrf'] ?? ''))) {
            $this->auth->logout();
        }

        $this->redirect('/admin/login');
    }

    private function dispatch(string $route, array $user, string $method): string
    {
        $requiredRole = $this->requiredRole($route);
        $nav = $this->navGroups($user);

        if (!$this->auth->can($user, $requiredRole)) {
            return $this->renderer->errorPage(403, 'Доступ запрещен', 'Для этого раздела нужны более высокие права доступа.', $nav, $user);
        }

        return match (true) {
            $route === 'dashboard' => $this->renderer->page('Dashboard', $this->dashboard(), $nav, $user, 'dashboard'),
            $route === 'seo' => $this->renderer->page('SEO', $this->seoOverview($method, $user), $nav, $user, 'seo'),
            $route === 'seo/sitemap' => $this->renderer->page('XML-карта сайта', $this->seoSitemap($method, $user), $nav, $user, 'seo-sitemap'),
            $route === 'seo/robots' => $this->renderer->page('robots.txt', $this->seoRobots($method, $user), $nav, $user, 'seo-robots'),
            $route === 'seo/htaccess' => $this->renderer->page('.htaccess', $this->seoHtaccess($method, $user), $nav, $user, 'seo-htaccess'),
            $route === 'settings/site' => $this->renderer->page('Настройки сайта', $this->siteSettings($method, $user), $nav, $user, 'settings-site'),
            $route === 'settings/theme' || $route === 'template' => $this->renderer->page('Шаблон: общие настройки', $this->themeSettings($method, $user, 'general'), $nav, $user, 'template-general'),
            $route === 'template/functions' => $this->renderer->page('PHP-функции', $this->siteFunctionsEditor($method, $user), $nav, $user, 'template-functions'),
            $route === 'template/home' => $this->renderer->page('Главная страница', $this->themeSettings($method, $user, 'home'), $nav, $user, 'template-home'),
            $route === 'template/footer' => $this->renderer->page('Футер', $this->themeSettings($method, $user, 'footer'), $nav, $user, 'template-footer'),
            $route === 'settings/theme/menu' => $this->renderer->page('Редактор меню', $this->genericFileEditor($method, $user, 'menus'), $nav, $user, 'template-general'),
            $route === 'settings/languages' => $this->renderer->page('Настройки фронтенда', $this->languageSettings($method, $user), $nav, $user, 'settings-languages'),
            $route === 'settings/admin' => $this->renderer->page('Настройки админ-панели', $this->adminSettings($method, $user), $nav, $user, 'settings-admin'),
            $route === 'settings/profile' => $this->renderer->page('Мой профиль', $this->profileSettings($method, $user), $nav, $user, 'settings-profile'),
            $route === 'settings/users' => $this->renderer->page('Пользователи', $this->usersPage($user), $nav, $user, 'settings-users'),
            $route === 'settings/users/edit' => $this->renderer->page('Пользователь', $this->userEditor($method, $user), $nav, $user, 'settings-users'),
            $route === 'settings/users/delete' => $this->renderer->page('Удалить пользователя', $this->userDeleteConfirm($method, $user), $nav, $user, 'settings-users'),
            $route === 'content/pages' => $this->renderer->page('Страницы', $this->documentsTable('pages', $method, $user), $nav, $user, 'content-pages'),
            $route === 'content/pages/edit' => $this->renderer->page('Редактор страницы', $this->documentEditor('pages', $method, $user), $nav, $user, 'content-pages'),
            $route === 'content/pages/delete' => $this->renderer->page('Удалить страницу', $this->deleteDocumentConfirm('pages', $method, $user), $nav, $user, 'content-pages'),
            $route === 'content/pages/clone' => $this->renderer->page('Клонировать страницу', $this->cloneDocumentForm('pages', $method, $user), $nav, $user, 'content-pages'),
            $route === 'content/categories' => $this->renderer->page('Категории', $this->documentsTable('categories', $method, $user), $nav, $user, 'content-categories'),
            $route === 'content/categories/edit' => $this->renderer->page('Редактор категории', $this->documentEditor('categories', $method, $user), $nav, $user, 'content-categories'),
            $route === 'content/categories/delete' => $this->renderer->page('Удалить категорию', $this->deleteDocumentConfirm('categories', $method, $user), $nav, $user, 'content-categories'),
            $route === 'content/categories/clone' => $this->renderer->page('Клонировать категорию', $this->cloneDocumentForm('categories', $method, $user), $nav, $user, 'content-categories'),
            $route === 'files/edit' => $this->managedFilePage('edit', $method, $nav, $user),
            $route === 'files/delete' => $this->managedFilePage('delete', $method, $nav, $user),
            $route === 'files/upload' => $this->fileUploadAction($method, $nav, $user),
            $route === 'files/replace' => $this->fileReplaceAction($method, $nav, $user),
            $route === 'content/snippets' => $this->renderer->page('Ушки и snippets', $this->snippetsEditor($method, $user), $nav, $user, 'content-snippets'),
            $route === 'content/snippets/delete' => $this->renderer->page('Удалить snippet', $this->genericDeleteConfirm($method, $user, 'snippets'), $nav, $user, 'content-snippets'),
            $route === 'media' => $this->renderer->page('Файлы и изображения', $this->mediaManager($method, $user), $nav, $user, 'media'),
            $route === 'media/delete' => $this->renderer->page('Удалить файл', $this->mediaDelete($method, $user), $nav, $user, 'media'),
            $route === 'plugins' => $this->renderer->page('Плагины', $this->pluginsPage($method, $user), $nav, $user, 'plugins'),
            str_starts_with($route, 'plugin/') => $this->pluginRoute($route, $method, $nav, $user),
            default => $this->renderer->errorPage(404, 'Раздел не найден', 'Такого раздела админки пока нет.', $nav, $user),
        };
    }

    private function requiredRole(string $route): string
    {
        if ($route === 'template/functions') {
            return 'super_admin';
        }

        if ($route === 'settings/profile') {
            return 'editor';
        }

        if (str_starts_with($route, 'plugin/')) {
            $pluginRoute = $this->pluginRouteDefinition($route);
            if ($pluginRoute !== null) {
                return $pluginRoute['role'];
            }
        }

        if (
            $route === 'plugins'
            || $route === 'seo'
            || str_starts_with($route, 'content/snippets')
            || str_starts_with($route, 'seo/')
            || str_starts_with($route, 'settings/')
            || $route === 'template'
            || str_starts_with($route, 'template/')
        ) {
            return 'admin';
        }

        return 'editor';
    }

    private function navGroups(array $user): array
    {
        $groups = [
            [
                'label' => 'Основное',
                'items' => [
                    ['id' => 'dashboard', 'label' => 'Dashboard', 'url' => '/admin/'],
                    ['id' => 'frontend', 'label' => 'Frontend', 'url' => '/', 'target' => '_blank'],
                ],
            ],
            [
                'label' => 'Контент',
                'items' => [
                    ['id' => 'content-pages', 'label' => 'Страницы', 'url' => '/admin/content/pages'],
                    ['id' => 'content-categories', 'label' => 'Категории', 'url' => '/admin/content/categories'],
                    ['id' => 'content-snippets', 'label' => 'Ушки / snippets', 'url' => '/admin/content/snippets', 'role' => 'admin'],
                    ['id' => 'media', 'label' => 'Файлы', 'url' => '/admin/media'],
                ],
            ],
            [
                'label' => 'SEO',
                'items' => [
                    ['id' => 'seo', 'label' => 'Обзор', 'url' => '/admin/seo', 'role' => 'admin'],
                    ['id' => 'seo-sitemap', 'label' => 'XML-карта сайта', 'url' => '/admin/seo/sitemap', 'role' => 'admin'],
                    ['id' => 'seo-robots', 'label' => 'robots.txt', 'url' => '/admin/seo/robots', 'role' => 'admin'],
                    ['id' => 'seo-htaccess', 'label' => '.htaccess', 'url' => '/admin/seo/htaccess', 'role' => 'admin'],
                ],
            ],
            [
                'label' => 'Настройки',
                'items' => [
                    ['id' => 'settings-site', 'label' => 'Сайт', 'url' => '/admin/settings/site', 'role' => 'admin'],
                    ['id' => 'settings-languages', 'label' => 'Настройки фронтенда', 'url' => '/admin/settings/languages', 'role' => 'admin'],
                    ['id' => 'settings-admin', 'label' => 'Админка', 'url' => '/admin/settings/admin', 'role' => 'admin'],
                    ['id' => 'settings-users', 'label' => 'Пользователи', 'url' => '/admin/settings/users', 'role' => 'admin'],
                ],
            ],
            [
                'label' => 'Шаблон',
                'items' => [
                    ['id' => 'template-general', 'label' => 'Общие настройки', 'url' => '/admin/template', 'role' => 'admin'],
                    ['id' => 'template-home', 'label' => 'Главная страница', 'url' => '/admin/template/home', 'role' => 'admin'],
                    ['id' => 'template-functions', 'label' => 'PHP-функции', 'url' => '/admin/template/functions', 'role' => 'super_admin'],
                    ['id' => 'template-footer', 'label' => 'Футер', 'url' => '/admin/template/footer', 'role' => 'admin'],
                ],
            ],
        ];

        $pluginItems = $this->hooks->filter('admin.menu', [], ['user' => $user]);

        $groups[] = [
            'label' => 'Плагины',
            'items' => array_merge([
                ['id' => 'plugins', 'label' => 'Управление плагинами', 'url' => '/admin/plugins', 'role' => 'admin'],
            ], is_array($pluginItems) ? $pluginItems : []),
        ];

        foreach ($groups as &$group) {
            $group['items'] = array_values(array_filter($group['items'], function (array $item) use ($user): bool {
                $role = (string) ($item['role'] ?? 'editor');
                return $this->auth->can($user, $role);
            }));
        }
        unset($group);

        return array_values(array_filter($groups, static fn (array $group): bool => ($group['items'] ?? []) !== []));
    }

    private function faviconField(string $surface): string
    {
        $key = 'favicon_' . $surface;
        $current = mur_favicon($this->site, $surface);
        return '<label>' . ($surface === 'admin' ? 'Admin favicon' : 'Favicon фронтенда') . '<input name="' . $key . '" value="' . mur_escape((string) ($this->site[$key] ?? '')) . '" placeholder="branding/favicon.svg"><span class="admin-help">Путь относительно uploads, PNG/SVG/WebP. ' . ($surface === 'frontend' ? 'Пустое поле: favicon* в корне uploads, затем иконка MurCMS.' : 'Пустое поле: иконка MurCMS.') . ' Сейчас: <code>' . mur_escape((string) ($current['url'] ?? '')) . '</code></span></label>';
    }

    private function saveFavicon(string $surface): void
    {
        $key = 'favicon_' . $surface;
        if (isset($_POST[$key]) && is_string($_POST[$key])) {
            $this->site[$key] = mur_favicon_config_path($_POST[$key]);
            $this->writeConfigFile('config/site.php', $this->site);
        }
    }

    private function siteFunctionsEditor(string $method, array $user): string
    {
        $template = \Mur\Core\TemplateManager::resolvedName(mur_engine_path('templates'), $this->themeConfig);
        $functions = new \Mur\Core\SiteFunctions(mur_data_path(), $template);
        $notice = '';
        $submitted = null;
        try {
            if ($method === 'POST') {
                $notice = $this->guardPost($user, 'super_admin');
                if ($notice !== '') {
                    return $notice;
                }
                if (($_POST['action'] ?? '') === 'restore') {
                    $functions->restore();
                    $notice = $this->notice('Предыдущая редакция восстановлена.');
                } else {
                    $submitted = (string) ($_POST['code'] ?? '');
                    $functions->save($submitted);
                    $notice = $this->notice('PHP-функции сохранены. Синтаксис проверен без исполнения.');
                }
            }
            $code = $submitted ?? $functions->read();
            $restore = $functions->hasBackup() ? '<button class="admin-button admin-button--ghost" name="action" value="restore" type="submit">Восстановить предыдущую редакцию</button>' : '';
        } catch (\Throwable $exception) {
            $notice = $this->notice($exception->getMessage(), true);
            $code = $submitted ?? '';
            $restore = '';
        }
        return $notice . '<section class="admin-panel"><h2>PHP-функции — ' . mur_escape($template) . '</h2><p><code>data/templates/' . mur_escape($template) . '/functions.php</code></p><p class="admin-help">Доверенный PHP только для super_admin. Подключается через require_once на публичном сайте перед шаблоном; админка этот файл не исполняет. Проверка синтаксиса не выявляет ошибки времени выполнения. Предыдущая редакция сохраняется вне дистрибутива.</p><form class="admin-form" method="post" action="/admin/template/functions"><input type="hidden" name="_csrf" value="' . mur_escape((string) $user['csrf']) . '"><label class="admin-field-full">PHP<textarea name="code" rows="24" spellcheck="false">' . mur_escape($code) . '</textarea></label><div class="admin-actions"><button class="admin-button" name="action" value="save" type="submit">Проверить и сохранить</button>' . $restore . '</div></form></section>';
    }

    private function updatedAtFields(array $meta): string
    {
        $timezone = (string) ($this->site['timezone'] ?? 'UTC');
        $now = new \DateTimeImmutable('now', new \DateTimeZone($timezone));
        $saved = \Mur\Core\DocumentDates::parse((string) ($meta['updated_at'] ?? ''), $timezone);
        $date = $saved?->setTimezone(new \DateTimeZone($timezone)) ?? $now;
        $fields = '';
        foreach (['year' => ['Год', 'Y', 1, 9999], 'month' => ['Месяц', 'm', 1, 12], 'day' => ['День', 'd', 1, 31], 'hour' => ['Часы', 'H', 0, 23], 'minute' => ['Минуты', 'i', 0, 59], 'second' => ['Секунды', 's', 0, 59]] as $key => [$label, $format, $min, $max]) {
            $value = (int) $date->format($format);
            if ($key === 'year' || $key === 'second') {
                $control = '<input name="updated[' . $key . ']" data-date-part="' . $key . '" type="number" min="' . $min . '" max="' . $max . '" step="1" value="' . $value . '">';
            } else {
                $options = [];
                for ($i = $min; $i <= $max; $i++) {
                    $options[(string) $i] = sprintf('%02d', $i);
                }
                $control = '<select name="updated[' . $key . ']" data-date-part="' . $key . '">' . $this->selectOptions($options, (string) $value) . '</select>';
            }
            $fields .= '<label>' . $label . $control . '</label>';
        }
        return '<fieldset class="admin-updated-at admin-field-full" data-updated-at data-timezone="' . mur_escape($timezone) . '" data-site-now="' . $now->getTimestamp() . '"><legend>Дата изменения</legend><div class="admin-date-fields">' . $fields . '</div><label class="admin-check"><input type="checkbox" name="update_updated_at" value="1" data-date-permission><span>Обновить дату изменения при сохранении</span></label><div class="admin-date-now"><button class="admin-button admin-button--ghost" type="button" data-date-now>Сейчас</button><span class="admin-help">Сейчас на сайте: <time data-site-clock>' . $now->format('Y-m-d H:i:s') . '</time> (' . mur_escape($timezone) . ')</span></div><p class="admin-help">Галочка разрешает запись показанных полей. Без неё сохранённая дата не меняется; отсутствующая дата создаётся при первом сохранении.</p></fieldset>';
    }

    private function documentModifiedCell(Document $document): string
    {
        $value = trim((string) ($document->meta['updated_at'] ?? ''));
        if ($value !== '') {
            return '<time>' . mur_escape($value) . '</time><small class="admin-help">updated_at</small>';
        }
        $mtime = is_file($document->filePath) ? filemtime($document->filePath) : false;
        return ($mtime !== false ? mur_escape(date('Y-m-d H:i:s', $mtime)) : '—') . '<small class="admin-help">Файл · legacy fallback</small>';
    }

    private function dashboard(): string
    {
        $pages = 0;

        foreach (($this->languages['enabled'] ?? []) as $language) {
            foreach ($this->content->all((string) $language) as $document) {
                if ($document->collection === 'pages') {
                    $pages++;
                }
            }
        }

        $homeBlocks = 0;
        $footerBlocks = 0;
        foreach ($this->orderedLanguages() as $language) {
            $homeBlocks += count($this->themeBlockFiles('home', $language));
            $footerBlocks += count($this->themeBlockFiles('footer', $language));
        }

        $sitemapUrls = count($this->seo->sitemapUrls());
        $plugins = count($this->plugins->loaded());

        return '<section class="admin-grid admin-grid--stats">
  <article class="admin-panel" data-dashboard-stat="pages"><strong>' . $pages . '</strong><span>Страницы</span></article>
  <article class="admin-panel" data-dashboard-stat="home-blocks"><strong>' . $homeBlocks . '</strong><span>Блоки главной</span></article>
  <article class="admin-panel" data-dashboard-stat="footer-blocks"><strong>' . $footerBlocks . '</strong><span>Блоки футера</span></article>
  <article class="admin-panel" data-dashboard-stat="sitemap-urls"><strong>' . $sitemapUrls . '</strong><span>URL в sitemap</span></article>
  <article class="admin-panel" data-dashboard-stat="plugins"><strong>' . $plugins . '</strong><span>Активные плагины</span></article>
  <article class="admin-panel" data-dashboard-stat="cms-version"><strong>' . mur_escape(MUR_CMS_VERSION) . '</strong><span>Версия MurCMS</span></article>
</section>';
    }

    private function documentsTable(string $collection, string $method, array $user): string
    {
        $notice = $method === 'POST' ? $this->documentTablePost($collection, $user) : '';
        $title = $collection === 'categories' ? 'Категории' : 'Страницы';
        $pageFilter = $collection === 'pages' ? $this->pageListFilter() : [];
        $pageBulkLanguage = $collection === 'pages' ? (string) ($pageFilter['language'] ?? '') : '';
        $pageBulkEnabled = $collection === 'pages' && $pageBulkLanguage !== '';
        $totalDocuments = 0;
        $documentsByLanguage = [];
        $selectedPageLookup = $pageBulkEnabled
            ? array_flip($this->postedSelection('selected'))
            : [];

        foreach ($this->orderedLanguages() as $language) {
            $documents = array_values(array_filter(
                $this->content->all((string) $language),
                static fn (Document $document): bool => $document->collection === $collection,
            ));
            $totalDocuments += count($documents);

            if ($collection === 'pages') {
                if (($pageFilter['language'] ?? '') !== '' && $pageFilter['language'] !== $language) {
                    continue;
                }

                $documents = array_values(array_filter(
                    $documents,
                    fn (Document $document): bool => $this->pageMatchesListFilter($document, $pageFilter),
                ));
            }

            $documentsByLanguage[(string) $language] = $collection === 'categories'
                ? $this->hierarchyRows($documents)
                : array_map(static fn (Document $document): array => [$document, 0], $this->content->sort($documents, 'order', 'asc'));
        }

        $matchedDocuments = 0;
        $pagePagination = [];
        if ($collection === 'pages') {
            $flatRows = [];
            foreach ($documentsByLanguage as $language => $documentRows) {
                foreach ($documentRows as [$document, $depth]) {
                    $flatRows[] = [$language, $document, $depth];
                }
            }

            $matchedDocuments = count($flatRows);
            $pagePagination = $this->listPagination($matchedDocuments);
            $visibleRows = (int) $pagePagination['per_page_count'] > 0
                ? array_slice($flatRows, (int) $pagePagination['offset'], (int) $pagePagination['per_page_count'])
                : $flatRows;

            if ($matchedDocuments > 0) {
                $documentsByLanguage = [];
                foreach ($visibleRows as [$language, $document, $depth]) {
                    $documentsByLanguage[$language][] = [$document, $depth];
                }
            }
        }

        $sections = '';
        foreach ($documentsByLanguage as $language => $documentRows) {
            $rows = '';

            foreach ($documentRows as [$document, $depth]) {
                $url = '/' . $language . '/' . $document->urlPath;
                $query = 'lang=' . rawurlencode((string) $language) . '&id=' . rawurlencode($document->id());
                $treeDepth = max(0, min(8, (int) $depth));
                $titleCell = '<span class="admin-tree-label' . ($treeDepth > 0 ? ' is-child' : '') . '">' . mur_escape($document->title()) . '</span>';
                $selectionValue = (string) $language . ':' . $document->id();
                $selectionCell = $pageBulkEnabled
                    ? '<td class="admin-document-select"><input type="checkbox" name="selected[]" value="' . mur_escape($selectionValue) . '" aria-label="' . mur_escape('Выбрать страницу «' . $document->title() . '»') . '"' . (isset($selectedPageLookup[$selectionValue]) ? ' checked' : '') . '></td>'
                    : '';
                $rows .= '<tr data-document-id="' . mur_escape($document->id()) . '">' . $selectionCell . '<td class="admin-document-cell"><div class="admin-document-content admin-tree-depth-' . $treeDepth . '">' . $titleCell
                    . '<code class="admin-document-id">' . mur_escape($document->id()) . '</code>'
                    . '<a class="admin-document-url" href="' . mur_escape($url) . '" target="_blank" rel="noreferrer"><code>' . mur_escape($url) . '</code></a>'
                    . '</div></td>' . ($collection === 'pages' ? '<td class="admin-document-modified">' . $this->documentModifiedCell($document) . '</td>' : '') . '<td class="admin-document-status">' . $this->statusIcon($document->status()) . '</td><td class="admin-document-actions">' . $this->toolbar([
                    ['label' => 'Редактировать', 'url' => '/admin/content/' . $collection . '/edit?' . $query],
                    ['label' => 'Клонировать', 'url' => '/admin/content/' . $collection . '/clone?' . $query],
                    ['label' => 'Скачать', 'url' => '/admin/content/' . $collection . '/download?' . $query],
                    ['label' => 'Загрузить взамен', 'url' => '#', 'attributes' => 'data-file-replace-open data-file-scope="' . mur_escape($collection) . '" data-file-path="' . mur_escape($this->relativePath($document->filePath)) . '"'],
                    ['label' => 'Удалить', 'url' => '/admin/content/' . $collection . '/delete?' . $query, 'danger' => true],
                ]) . '</td></tr>';
            }

            if ($rows === '') {
                $rows = '<tr><td colspan="' . ($collection === 'pages' ? ($pageBulkEnabled ? '5' : '4') : '3') . '">' . ($collection === 'pages' && $this->pageListFilterIsActive($pageFilter)
                    ? 'Страницы по выбранному фильтру не найдены.'
                    : 'Документы этой языковой ветки пока не найдены.') . '</td></tr>';
            }

            $newUrl = '/admin/content/' . $collection . '/edit?lang=' . rawurlencode($language);
            $selectionHeading = $pageBulkEnabled
                ? '<th class="admin-document-select"><span class="visually-hidden">Выбор</span></th>'
                : '';
            $sections .= '<section class="admin-panel admin-language-group" data-language="' . mur_escape((string) $language) . '">
  <div class="admin-panel-head"><h2>' . mur_escape($this->languageHeading($language)) . '</h2><a class="admin-button" href="' . mur_escape($newUrl) . '">' . $this->icon('add') . '<span>Создать</span></a></div>
  <table class="admin-table admin-documents-table"><thead><tr>' . $selectionHeading . '<th>Страница</th>' . ($collection === 'pages' ? '<th>Изменено</th>' : '') . '<th>Статус</th><th>Действия</th></tr></thead><tbody>' . $rows . '</tbody></table>
</section>';
        }

        $filterPanel = $collection === 'pages'
            ? $this->pageListFilterPanel($pageFilter, $pagePagination, $matchedDocuments, $totalDocuments)
                . $this->pageListPaginationLinks($pageFilter, $pagePagination, 'top')
            : '<p>Объекты разделены по языковым веткам. Основной язык показан первым.</p>';
        $bottomPagination = $collection === 'pages'
            ? $this->pageListPaginationLinks($pageFilter, $pagePagination, 'bottom')
            : '';
        if ($pageBulkEnabled) {
            $sections = '<form class="admin-bulk-form" method="post" action="' . mur_escape($this->pageListUrl($pageFilter, $pagePagination, (int) $pagePagination['page'])) . '" data-selection-scope>
  <input type="hidden" name="_csrf" value="' . mur_escape((string) $user['csrf']) . '">
  ' . $sections . '
  ' . $bottomPagination . '
  ' . $this->pageBulkControls($pageBulkLanguage) . '
  ' . $this->pageBulkDialogs($pageBulkLanguage) . '
</form>';
            $bottomPagination = '';
        }

        return $notice . '<section class="admin-panel admin-list-intro"><h2>' . mur_escape($title) . '</h2>' . $filterPanel . '</section>' . $sections . $bottomPagination
            . $this->fileUploadPanel($collection, $user)
            . $this->fileReplaceDialog($user);
    }

    private function pageListFilter(): array
    {
        $scope = trim((string) ($_GET['filter'] ?? ''));
        $status = strtolower(trim((string) ($_GET['status'] ?? '')));
        $status = in_array($status, ['published', 'draft'], true) ? $status : '';
        $languages = $this->orderedLanguages();
        $defaultLanguage = (string) ($this->languages['default'] ?? '');
        if (!in_array($defaultLanguage, $languages, true)) {
            $defaultLanguage = (string) ($languages[0] ?? '');
        }
        $filter = [
            'scope' => '',
            'type' => 'language',
            'language' => $defaultLanguage,
            'value' => '',
            'status' => $status,
        ];

        if ($scope === '') {
            return $filter;
        }

        if ($scope === 'all') {
            $filter['scope'] = 'all';
            $filter['type'] = 'all';
            $filter['language'] = '';
            return $filter;
        }

        $parts = explode(':', $scope, 3);
        $type = (string) ($parts[0] ?? '');
        $language = (string) ($parts[1] ?? '');

        if (!in_array($language, $languages, true)) {
            return $filter;
        }

        if ($type === 'language' && count($parts) === 2) {
            $filter['scope'] = $scope;
            $filter['type'] = $type;
            $filter['language'] = $language;
            return $filter;
        }

        if ($type === 'parent-root' && count($parts) === 2) {
            $filter['scope'] = $scope;
            $filter['type'] = $type;
            $filter['language'] = $language;
            return $filter;
        }

        $value = trim((string) ($parts[2] ?? ''));
        if ($value === '' || !in_array($type, ['category', 'parent'], true)) {
            return $filter;
        }

        $expectedCollection = $type === 'category' ? 'categories' : 'pages';
        $exists = false;
        foreach ($this->content->all($language) as $document) {
            if ($document->collection === $expectedCollection && $document->id() === $value) {
                $exists = true;
                break;
            }
        }

        if (!$exists) {
            return $filter;
        }

        $filter['scope'] = $scope;
        $filter['type'] = $type;
        $filter['language'] = $language;
        $filter['value'] = $value;

        return $filter;
    }

    private function pageMatchesListFilter(Document $document, array $filter): bool
    {
        $status = (string) ($filter['status'] ?? '');
        if ($status !== '' && strtolower($document->status()) !== $status) {
            return false;
        }

        return match ((string) ($filter['type'] ?? '')) {
            'category' => in_array((string) ($filter['value'] ?? ''), $document->categoryIds(), true),
            'parent' => $document->parentId() === (string) ($filter['value'] ?? ''),
            'parent-root' => $document->parentId() === '',
            default => true,
        };
    }

    private function pageListFilterIsActive(array $filter): bool
    {
        return (string) ($filter['scope'] ?? '') !== '' || (string) ($filter['status'] ?? '') !== '';
    }

    private function listPagination(int $matchedDocuments): array
    {
        $perPageValue = strtolower(trim((string) ($_GET['per_page'] ?? $_POST['per_page'] ?? 'all')));
        $perPageCount = in_array($perPageValue, ['10', '50'], true) ? (int) $perPageValue : 0;
        $perPageValue = $perPageCount > 0 ? (string) $perPageCount : 'all';
        $totalPages = $perPageCount > 0 ? max(1, (int) ceil($matchedDocuments / $perPageCount)) : 1;
        $requestedPage = max(1, (int) ($_GET['page'] ?? $_POST['page'] ?? 1));
        $page = min($requestedPage, $totalPages);
        $offset = $perPageCount > 0 ? ($page - 1) * $perPageCount : 0;
        $visibleCount = $perPageCount > 0
            ? min($perPageCount, max(0, $matchedDocuments - $offset))
            : $matchedDocuments;

        return [
            'per_page' => $perPageValue,
            'per_page_count' => $perPageCount,
            'page' => $page,
            'total_pages' => $totalPages,
            'offset' => $offset,
            'visible_count' => $visibleCount,
            'visible_start' => $visibleCount > 0 ? $offset + 1 : 0,
            'visible_end' => $visibleCount > 0 ? $offset + $visibleCount : 0,
        ];
    }

    private function pageListFilterPanel(array $filter, array $pagination, int $matched, int $total): string
    {
        $status = (string) ($filter['status'] ?? '');
        $statusOptions = '<option value=""' . ($status === '' ? ' selected' : '') . '>Все статусы</option>'
            . '<option value="published"' . ($status === 'published' ? ' selected' : '') . '>Опубликованные</option>'
            . '<option value="draft"' . ($status === 'draft' ? ' selected' : '') . '>Черновики</option>';
        $perPage = (string) ($pagination['per_page'] ?? 'all');
        $perPageOptions = '<option value="all"' . ($perPage === 'all' ? ' selected' : '') . '>Все</option>'
            . '<option value="50"' . ($perPage === '50' ? ' selected' : '') . '>50</option>'
            . '<option value="10"' . ($perPage === '10' ? ' selected' : '') . '>10</option>';
        $reset = $this->pageListFilterIsActive($filter)
            ? '<a class="admin-button admin-button--ghost" href="' . mur_escape($this->pageListUrl([], $pagination, 1)) . '">Сбросить</a>'
            : '';
        $summary = (int) ($pagination['per_page_count'] ?? 0) > 0
            ? 'Показано: ' . (int) ($pagination['visible_start'] ?? 0) . '–' . (int) ($pagination['visible_end'] ?? 0) . ' из ' . $matched
            : 'Показано: ' . $matched . ' из ' . $total;
        if ((int) ($pagination['per_page_count'] ?? 0) > 0 && $matched !== $total) {
            $summary .= ' (всего страниц: ' . $total . ')';
        }
        $bulkNote = (string) ($filter['language'] ?? '') === ''
            ? '<p class="admin-help">Режим всех языков предназначен для просмотра. Для чекбоксов и пакетных действий выберите одну языковую версию.</p>'
            : '';

        return '<p>По умолчанию показан основной язык. Можно выбрать другой язык, все языки либо один фильтр по рубрике или родителю. Статус сочетается с любым режимом.</p>
<form class="admin-form admin-page-filters" method="get" action="/admin/content/pages">
  <div class="admin-form-grid admin-form-grid--compact">
    <label>Язык, рубрика или родитель
      <select name="filter">' . $this->pageListScopeOptions((string) ($filter['scope'] ?? '')) . '</select>
    </label>
    <label>Статус
      <select name="status">' . $statusOptions . '</select>
    </label>
    <label>На странице
      <select name="per_page">' . $perPageOptions . '</select>
    </label>
  </div>
  <div class="admin-actions"><button class="admin-button" type="submit">Применить</button>' . $reset . '<span class="admin-filter-summary">' . $summary . '</span></div>
</form>' . $bulkNote;
    }

    private function pageListPaginationLinks(array $filter, array $pagination, string $position): string
    {
        $page = (int) ($pagination['page'] ?? 1);
        $totalPages = (int) ($pagination['total_pages'] ?? 1);
        if ($totalPages <= 1) {
            return '';
        }

        $pageNumbers = [1, $totalPages];
        for ($number = max(1, $page - 2); $number <= min($totalPages, $page + 2); $number++) {
            $pageNumbers[] = $number;
        }
        $pageNumbers = array_values(array_unique($pageNumbers));
        sort($pageNumbers);

        $links = '';
        if ($page > 1) {
            $links .= '<a href="' . mur_escape($this->pageListUrl($filter, $pagination, $page - 1)) . '" rel="prev">Назад</a>';
        }

        $previousNumber = 0;
        foreach ($pageNumbers as $number) {
            if ($previousNumber > 0 && $number > $previousNumber + 1) {
                $links .= '<span class="admin-pagination-gap" aria-hidden="true">…</span>';
            }
            $links .= $number === $page
                ? '<span aria-current="page">' . $number . '</span>'
                : '<a href="' . mur_escape($this->pageListUrl($filter, $pagination, $number)) . '">' . $number . '</a>';
            $previousNumber = $number;
        }

        if ($page < $totalPages) {
            $links .= '<a href="' . mur_escape($this->pageListUrl($filter, $pagination, $page + 1)) . '" rel="next">Вперёд</a>';
        }

        $label = $position === 'bottom' ? 'Пагинация списка страниц внизу' : 'Пагинация списка страниц вверху';

        return '<nav class="admin-pagination admin-pagination--' . mur_escape($position) . '" aria-label="' . mur_escape($label) . '">' . $links . '</nav>';
    }

    private function pageListUrl(array $filter, array $pagination, int $page): string
    {
        $query = [];
        if ((string) ($filter['scope'] ?? '') !== '') {
            $query['filter'] = (string) $filter['scope'];
        }
        if ((string) ($filter['status'] ?? '') !== '') {
            $query['status'] = (string) $filter['status'];
        }
        if ((string) ($pagination['per_page'] ?? 'all') !== 'all') {
            $query['per_page'] = (string) $pagination['per_page'];
        }
        if ($page > 1) {
            $query['page'] = $page;
        }

        return '/admin/content/pages' . ($query !== [] ? '?' . http_build_query($query) : '');
    }

    private function pageListScopeOptions(string $selected): string
    {
        $defaultLanguage = (string) ($this->languages['default'] ?? '');
        $defaultLabel = $this->languageHeading($defaultLanguage);
        $html = '<optgroup label="Язык"><option value=""' . ($selected === '' || $selected === 'language:' . $defaultLanguage ? ' selected' : '') . '>'
            . mur_escape($defaultLabel) . '</option>';
        $languageOptions = '';
        $categoryGroups = '';
        $parentGroups = '';

        foreach ($this->orderedLanguages() as $language) {
            if ($language === $defaultLanguage) {
                continue;
            }
            $languageValue = 'language:' . $language;
            $languageOptions .= '<option value="' . mur_escape($languageValue) . '"' . ($selected === $languageValue ? ' selected' : '') . '>'
                . mur_escape($this->languageHeading($language)) . '</option>';
        }

        $html .= $languageOptions
            . '<option value="all"' . ($selected === 'all' ? ' selected' : '') . '>Все языки (без пакетных действий)</option></optgroup>';

        foreach ($this->orderedLanguages() as $language) {
            $categories = array_values(array_filter(
                $this->content->all($language),
                static fn (Document $document): bool => $document->collection === 'categories',
            ));
            $categoryOptions = '';
            foreach ($this->hierarchyRows($categories) as [$category, $depth]) {
                $value = 'category:' . $language . ':' . $category->id();
                $label = str_repeat('— ', (int) $depth) . $category->title() . ' [' . $category->id() . ']';
                $categoryOptions .= '<option value="' . mur_escape($value) . '"' . ($selected === $value ? ' selected' : '') . '>' . mur_escape($label) . '</option>';
            }
            if ($categoryOptions !== '') {
                $categoryGroups .= '<optgroup label="' . mur_escape('Рубрики — ' . $this->languageHeading($language)) . '">' . $categoryOptions . '</optgroup>';
            }

            $pages = array_values(array_filter(
                $this->content->all($language),
                static fn (Document $document): bool => $document->collection === 'pages',
            ));
            $usedParentIds = array_flip(array_values(array_unique(array_filter(
                array_map(static fn (Document $document): string => $document->parentId(), $pages),
                static fn (string $parentId): bool => $parentId !== '',
            ))));
            $rootValue = 'parent-root:' . $language;
            $parentOptions = '<option value="' . mur_escape($rootValue) . '"' . ($selected === $rootValue ? ' selected' : '') . '>Без родителя</option>';
            foreach ($this->hierarchyRows($pages) as [$page, $depth]) {
                if (!isset($usedParentIds[$page->id()])) {
                    continue;
                }
                $value = 'parent:' . $language . ':' . $page->id();
                $label = str_repeat('— ', (int) $depth) . $page->title() . ' [' . $page->id() . ']';
                $parentOptions .= '<option value="' . mur_escape($value) . '"' . ($selected === $value ? ' selected' : '') . '>' . mur_escape($label) . '</option>';
            }
            $parentGroups .= '<optgroup label="' . mur_escape('Родительские страницы — ' . $this->languageHeading($language)) . '">' . $parentOptions . '</optgroup>';
        }

        return $html
            . $categoryGroups
            . $parentGroups;
    }

    private function documentEditor(string $collection, string $method, array $user): string
    {
        $notice = isset($_GET['saved']) ? $this->notice('Документ сохранен.') : '';

        if ($method === 'POST') {
            $notice = $this->saveDocument($collection, $user);
        }

        $language = $this->safeLanguage((string) ($_GET['lang'] ?? $_POST['lang'] ?? ($this->languages['default'] ?? 'ru')));
        $id = (string) ($_GET['id'] ?? $_POST['original_id'] ?? '');
        $document = $id !== '' ? $this->content->findById($language, $id) : null;
        $meta = $document instanceof Document ? $document->meta : $this->defaultDocumentMeta($collection);
        $body = $document instanceof Document ? $document->body : '';
        if ($method === 'POST') {
            $meta = $this->postedDocumentMeta($collection, (string) ($_POST['id'] ?? ''), $language);
            $body = (string) ($_POST['body'] ?? '');
        }
        $url = $document instanceof Document ? '/' . $language . '/' . $document->urlPath : '';
        $back = '/admin/content/' . $collection;
        $isPage = $collection === 'pages';
        $entityName = $isPage ? 'страницу' : 'категорию';
        $selectedCategories = $this->parseCategoryIds((string) ($meta['categories'] ?? ''));
        $help = $this->helpEnabled();
        $categorySection = $isPage ? $this->categoryChecklist($language, $selectedCategories, $help) : '';
        $pluginFields = $this->hooks->render('admin.document_fields', [
            'collection' => $collection,
            'language' => $language,
            'document' => $document,
            'meta' => $meta,
            'help' => $help,
        ]);
        $editorTools = $this->hooks->render('admin.editor_tools', [
            'editor' => 'document',
            'textarea' => 'body',
            'collection' => $collection,
            'language' => $language,
            'document' => $document,
            'meta' => $meta,
        ]);

        return $notice . '<section class="admin-panel">
  <div class="admin-panel-head"><h2>' . ($document instanceof Document ? 'Редактировать' : 'Создать') . '</h2>' . ($url !== '' ? '<a class="admin-button admin-button--ghost" href="' . mur_escape($url) . '" target="_blank" rel="noreferrer">' . $this->icon('view') . '<span>Открыть на сайте</span></a>' : '') . '</div>
  ' . $this->editorIntro($isPage, $help) . '
  <form class="admin-form admin-editor" method="post" action="/admin/content/' . mur_escape($collection) . '/edit">
    <input type="hidden" name="_csrf" value="' . mur_escape((string) $user['csrf']) . '">
    <input type="hidden" name="original_id" value="' . mur_escape($id) . '">
    <input type="hidden" name="original_language" value="' . mur_escape($language) . '">
      ' . $this->documentField('Заголовок', '<input id="field-title" name="title" value="' . mur_escape((string) ($meta['title'] ?? '')) . '" required>', 'Название ' . $entityName . ' в админке и основной заголовок на сайте.', 'field-title', $help) . '
    ' . $editorTools . '
    <label class="admin-field-full">Содержимое
      <textarea name="body" rows="24" spellcheck="false">' . mur_escape($body) . '</textarea>
      ' . $this->helpText('Основной текст в Markdown или HTML. Шорткоды размещайте отдельной строкой, например <code>[gallery=name]</code>.', $help) . '
    </label>
    <fieldset class="admin-editor-section">
      <legend>Основные параметры</legend>
      ' . $this->documentField('Адрес (slug)', '<input id="field-slug" name="slug" value="' . mur_escape((string) ($meta['slug'] ?? '')) . '" pattern="[A-Za-z0-9_\\-\\/]+">', 'Часть URL латиницей, например <code>about-company</code>. Если оставить пустым, используется ID.', 'field-slug', $help) . '
      ' . $this->documentField('Родитель', '<select id="field-parent" name="parent">' . $this->parentOptions($language, $collection, (string) ($meta['parent'] ?? ''), (string) ($meta['id'] ?? '')) . '</select>', $isPage ? 'Родительская страница задает вложенность URL. Например, страница с родителем <code>services</code> будет находиться внутри раздела услуг.' : 'Родительская категория задает положение в дереве рубрик. Она не назначает материалы в эту категорию.', 'field-parent', $help) . '
      ' . $this->documentField('Шаблон', '<select id="field-template" name="template">' . $this->templateOptions((string) ($meta['template'] ?? ($isPage ? 'page' : 'category')), $isPage) . '</select>', 'Определяет способ вывода. Обычная страница показывает текст, список дополнительно выводит подобранные материалы, категория представляет рубрику.', 'field-template', $help) . '
      ' . $this->documentField('Ключ перевода', '<input id="field-translation-key" name="translation_key" value="' . mur_escape((string) ($meta['translation_key'] ?? '')) . '">', 'Одинаковый технический ключ связывает версии одного материала на разных языках, например <code>about</code>.', 'field-translation-key', $help) . '
      ' . $this->documentField('Краткое описание', '<textarea class="admin-textarea--short" id="field-summary" name="summary" rows="3">' . mur_escape((string) ($meta['summary'] ?? '')) . '</textarea>', 'Короткий анонс для карточек и вступления страницы. Это не SEO-описание.', 'field-summary', $help) . '
    </fieldset>
    ' . $categorySection . '
    ' . $pluginFields . '
    <fieldset class="admin-editor-section">
      <legend>SEO</legend>
      ' . $this->documentField('SEO-заголовок', '<input id="field-seo-title" name="seo.title" value="' . mur_escape((string) ($meta['seo.title'] ?? '')) . '">', 'Заголовок вкладки браузера и результата поиска. Обычно до 60 символов.', 'field-seo-title', $help) . '
      ' . $this->documentField('SEO-описание', '<textarea class="admin-textarea--short" id="field-seo-description" name="seo.description" rows="3">' . mur_escape((string) ($meta['seo.description'] ?? '')) . '</textarea>', 'Описание страницы для поисковых систем и социальных превью. Обычно 120–160 символов.', 'field-seo-description', $help) . '
    </fieldset>
    <details class="admin-editor-disclosure">
      <summary>Настройки списка</summary>
      <div class="admin-editor-section">
      ' . $this->documentField('Источник списка', '<select id="field-list-source" name="list.source">' . $this->listSourceOptions((string) ($meta['list.source'] ?? ''), $isPage) . '</select>', $isPage ? 'Для страницы со списком выводит материалы, у которых эта страница указана как родитель.' : 'Категория может выводить назначенные ей страницы или свои дочерние категории.', 'field-list-source', $help) . '
      ' . $this->documentField('Вид списка', '<select id="field-list-view" name="list.view">' . $this->listViewOptions((string) ($meta['list.view'] ?? '')) . '</select>', 'Вариант оформления списка: карточки, только заголовки или лента.', 'field-list-view', $help) . '
      ' . $this->documentField('Сортировка', '<select id="field-list-sort" name="list.sort">' . $this->listSortOptions((string) ($meta['list.sort'] ?? '')) . '</select>', 'Поле, по которому упорядочиваются элементы. Для ручного порядка выберите «Порядок».', 'field-list-sort', $help) . '
      ' . $this->documentField('Направление', '<select id="field-list-direction" name="list.direction">' . $this->directionOptions((string) ($meta['list.direction'] ?? '')) . '</select>', 'По возрастанию: от меньшего к большему. По убыванию: новые или большие значения первыми.', 'field-list-direction', $help) . '
      ' . $this->documentField('Элементов на странице', '<input id="field-list-per-page" name="list.per_page" type="number" min="1" max="100" value="' . mur_escape((string) ($meta['list.per_page'] ?? '')) . '">', 'Положительное число включает пагинацию. Пустое поле выводит весь список на одной странице.', 'field-list-per-page', $help) . '
      ' . $this->documentField('Позиция списка', '<select id="field-list-position" name="list.position">' . $this->listPositionOptions((string) ($meta['list.position'] ?? '')) . '</select>', 'Где выводить список: перед текстом, после текста или только в месте shortcode <code>[list]</code>. Пустое значение берет глобальную настройку шаблона.', 'field-list-position', $help) . '
      </div>
    </details>
    <details class="admin-editor-disclosure">
      <summary>Служебные параметры</summary>
      <div class="admin-editor-disclosure__body">
      <div class="admin-form-grid admin-form-grid--compact">
        ' . $this->updatedAtFields($meta) . '
        ' . $this->compactDocumentField('Язык', $this->languageSelect($language), 'Языковая ветка файла.', $help) . '
        ' . $this->compactDocumentField('Статус', '<select name="status">' . $this->statusOptions((string) ($meta['status'] ?? 'draft')) . '</select>', 'Опубликован или скрыт как черновик.', $help) . '
        ' . $this->compactDocumentField('ID', '<input name="id" value="' . mur_escape((string) ($meta['id'] ?? '')) . '" pattern="[A-Za-z0-9_-]+" required>', 'Стабильный технический идентификатор латиницей.', $help) . '
        ' . $this->compactDocumentField('Порядок', '<input name="order" type="number" value="' . mur_escape((string) ($meta['order'] ?? '')) . '">', 'Число для ручной сортировки: меньшее показывается раньше.', $help) . '
        ' . $this->compactDocumentField('Заголовок страницы', '<select name="page.header">' . $this->pageHeaderOptions((string) ($meta['page.header'] ?? '')) . '</select>', 'Показывать ли стандартный заголовок страницы над содержимым.', $help) . '
      </div>
      </div>
    </details>
    <details class="admin-editor-disclosure">
      <summary>Дополнительные параметры</summary>
      <div class="admin-editor-disclosure__body">
        <label class="admin-field-full">Параметры без отдельного поля
          <textarea name="extra_meta" rows="8" spellcheck="false">' . mur_escape($this->extraMetaText($meta)) . '</textarea>
          ' . $this->helpText('Формат каждой строки: <code>ключ: значение</code>.', $help) . '
        </label>
      </div>
    </details>
    <div class="admin-actions">
      <button class="admin-button" type="submit">' . $this->icon('save') . '<span>Сохранить</span></button>
      <a class="admin-button admin-button--ghost" href="' . mur_escape($back) . '">' . $this->icon('back') . '<span>Назад</span></a>
    </div>
  </form>
</section>';
    }

    private function saveDocument(string $collection, array $user): string
    {
        $guard = $this->guardPost($user, 'editor');

        if ($guard !== '') {
            return $guard;
        }

        $language = $this->safeLanguage((string) ($_POST['lang'] ?? ''));
        $id = $this->safeName((string) ($_POST['id'] ?? ''));

        if ($id === '') {
            return $this->notice('ID обязателен и должен содержать латиницу, цифры, дефис или underscore.', true);
        }

        $perPage = $this->postedMetaValue('list.per_page');
        if ($perPage !== '' && (!ctype_digit($perPage) || (int) $perPage < 1 || (int) $perPage > 100)) {
            return $this->notice('Количество элементов на странице должно быть числом от 1 до 100 или пустым значением.', true);
        }

        $meta = $this->postedDocumentMeta($collection, $id, $language);
        $body = str_replace(["\r\n", "\r"], "\n", (string) ($_POST['body'] ?? ''));
        $originalId = $this->safeName((string) ($_POST['original_id'] ?? ''));
        $originalLanguage = $this->safeLanguage((string) ($_POST['original_language'] ?? $language));
        $original = $originalId !== '' ? $this->content->findById($originalLanguage, $originalId) : null;

        if ($originalId !== '' && !$original instanceof Document) {
            return $this->notice('Исходный документ не найден. Обновите список и повторите редактирование.', true);
        }

        if ($original instanceof Document && $original->collection !== $collection) {
            return $this->notice('Исходный документ относится к другой коллекции.', true);
        }

        if ($original instanceof Document && $id !== $original->id()) {
            return $this->notice('ID существующего документа нельзя изменять. Создайте копию с новым ID.', true);
        }

        if ($original instanceof Document && $language !== $original->language) {
            return $this->notice('Языковую ветку существующего документа нельзя изменять. Создайте перевод или копию.', true);
        }

        try {
            $oldUpdated = (string) ($original?->meta['updated_at'] ?? '');
            unset($meta['updated_at']);
            if (isset($_POST['update_updated_at'])) {
                $meta['updated_at'] = \Mur\Core\DocumentDates::fromFields((array) ($_POST['updated'] ?? []), (string) ($this->site['timezone'] ?? 'UTC'));
            } else {
                $meta['updated_at'] = $oldUpdated !== '' ? $oldUpdated : date('Y-m-d H:i:s');
            }
        } catch (\InvalidArgumentException $exception) {
            return $this->notice($exception->getMessage(), true);
        }

        $originalFile = $original instanceof Document ? $original->filePath : '';
        $targetFile = mur_path('content/' . $language . '/' . $collection . '/' . $id . '.mur.md');
        $validationError = $this->documentCollisionError($collection, $language, $meta, $original, $targetFile);

        if ($validationError !== '') {
            return $this->notice($validationError, true);
        }

        if ($originalFile !== '' && is_file($originalFile)) {
            $this->backupManagedFile($originalFile);
        }

        $this->writeDocument($targetFile, $meta, $body);

        if ($originalFile !== '' && !$this->sameFilePath($originalFile, $targetFile) && is_file($originalFile)) {
            $this->deleteFile($originalFile, mur_path('content'));
        }

        $this->content->refresh();

        $this->redirect('/admin/content/' . $collection . '/edit?lang=' . rawurlencode($language) . '&id=' . rawurlencode($id) . '&saved=1', 303);
        return $this->notice('Документ сохранен.');
    }

    private function deleteDocumentConfirm(string $collection, string $method, array $user): string
    {
        $language = $this->safeLanguage((string) ($_GET['lang'] ?? $_POST['lang'] ?? ''));
        $id = (string) ($_GET['id'] ?? $_POST['id'] ?? '');
        $document = $id !== '' ? $this->content->findById($language, $id) : null;
        $back = '/admin/content/' . $collection;

        if (!$document instanceof Document || $document->collection !== $collection) {
            return '<section class="admin-panel"><p>Документ не найден.</p><a class="admin-button admin-button--ghost" href="' . mur_escape($back) . '">Назад</a></section>';
        }

        if ($method === 'POST') {
            $guard = $this->guardPost($user, 'editor');
            if ($guard !== '') {
                return $guard;
            }
            if (!$this->documentDeleteConfirmed((string) ($_POST['confirm'] ?? ''))) {
                return $this->notice('Для удаления введите Y или 1.', true) . $this->deleteDocumentForm($collection, $document, $user);
            }

            $dependencyError = $this->documentDeleteDependencyError($document);
            if ($dependencyError !== '') {
                return $this->notice($dependencyError, true) . $this->deleteDocumentForm($collection, $document, $user);
            }

            $this->backupManagedFile($document->filePath);
            $this->deleteFile($document->filePath, mur_path('content'));
            $this->content->refresh();
            return $this->notice('Документ удален.') . '<section class="admin-panel"><a class="admin-button" href="' . mur_escape($back) . '">Вернуться к списку</a></section>';
        }

        return $this->deleteDocumentForm($collection, $document, $user);
    }

    private function deleteDocumentForm(string $collection, Document $document, array $user): string
    {
        return '<section class="admin-panel">
  <h2>Подтверждение удаления</h2>
  <p>Будет удален файл <code>' . mur_escape($this->relativePath($document->filePath)) . '</code>. Для подтверждения введите <code>Y</code> или <code>1</code>.</p>
  <form class="admin-form" method="post" action="/admin/content/' . mur_escape($collection) . '/delete">
    <input type="hidden" name="_csrf" value="' . mur_escape((string) $user['csrf']) . '">
    <input type="hidden" name="lang" value="' . mur_escape($document->language) . '">
    <input type="hidden" name="id" value="' . mur_escape($document->id()) . '">
    <label>Подтверждение<input name="confirm" pattern="[Yy1]" maxlength="1" autocomplete="off" required></label>
    <div class="admin-actions"><button class="admin-button admin-button--danger" type="submit">Удалить</button><a class="admin-button admin-button--ghost" href="/admin/content/' . mur_escape($collection) . '">Отмена</a></div>
  </form>
</section>';
    }

    private function documentDeleteConfirmed(string $value): bool
    {
        return in_array(strtolower(trim($value)), ['y', '1'], true);
    }

    private function cloneDocumentForm(string $collection, string $method, array $user): string
    {
        $language = $this->safeLanguage((string) ($_GET['lang'] ?? $_POST['lang'] ?? ''));
        $id = (string) ($_GET['id'] ?? $_POST['id'] ?? '');
        $document = $id !== '' ? $this->content->findById($language, $id) : null;

        if (!$document instanceof Document || $document->collection !== $collection) {
            return '<section class="admin-panel"><p>Документ не найден.</p></section>';
        }

        if ($method === 'POST') {
            $guard = $this->guardPost($user, 'editor');
            if ($guard !== '') {
                return $guard;
            }
            $newId = $this->safeName((string) ($_POST['new_id'] ?? ''));
            $newTitle = trim((string) ($_POST['new_title'] ?? ''));

            if ($newId === '' || $newTitle === '') {
                return $this->notice('Новый ID и заголовок обязательны.', true) . $this->cloneDocumentHtml($collection, $document, $user);
            }

            $meta = $document->meta;
            $meta['id'] = $newId;
            $meta['title'] = $newTitle;
            $meta['slug'] = $this->safeSlug((string) ($_POST['new_slug'] ?? $newId));
            $meta['status'] = 'draft';
            $file = mur_path('content/' . $document->language . '/' . $collection . '/' . $newId . '.mur.md');

            if (is_file($file)) {
                return $this->notice('Файл с таким ID уже существует.', true) . $this->cloneDocumentHtml($collection, $document, $user);
            }

            $this->writeDocument($file, $meta, $document->body);
            return $this->notice('Копия создана.') . '<section class="admin-panel"><a class="admin-button" href="/admin/content/' . mur_escape($collection) . '/edit?lang=' . rawurlencode($document->language) . '&id=' . rawurlencode($newId) . '">Открыть копию</a></section>';
        }

        return $this->cloneDocumentHtml($collection, $document, $user);
    }

    private function cloneDocumentHtml(string $collection, Document $document, array $user): string
    {
        $suggested = $this->suggestCopyId($document->language, $collection, $document->id());

        return '<section class="admin-panel">
  <h2>Клонировать</h2>
  <p>Исходный файл: <code>' . mur_escape($this->relativePath($document->filePath)) . '</code>. Новая копия будет создана как draft.</p>
  <form class="admin-form" method="post" action="/admin/content/' . mur_escape($collection) . '/clone">
    <input type="hidden" name="_csrf" value="' . mur_escape((string) $user['csrf']) . '">
    <input type="hidden" name="lang" value="' . mur_escape($document->language) . '">
    <input type="hidden" name="id" value="' . mur_escape($document->id()) . '">
    <div class="admin-form-grid">
      <label>Новый ID<input name="new_id" value="' . mur_escape($suggested) . '" pattern="[A-Za-z0-9_-]+" required></label>
      <label>Новый заголовок<input name="new_title" value="' . mur_escape($document->title() . ' copy') . '" required></label>
      <label>Новый slug<input name="new_slug" value="' . mur_escape($suggested) . '" pattern="[A-Za-z0-9_\\-\\/]+"></label>
    </div>
    <div class="admin-actions"><button class="admin-button" type="submit">Создать копию</button><a class="admin-button admin-button--ghost" href="/admin/content/' . mur_escape($collection) . '">Отмена</a></div>
  </form>
</section>';
    }

    private function documentTablePost(string $collection, array $user): string
    {
        $guard = $this->guardPost($user, 'editor');

        if ($guard !== '') {
            return $guard;
        }

        if ($collection !== 'pages') {
            return $this->notice('Действие выполнено.');
        }

        try {
            $documents = $this->selectedDocuments('pages');
            if ($documents === []) {
                return $this->notice('Выберите хотя бы одну страницу.', true);
            }

            $action = (string) ($_POST['bulk_action'] ?? '');

            if ($action === 'status') {
                $status = (string) ($_POST['bulk_status'] ?? '');
                if (!in_array($status, ['published', 'draft'], true)) {
                    return $this->notice('Выберите допустимый статус.', true);
                }
                $this->updateSelectedDocuments($documents, static function (array $meta) use ($status): array {
                    $meta['status'] = $status;
                    return $meta;
                });
                return $this->notice('Статус изменен у страниц: ' . count($documents) . '.');
            }

            if ($action === 'parent') {
                $target = (string) ($_POST['bulk_parent'] ?? '');
                $this->assignBulkParent($documents, $target);
                return $this->notice('Родитель изменен у страниц: ' . count($documents) . '.');
            }

            if ($action === 'category') {
                $targets = $this->postedSelection('bulk_categories');
                $legacyTarget = trim((string) ($_POST['bulk_category'] ?? ''));
                if ($targets === [] && $legacyTarget !== '') {
                    $targets[] = $legacyTarget;
                }
                $categoryCount = $this->addBulkCategories($documents, $targets);
                return $this->notice('Страниц обновлено: ' . count($documents) . '. Добавлено рубрик: ' . $categoryCount . '.');
            }

            if ($action === 'delete') {
                return $this->pageBulkDeleteConfirmation($documents, $user);
            }

            if ($action === 'delete_confirm') {
                if (!$this->documentDeleteConfirmed((string) ($_POST['confirm'] ?? ''))) {
                    return $this->notice('Для удаления введите Y или 1.', true) . $this->pageBulkDeleteConfirmation($documents, $user);
                }

                $dependencyError = $this->bulkPageDeleteDependencyError($documents);
                if ($dependencyError !== '') {
                    return $this->notice($dependencyError, true);
                }

                $backup = $this->bulkPageBackupArchive($documents);
                foreach ($documents as $document) {
                    $this->backupManagedFile($document->filePath);
                    $this->deleteFile($document->filePath, mur_path('content'));
                }
                $this->content->refresh();

                return $this->notice(
                    'Удалено страниц: ' . count($documents) . '. Резервный ZIP: ' . $this->relativePath($backup) . '.',
                );
            }
        } catch (\Throwable $exception) {
            return $this->notice($exception->getMessage(), true);
        }

        return $this->notice('Выберите групповую операцию.', true);
    }

    private function pageBulkControls(string $language): string
    {
        return '<section class="admin-panel admin-bulk-panel">
  <h2>Групповые операции — ' . mur_escape($this->languageHeading($language)) . '</h2>
  <div class="admin-selection-tools">
    <button class="admin-button admin-button--ghost" type="button" data-selection-action="all">Выделить все</button>
    <button class="admin-button admin-button--ghost" type="button" data-selection-action="invert">Инвертировать выделение</button>
    <span class="admin-selection-count" data-selection-count>Выбрано: 0</span>
  </div>
  <div class="admin-bulk-actions">
    <button class="admin-button" type="submit" formaction="/admin/content/pages/bulk/download" formmethod="post" data-selection-requires>Скачать ZIP</button>
    <span class="admin-inline-action">
      <label>Статус <select name="bulk_status">' . $this->statusOptions('published') . '</select></label>
      <button class="admin-button admin-button--ghost" type="submit" name="bulk_action" value="status" data-selection-requires>Изменить статус</button>
    </span>
    <button class="admin-button admin-button--ghost" type="button" data-dialog-open="page-bulk-parent-dialog" data-selection-requires>Назначить родителя</button>
    <button class="admin-button admin-button--ghost" type="button" data-dialog-open="page-bulk-category-dialog" data-selection-requires>Добавить в рубрики</button>
    <button class="admin-button admin-button--danger" type="submit" name="bulk_action" value="delete" data-selection-requires>Удалить</button>
  </div>
</section>';
    }

    private function pageBulkDialogs(string $language): string
    {
        return '<dialog class="admin-dialog" id="page-bulk-parent-dialog">
  <h2>Назначить родителя</h2>
  <p>Будут изменены только выбранные страницы языка ' . mur_escape(strtoupper($language)) . '.</p>
  <label>Родительская страница
    <select name="bulk_parent">' . $this->pageBulkParentOptions($language) . '</select>
  </label>
  <div class="admin-actions">
    <button class="admin-button" type="submit" name="bulk_action" value="parent">Применить</button>
    <button class="admin-button admin-button--ghost" type="button" data-dialog-close>Отмена</button>
  </div>
</dialog>
<dialog class="admin-dialog" id="page-bulk-category-dialog">
  <h2>Добавить в рубрики</h2>
  <p>Можно выбрать несколько рубрик, включая родительскую и вложенную. Уже назначенные рубрики сохранятся.</p>
  ' . $this->pageBulkCategoryTree($language) . '
  <div class="admin-actions">
    <button class="admin-button" type="submit" name="bulk_action" value="category">Применить</button>
    <button class="admin-button admin-button--ghost" type="button" data-dialog-close>Отмена</button>
  </div>
</dialog>';
    }

    private function pageBulkParentOptions(string $language): string
    {
        $html = '<option value="">Выберите родителя</option>';
        $html .= '<option value="' . mur_escape($language . ':') . '">Без родителя</option>';
        foreach ($this->hierarchyRows($this->collectionDocuments($language, 'pages')) as [$document, $depth]) {
            $label = str_repeat('— ', (int) $depth) . $document->title() . ' [' . $document->id() . ']';
            $html .= '<option value="' . mur_escape($language . ':' . $document->id()) . '">' . mur_escape($label) . '</option>';
        }

        return $html;
    }

    private function pageBulkCategoryTree(string $language): string
    {
        $html = '<div class="admin-category-tree admin-category-tree--bulk" role="group" aria-label="Рубрики для выбранных страниц">';
        $categories = $this->collectionDocuments($language, 'categories');
        if ($categories === []) {
            return $html . '<p class="admin-empty">Категорий пока нет.</p></div>';
        }
        foreach ($this->hierarchyRows($categories) as [$document, $depth]) {
            $treeDepth = max(0, min(8, (int) $depth));
            $html .= '<label class="admin-category-option admin-tree-depth-' . $treeDepth . '">
  <input type="checkbox" name="bulk_categories[]" value="' . mur_escape($document->id()) . '">
  <span>' . mur_escape($document->title()) . ' <code>' . mur_escape($document->id()) . '</code></span>
</label>';
        }

        return $html . '</div>';
    }

    private function selectedDocuments(string $collection): array
    {
        $documents = [];
        $languageCounts = [];
        foreach ($this->postedSelection('selected') as $token) {
            if (!preg_match('/^([a-z]{2}):([A-Za-z0-9_-]+)$/', $token, $match)
                || !in_array($match[1], $this->orderedLanguages(), true)) {
                throw new \RuntimeException('Недопустимый идентификатор выбранной страницы.');
            }
            $document = $this->content->findById($match[1], $match[2]);
            if (!$document instanceof Document || $document->collection !== $collection) {
                throw new \RuntimeException('Одна из выбранных страниц не найдена.');
            }
            $documents[] = $document;
            $languageCounts[$document->language] = ($languageCounts[$document->language] ?? 0) + 1;
        }

        if (count($languageCounts) > 1) {
            $parts = [];
            foreach ($this->orderedLanguages() as $language) {
                if (isset($languageCounts[$language])) {
                    $parts[] = strtoupper($language) . ': ' . $languageCounts[$language];
                }
            }
            throw new \RuntimeException(
                'Пакетные действия доступны только внутри одной языковой версии. Выбрано: ' . implode(', ', $parts) . '.',
            );
        }

        return $documents;
    }

    private function updateSelectedDocuments(array $documents, callable $update): void
    {
        foreach ($documents as $document) {
            if (!$document instanceof Document) {
                throw new \RuntimeException('Недопустимый документ в групповой операции.');
            }
            $meta = $update($document->meta, $document);
            if (!is_array($meta)) {
                throw new \RuntimeException('Групповая операция вернула недопустимые данные.');
            }
            $this->backupManagedFile($document->filePath);
            $this->writeDocument($document->filePath, $meta, $document->body);
        }
        $this->content->refresh();
    }

    private function assignBulkParent(array $documents, string $target): void
    {
        if (!preg_match('/^([a-z]{2}):(.*)$/', $target, $match)
            || !in_array($match[1], $this->orderedLanguages(), true)
            || ($match[2] !== '' && $this->safeName($match[2]) !== $match[2])) {
            throw new \RuntimeException('Выберите допустимую родительскую страницу.');
        }

        $language = $match[1];
        $parentId = $match[2];
        $byId = [];
        foreach ($this->content->all($language) as $candidate) {
            if ($candidate instanceof Document) {
                $byId[$candidate->id()] = $candidate;
            }
        }
        if ($parentId !== '' && (!isset($byId[$parentId]) || $byId[$parentId]->collection !== 'pages')) {
            throw new \RuntimeException('Родительская страница не найдена.');
        }

        $plannedPaths = [];
        foreach ($documents as $document) {
            if (!$document instanceof Document || $document->language !== $language) {
                throw new \RuntimeException('Все выбранные страницы должны находиться в языковой ветке родителя.');
            }
            $meta = $document->meta;
            if ($parentId === '') {
                unset($meta['parent']);
            } else {
                $meta['parent'] = $parentId;
            }
            $error = $this->documentCollisionError('pages', $language, $meta, $document, $document->filePath);
            if ($error !== '') {
                throw new \RuntimeException($error);
            }
            $path = $this->documentUrlPath('pages', $meta, $byId);
            if (isset($plannedPaths[$path])) {
                throw new \RuntimeException('Назначение родителя создаёт одинаковые URL у выбранных страниц.');
            }
            $plannedPaths[$path] = true;
        }

        $this->updateSelectedDocuments($documents, static function (array $meta) use ($parentId): array {
            if ($parentId === '') {
                unset($meta['parent']);
            } else {
                $meta['parent'] = $parentId;
            }
            return $meta;
        });
    }

    private function addBulkCategories(array $documents, array $targets): int
    {
        if ($documents === []) {
            throw new \RuntimeException('Выберите хотя бы одну страницу.');
        }

        $language = (string) $documents[0]->language;
        $categoryIds = [];
        foreach (array_values(array_unique(array_map('strval', $targets))) as $target) {
            $target = trim($target);
            if (preg_match('/^([a-z]{2}):([A-Za-z0-9_-]+)$/', $target, $match)) {
                if ($match[1] !== $language) {
                    throw new \RuntimeException('Все рубрики должны находиться в языке выбранных страниц.');
                }
                $target = $match[2];
            }
            if ($target === '' || $this->safeName($target) !== $target) {
                throw new \RuntimeException('Выберите допустимые рубрики.');
            }
            $category = $this->content->findById($language, $target);
            if (!$category instanceof Document || $category->collection !== 'categories') {
                throw new \RuntimeException('Одна из выбранных рубрик не найдена.');
            }
            $categoryIds[] = $target;
        }

        $categoryIds = array_values(array_unique($categoryIds));
        if ($categoryIds === []) {
            throw new \RuntimeException('Выберите хотя бы одну рубрику.');
        }

        foreach ($documents as $document) {
            if (!$document instanceof Document || $document->language !== $language) {
                throw new \RuntimeException('Все выбранные страницы должны находиться в одной языковой версии.');
            }
        }

        $this->updateSelectedDocuments($documents, function (array $meta) use ($categoryIds): array {
            $categories = $this->parseCategoryIds((string) ($meta['categories'] ?? ''));
            foreach ($categoryIds as $categoryId) {
                if (!in_array($categoryId, $categories, true)) {
                    $categories[] = $categoryId;
                }
            }
            $meta['categories'] = implode(', ', $categories);
            return $meta;
        });

        return count($categoryIds);
    }

    private function pageBulkDeleteConfirmation(array $documents, array $user): string
    {
        $hidden = '';
        foreach ($documents as $document) {
            $hidden .= '<input type="hidden" name="selected[]" value="' . mur_escape($document->language . ':' . $document->id()) . '">';
        }

        return '<section class="admin-panel admin-bulk-confirmation">
  <h2>Подтверждение группового удаления</h2>
  <p>Будет удалено страниц: <strong>' . count($documents) . '</strong>. Перед удалением MurCMS создаст единый ZIP-архив исходных файлов. Для подтверждения введите <code>Y</code> или <code>1</code>.</p>
  <form class="admin-form" method="post" action="/admin/content/pages">
    <input type="hidden" name="_csrf" value="' . mur_escape((string) $user['csrf']) . '">
    <input type="hidden" name="bulk_action" value="delete_confirm">
    ' . $hidden . '
    <label>Подтверждение<input name="confirm" pattern="[Yy1]" maxlength="1" autocomplete="off" required></label>
    <div class="admin-actions"><button class="admin-button admin-button--danger" type="submit">Удалить страницы</button><a class="admin-button admin-button--ghost" href="/admin/content/pages">Отмена</a></div>
  </form>
</section>';
    }

    private function bulkPageDeleteDependencyError(array $documents): string
    {
        $selected = [];
        foreach ($documents as $document) {
            $selected[$document->language . ':' . $document->id()] = true;
        }

        foreach ($documents as $document) {
            foreach ($this->content->all($document->language) as $candidate) {
                if (!$candidate instanceof Document || $candidate->collection !== 'pages') {
                    continue;
                }
                if ($candidate->parentId() === $document->id()
                    && !isset($selected[$candidate->language . ':' . $candidate->id()])) {
                    return 'Сначала переназначьте родителя у страницы «' . $candidate->title() . '».';
                }
            }
        }

        return '';
    }

    private function bulkPageBackupArchive(array $documents): string
    {
        $directory = mur_path('storage/backups/bulk-pages');
        $this->ensureDirectory($directory);
        $archive = $this->uniqueArchivePath($directory, 'pages-' . date('Ymd-His'));
        $files = [];
        foreach ($documents as $document) {
            $files[] = [
                'path' => $document->filePath,
                'name' => $document->language . '/pages/' . basename($document->filePath),
            ];
        }
        $this->createZipArchive($archive, $files);

        return $archive;
    }

    private function downloadRoute(string $route, array $user): void
    {
        if (in_array($route, ['content/pages/bulk/download', 'media/bulk/download'], true)) {
            $this->bulkDownloadRoute($route, $user);
            return;
        }

        $file = null;
        $role = 'editor';

        if (preg_match('~^content/(pages|categories)/download$~', $route, $match)) {
            $language = $this->safeLanguage((string) ($_GET['lang'] ?? ''));
            $id = (string) ($_GET['id'] ?? '');
            $document = $id !== '' ? $this->content->findById($language, $id) : null;
            if ($document instanceof Document && $document->collection === $match[1]) {
                $file = $document->filePath;
            }
        } elseif ($route === 'content/snippets/download') {
            $candidate = str_replace('\\', '/', trim((string) ($_GET['file'] ?? ''), '/'));
            if (preg_match('~^snippets/(?:[a-z]{2}/)?[A-Za-z0-9_-]+\.(?:mur\.md|html)$~', $candidate) === 1) {
                $resolved = mur_path($candidate);
                if (is_file($resolved) && $this->isSafePath($resolved, mur_path('snippets'))) {
                    $role = 'admin';
                    $file = $resolved;
                }
            }
        } elseif ($route === 'files/download') {
            $scope = (string) ($_GET['scope'] ?? '');
            $candidate = (string) ($_GET['file'] ?? '');
            $collection = $this->fileCollection($scope);
            if ($collection !== null && $this->collectionFileAllowed($collection, $candidate)) {
                $role = (string) $collection['role'];
                $file = mur_path($candidate);
            }
        } elseif ($route === 'media/download') {
            $candidate = (string) ($_GET['file'] ?? '');
            if ($candidate !== '' && $this->media->canDeleteFile($candidate)) {
                $file = $this->media->filePath($candidate);
            }
        }

        if (!$this->auth->can($user, $role)) {
            http_response_code(403);
            echo 'Forbidden';
            return;
        }

        if (!is_string($file) || !$this->isSafePath($file, mur_root_path()) || !is_file($file)) {
            http_response_code(404);
            echo 'File not found';
            return;
        }

        $mime = 'text/plain; charset=utf-8';
        if (str_starts_with($route, 'media/')) {
            $detected = function_exists('mime_content_type') ? mime_content_type($file) : false;
            $mime = is_string($detected) && $detected !== '' ? $detected : 'application/octet-stream';
        }

        header('Content-Type: ' . $mime);
        header('Content-Disposition: attachment; filename="' . basename($file) . '"');
        readfile($file);
    }

    private function bulkDownloadRoute(string $route, array $user): void
    {
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST'
            || !$this->auth->can($user, 'editor')
            || !$this->auth->verifyCsrf((string) ($_POST['_csrf'] ?? ''))) {
            http_response_code(403);
            echo 'Forbidden';
            return;
        }

        $temporary = tempnam(sys_get_temp_dir(), 'murcms-');
        if ($temporary === false) {
            http_response_code(500);
            echo 'Unable to create archive';
            return;
        }
        @unlink($temporary);
        $archive = $temporary . '.zip';

        try {
            $files = [];
            $downloadName = 'murcms-files-' . date('Ymd-His') . '.zip';
            if ($route === 'content/pages/bulk/download') {
                foreach ($this->selectedDocuments('pages') as $document) {
                    $files[] = [
                        'path' => $document->filePath,
                        'name' => $document->language . '/pages/' . basename($document->filePath),
                    ];
                }
                $downloadName = 'murcms-pages-' . date('Ymd-His') . '.zip';
            } else {
                foreach ($this->selectedMediaFiles() as $relative) {
                    $files[] = [
                        'path' => $this->media->filePath($relative),
                        'name' => 'uploads/' . $relative,
                    ];
                }
                $downloadName = 'murcms-uploads-' . date('Ymd-His') . '.zip';
            }

            if ($files === []) {
                throw new \RuntimeException('No files selected');
            }

            $this->createZipArchive($archive, $files);
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="' . $downloadName . '"');
            header('Content-Length: ' . (string) (filesize($archive) ?: 0));
            readfile($archive);
        } catch (\Throwable $exception) {
            http_response_code(400);
            header('Content-Type: text/plain; charset=utf-8');
            echo $exception->getMessage();
        } finally {
            if (is_file($archive)) {
                @unlink($archive);
            }
        }
    }

    private function seoOverview(string $method, array $user): string
    {
        $notice = '';
        if ($method === 'POST') {
            $notice = $this->guardPost($user);
            if ($notice === '') {
                $home = [];
                $posted = is_array($_POST['home'] ?? null) ? $_POST['home'] : [];
                foreach ($this->orderedLanguages() as $language) {
                    $home[$language] = [];
                    foreach (['title', 'description'] as $key) {
                        $value = $posted[$language][$key] ?? '';
                        $home[$language][$key] = is_string($value) ? trim($value) : '';
                    }
                }
                $this->seo->saveHomeMeta($home);
                $notice = $this->notice('SEO главной сохранено.');
            }
        }
        $fields = '';
        foreach ($this->orderedLanguages() as $language) {
            $values = $this->seo->config()['home'][$language] ?? [];
            $fields .= '<fieldset class="admin-editor-section"><legend>' . mur_escape($this->languageHeading($language)) . '</legend><label>Meta title<input name="home[' . mur_escape($language) . '][title]" value="' . mur_escape((string) ($values['title'] ?? '')) . '"></label><label>Meta description<textarea name="home[' . mur_escape($language) . '][description]" rows="3">' . mur_escape((string) ($values['description'] ?? '')) . '</textarea></label></fieldset>';
        }
        $urls = $this->seo->sitemapUrls();
        $robots = $this->seo->robots();

        return $notice . '<section class="admin-panel"><h2>SEO главной по языкам</h2><p class="admin-help">Пустое поле: текущий язык → основной язык → системное название / описание сайта. Название сайта единое для всех языков.</p><form class="admin-form" method="post" action="/admin/seo"><input type="hidden" name="_csrf" value="' . mur_escape((string) $user['csrf']) . '">' . $fields . '<button class="admin-button" type="submit">Сохранить SEO главной</button></form></section><section class="admin-grid admin-grid--stats">
  <article class="admin-panel"><strong>' . count($urls) . '</strong><span>sitemap URLs</span></article>
  <article class="admin-panel"><strong>' . count($this->languages['enabled'] ?? []) . '</strong><span>languages</span></article>
  <article class="admin-panel"><strong>' . strlen($robots) . '</strong><span>robots.txt bytes</span></article>
</section>';
    }

    private function seoSitemap(string $method, array $user): string
    {
        $notice = '';

        if ($method === 'POST') {
            $notice = $this->guardPost($user);
            if ($notice === '') {
                $action = (string) ($_POST['action'] ?? 'save');

                if ($action === 'snapshot') {
                    $file = $this->seo->saveSitemapSnapshot();
                    $notice = $this->notice('Snapshot sitemap обновлен: ' . $this->relativePath($file));
                } else {
                    $this->seo->saveSitemapSettings([
                        'enabled' => isset($_POST['enabled']),
                        'include_home' => isset($_POST['include_home']),
                        'include_documents' => isset($_POST['include_documents']),
                        'include_lastmod' => isset($_POST['include_lastmod']),
                    ]);
                    $notice = $this->notice('Настройки sitemap сохранены.');
                }
            }
        }

        $settings = $this->seo->sitemapSettings();
        $urls = $this->seo->sitemapUrls();
        $rows = '';

        foreach (array_slice($urls, 0, 80) as $url) {
            $rows .= '<tr><td><code>' . mur_escape((string) $url['path']) . '</code></td><td>' . mur_escape((string) $url['lastmod']) . '</td></tr>';
        }

        if ($rows === '') {
            $rows = '<tr><td colspan="2">Sitemap сейчас пуст.</td></tr>';
        }

        return $notice . '<section class="admin-panel">
  <h2>Настройки XML-карты</h2>
  <form class="admin-form" method="post" action="/admin/seo/sitemap">
    <input type="hidden" name="_csrf" value="' . mur_escape((string) $user['csrf']) . '">
    ' . $this->checkbox('enabled', 'Sitemap включен', ($settings['enabled'] ?? true) === true) . '
    ' . $this->checkbox('include_home', 'Включать главные языковые страницы', ($settings['include_home'] ?? true) === true) . '
    ' . $this->checkbox('include_documents', 'Включать опубликованные документы, включая pages/categories/services/news/info', ($settings['include_documents'] ?? true) === true) . '
    ' . $this->checkbox('include_lastmod', 'Добавлять lastmod', ($settings['include_lastmod'] ?? false) === true) . '
    ' . (($settings['include_lastmod'] ?? false) !== true ? '<p class="admin-help">Lastmod выключен: updated_at хранится в документах, но не выводится в sitemap. Включите эту настройку, если хотите публиковать даты правок.</p>' : '') . '
    <div class="admin-actions">
      <button class="admin-button" name="action" value="save" type="submit">Сохранить настройки</button>
      <button class="admin-button admin-button--ghost" name="action" value="snapshot" type="submit">Обновить snapshot</button>
    </div>
  </form>
</section>
<section class="admin-panel">
  <h2>Preview</h2>
  <p>Публичный URL: <a href="/sitemap.xml" target="_blank" rel="noreferrer">/sitemap.xml</a>. Сейчас в карте: ' . count($urls) . ' URL.</p>
  <table class="admin-table"><thead><tr><th>URL</th><th>lastmod</th></tr></thead><tbody>' . $rows . '</tbody></table>
</section>';
    }

    private function seoRobots(string $method, array $user): string
    {
        $notice = '';

        if ($method === 'POST') {
            $notice = $this->guardPost($user);
            if ($notice === '') {
                $this->seo->saveRobots((string) ($_POST['content'] ?? ''));
                $notice = $this->notice('robots.txt сохранен.');
            }
        }

        return $notice . '<section class="admin-panel">
  <h2>Редактор robots.txt</h2>
  <p>Можно использовать <code>{SITEMAP_URL}</code>: CMS заменит его абсолютным URL карты сайта.</p>
  <form class="admin-form" method="post" action="/admin/seo/robots">
    <input type="hidden" name="_csrf" value="' . mur_escape((string) $user['csrf']) . '">
    <textarea name="content" rows="14" spellcheck="false">' . mur_escape($this->seo->robotsTemplate()) . '</textarea>
    <button class="admin-button" type="submit">Сохранить robots.txt</button>
  </form>
</section>
<section class="admin-panel">
  <h2>Preview</h2>
  <pre class="admin-code">' . mur_escape($this->seo->robots()) . '</pre>
</section>';
    }

    private function seoHtaccess(string $method, array $user): string
    {
        $notice = '';

        if ($method === 'POST') {
            $notice = $this->guardPost($user);
            if ($notice === '') {
                $this->seo->saveHtaccess((string) ($_POST['content'] ?? ''));
                $notice = $this->notice('.htaccess сохранен. Предыдущая версия сохранена как .htaccess.bak.');
            }
        }

        return $notice . '<section class="admin-panel">
  <h2>Редактор .htaccess</h2>
  <p>Опасный файл: ошибка может сломать сайт на Apache. При сохранении создается резервная копия <code>.htaccess.bak</code>.</p>
  <form class="admin-form" method="post" action="/admin/seo/htaccess">
    <input type="hidden" name="_csrf" value="' . mur_escape((string) $user['csrf']) . '">
    <textarea name="content" rows="24" spellcheck="false">' . mur_escape($this->seo->htaccessContent()) . '</textarea>
    <button class="admin-button" type="submit">Сохранить .htaccess</button>
  </form>
</section>';
    }

    private function siteSettings(string $method, array $user): string
    {
        $notice = '';

        if ($method === 'POST') {
            $notice = $this->guardPost($user);
            if ($notice === '') {
                $this->site['name'] = trim((string) ($_POST['name'] ?? $this->site['name'] ?? ''));
                $this->site['description'] = trim((string) ($_POST['description'] ?? $this->site['description'] ?? ''));
                $this->writeConfigFile('config/site.php', $this->site);
                $notice = $this->notice('Настройки сайта сохранены.');
            }
        }

        return $notice . '<section class="admin-panel">
  <h2>Технические настройки</h2>
  <form class="admin-form" method="post" action="/admin/settings/site">
    <input type="hidden" name="_csrf" value="' . mur_escape((string) $user['csrf']) . '">
    <label>Системное название сайта<input name="name" value="' . mur_escape((string) ($this->site['name'] ?? '')) . '" required></label>
    <label>Описание по умолчанию<textarea name="description" rows="4">' . mur_escape((string) ($this->site['description'] ?? '')) . '</textarea></label>

    <button class="admin-button" type="submit">Сохранить</button>
  </form>
</section>
<section class="admin-panel">
  <h2>Текущий config/site.php</h2>
  ' . $this->keyValueTable($this->site) . '
</section>';
    }

    private function languageSettings(string $method, array $user): string
    {
        $notice = '';

        if ($method === 'POST') {
            $notice = $this->saveLanguageSettings($user);
        }

        $available = $this->availableFrontendLanguages();
        $enabled = array_values(array_filter((array) ($this->languages['enabled'] ?? []), static fn (mixed $value): bool => is_string($value)));
        $default = (string) ($this->languages['default'] ?? ($enabled[0] ?? 'ru'));
        $structure = (string) ($this->languages['structure'] ?? 'flexible');
        $rows = '';

        foreach ($available as $language) {
            $rows .= '<tr>
  <td><code>' . mur_escape($language) . '</code></td>
  <td><input name="labels[' . mur_escape($language) . ']" value="' . mur_escape((string) ($this->languages['labels'][$language] ?? strtoupper($language))) . '"></td>
  <td><label class="admin-check"><input type="checkbox" name="enabled[]" value="' . mur_escape($language) . '"' . (in_array($language, $enabled, true) ? ' checked' : '') . '><span>' . mur_escape($this->t('Включен')) . '</span></label></td>
  <td><input type="radio" name="default" value="' . mur_escape($language) . '"' . ($language === $default ? ' checked' : '') . '></td>
</tr>';
        }

        return $notice . '<section class="admin-panel">
  <h2>' . mur_escape($this->t('Языки')) . '</h2>
  <p>' . mur_escape($this->t('Эти языки используются публичным сайтом, URL, sitemap, меню и контентными папками. Они не связаны с языком интерфейса админки.')) . '</p>
  <form class="admin-form" method="post" action="/admin/settings/languages">
    <input type="hidden" name="_csrf" value="' . mur_escape((string) $user['csrf']) . '">
    <table class="admin-table"><thead><tr><th>' . mur_escape($this->t('Код')) . '</th><th>' . mur_escape($this->t('Метка')) . '</th><th>' . mur_escape($this->t('Включен')) . '</th><th>' . mur_escape($this->t('Язык по умолчанию')) . '</th></tr></thead><tbody>' . $rows . '</tbody></table>
    <label>' . mur_escape($this->t('Режим зеркальности')) . '
      <select name="structure">
        <option value="flexible"' . ($structure === 'flexible' ? ' selected' : '') . '>flexible</option>
        <option value="mirror"' . ($structure === 'mirror' ? ' selected' : '') . '>mirror</option>
      </select>
    </label>
    <p>' . mur_escape($this->t('flexible: языковые версии могут отличаться по структуре.')) . '</p>
    <p>' . mur_escape($this->t('mirror: структура языковых версий должна совпадать, а админка позже сможет подсвечивать расхождения.')) . '</p>
    <fieldset class="admin-editor-section"><legend>Favicon фронтенда</legend>
      ' . $this->faviconField('frontend') . '
    </fieldset>
    <button class="admin-button" type="submit">' . mur_escape($this->t('Сохранить языки')) . '</button>
  </form>
</section>';
    }

    private function adminSettings(string $method, array $user): string
    {
        $notice = '';

        if ($method === 'POST') {
            $notice = $this->saveAdminSettings($user);
            $user = $this->auth->user() ?? $user;
            $user['csrf'] = $this->auth->csrfToken();
        }

        $available = $this->availableAdminLanguages();
        $default = (string) ($this->adminConfig['ui']['default_language'] ?? 'ru');
        $current = (string) ($user['admin_language'] ?? $default);
        $helpEnabled = $this->helpEnabled();

        return $notice . '<section class="admin-panel">
  <h2>' . mur_escape($this->t('Язык интерфейса админки')) . '</h2>
  <p>' . mur_escape($this->t('Это отдельная настройка. Она не включает и не выключает языки фронтенда.')) . '</p>
  <form class="admin-form" method="post" action="/admin/settings/admin">
    <input type="hidden" name="_csrf" value="' . mur_escape((string) $user['csrf']) . '">
    <label>' . mur_escape($this->t('Язык по умолчанию для админки')) . '
      <select name="default_language">' . $this->plainLanguageOptions($available, $default) . '</select>
    </label>
    <label>' . mur_escape($this->t('Мой язык интерфейса')) . '
      <select name="user_language">' . $this->plainLanguageOptions($available, $current) . '</select>
    </label>
    ' . $this->checkbox('help_enabled', 'Показывать пояснения и примеры в формах админки', $helpEnabled) . '
    <p class="admin-help">Режим помощи добавляет короткие подсказки под полями. Он меняет только интерфейс админки и не добавляет ничего в контент.</p>
    <p><strong>' . mur_escape($this->t('Доступные языки интерфейса')) . ':</strong> <code>' . mur_escape(implode(', ', $available)) . '</code></p>
    ' . $this->faviconField('admin') . '
    <button class="admin-button" type="submit">' . mur_escape($this->t('Сохранить настройки админки')) . '</button>
  </form>
</section>
<section class="admin-panel">
  <h2>' . mur_escape($this->t('Lang-файлы')) . '</h2>
  <p>' . mur_escape($this->t('Админка загружает строки из root/murcms/admin/lang/{lang}.php. Если файла или строки нет, используется русский fallback, а затем сам ключ.')) . '</p>
</section>';
    }

    private function profileSettings(string $method, array $user): string
    {
        $notice = '';

        if ($method === 'POST') {
            $notice = $this->saveProfileSettings($user);
            $user = $this->auth->user() ?? $user;
            $user['csrf'] = $this->auth->csrfToken();
        }

        $available = $this->availableAdminLanguages();
        $current = (string) ($user['admin_language'] ?? $this->adminConfig['ui']['default_language'] ?? 'ru');
        $profile = $this->users->find((string) ($user['id'] ?? '')) ?? $user;

        return $notice . '<section class="admin-panel">
  <h2>Профиль</h2>
  <p><strong>Логин:</strong> <code>' . mur_escape((string) ($profile['username'] ?? '')) . '</code> · <strong>Уровень доступа:</strong> ' . mur_escape(AdminUserManager::roleLabel((string) ($profile['role'] ?? ''))) . '</p>
  <form class="admin-form" method="post" action="/admin/settings/profile">
    <input type="hidden" name="_csrf" value="' . mur_escape((string) $user['csrf']) . '">
    <input type="hidden" name="profile_action" value="contact">
    <div class="admin-form-grid">
      <label>Имя в системе
        <input name="display_name" maxlength="100" value="' . mur_escape((string) ($profile['display_name'] ?? '')) . '" required>
      </label>
      <label>E-mail
        <input name="email" type="email" maxlength="190" autocomplete="email" value="' . mur_escape((string) ($profile['email'] ?? '')) . '" required>
      </label>
      <label>Контактный телефон
        <input name="phone" type="tel" maxlength="50" autocomplete="tel" value="' . mur_escape((string) ($profile['phone'] ?? '')) . '">
      </label>
    </div>
    <button class="admin-button" type="submit">Сохранить профиль</button>
  </form>
</section>
<section class="admin-panel">
  <h2>Интерфейс</h2>
  <form class="admin-form" method="post" action="/admin/settings/profile">
    <input type="hidden" name="_csrf" value="' . mur_escape((string) $user['csrf']) . '">
    <input type="hidden" name="profile_action" value="language">
    <label>Мой язык интерфейса
      <select name="user_language">' . $this->plainLanguageOptions($available, $current) . '</select>
    </label>
    <button class="admin-button" type="submit">Сохранить язык</button>
  </form>
</section>
<section class="admin-panel">
  <h2>Смена пароля</h2>
  <p>Новый пароль должен содержать не менее 14 символов, строчную и прописную буквы, цифру и специальный символ.</p>
  <form class="admin-form" method="post" action="/admin/settings/profile">
    <input type="hidden" name="_csrf" value="' . mur_escape((string) $user['csrf']) . '">
    <input type="hidden" name="profile_action" value="password">
    <label>Текущий пароль
      <input name="current_password" type="password" autocomplete="current-password" required>
    </label>
    <label>Новый пароль
      <input name="new_password" type="password" autocomplete="new-password" minlength="14" required>
    </label>
    <label>Повторите новый пароль
      <input name="new_password_confirm" type="password" autocomplete="new-password" minlength="14" required>
    </label>
    <button class="admin-button" type="submit">Изменить пароль</button>
  </form>
</section>';
    }

    private function usersPage(array $user): string
    {
        $users = $this->users->users();
        usort($users, static function (array $left, array $right): int {
            $rank = AdminUserManager::roleRank((string) ($right['role'] ?? ''))
                <=> AdminUserManager::roleRank((string) ($left['role'] ?? ''));
            return $rank !== 0
                ? $rank
                : strcasecmp((string) ($left['display_name'] ?? ''), (string) ($right['display_name'] ?? ''));
        });

        $rows = '';
        foreach ($users as $managedUser) {
            $id = (string) ($managedUser['id'] ?? '');
            $isCurrent = $id === (string) ($user['id'] ?? '');
            $actions = '';
            if ($isCurrent) {
                $actions = '<a class="admin-button admin-button--ghost" href="/admin/settings/profile">Мой профиль</a>';
            } elseif ($this->users->canManage($user, $managedUser)) {
                $query = '?id=' . rawurlencode($id);
                $actions = '<a class="admin-button admin-button--ghost" href="/admin/settings/users/edit' . mur_escape($query) . '">Редактировать</a>'
                    . '<a class="admin-button admin-button--danger" href="/admin/settings/users/delete' . mur_escape($query) . '">Удалить</a>';
            }
            if ($actions === '') {
                $actions = '<span class="admin-muted">Только просмотр</span>';
            }

            $rows .= '<tr data-admin-user="' . mur_escape($id) . '">
  <td><strong>' . mur_escape((string) ($managedUser['display_name'] ?? '')) . '</strong><br><code>' . mur_escape((string) ($managedUser['username'] ?? '')) . '</code></td>
  <td><span class="admin-role-badge admin-role-badge--' . mur_escape(str_replace('_', '-', AdminUserManager::normalizeRole((string) ($managedUser['role'] ?? '')))) . '">' . mur_escape(AdminUserManager::roleLabel((string) ($managedUser['role'] ?? ''))) . '</span></td>
  <td><a href="mailto:' . mur_escape((string) ($managedUser['email'] ?? '')) . '">' . mur_escape((string) ($managedUser['email'] ?? '')) . '</a>' . ((string) ($managedUser['phone'] ?? '') !== '' ? '<br>' . mur_escape((string) $managedUser['phone']) : '') . '</td>
  <td>' . (($managedUser['active'] ?? false) === true ? 'Активен' : 'Отключён') . '</td>
  <td><div class="admin-actions">' . $actions . '</div></td>
</tr>';
        }

        $create = $this->users->creatableRoles($user) !== []
            ? '<a class="admin-button" href="/admin/settings/users/edit">' . $this->icon('add') . '<span>Создать пользователя</span></a>'
            : '';

        return '<section class="admin-panel">
  <div class="admin-panel-head"><div><h2>Пользователи админ-панели</h2><p>Супер-администратор управляет администраторами и редакторами. Администратор — только редакторами.</p></div>' . $create . '</div>
  <table class="admin-table admin-users-table">
    <thead><tr><th>Пользователь</th><th>Роль</th><th>Контакты</th><th>Статус</th><th>Действия</th></tr></thead>
    <tbody>' . $rows . '</tbody>
  </table>
</section>
<section class="admin-panel">
  <h2>Права редактора</h2>
  <p>Редактор работает со страницами, категориями, метками, блоками, галереями, библиотеками контента и Media Manager. Системные настройки, snippets с доверенным HTML, SEO-конфигурация, плагины и управление шаблоном ему недоступны.</p>
</section>';
    }

    private function userEditor(string $method, array $user): string
    {
        $targetId = trim((string) ($_POST['id'] ?? $_GET['id'] ?? ''));
        $target = $targetId !== '' ? $this->users->find($targetId) : null;
        if ($targetId !== '' && $target === null) {
            return $this->notice('Пользователь не найден.', true)
                . '<p><a class="admin-button admin-button--ghost" href="/admin/settings/users">Вернуться к списку</a></p>';
        }
        $creating = $target === null;
        $notice = '';

        if (!$creating && !$this->users->canManage($user, $target)) {
            return $this->notice('Недостаточно прав для изменения этого пользователя.', true)
                . '<p><a class="admin-button admin-button--ghost" href="/admin/settings/users">Вернуться к списку</a></p>';
        }
        if ($creating && $this->users->creatableRoles($user) === []) {
            return $this->notice('У вас нет прав создавать пользователей.', true);
        }

        if ($method === 'POST') {
            $guard = $this->guardPost($user, 'admin');
            if ($guard !== '') {
                $notice = $guard;
            } else {
                try {
                    $input = $_POST;
                    $language = (string) ($_POST['admin_language'] ?? '');
                    $input['admin_language'] = in_array($language, $this->availableAdminLanguages(), true)
                        ? $language
                        : (string) ($this->adminConfig['ui']['default_language'] ?? 'ru');
                    $target = $this->users->save($user, $input);
                    $targetId = (string) ($target['id'] ?? '');
                    $creating = false;
                    $this->persistAdminUsers();
                    $notice = $this->notice('Пользователь сохранён.');
                } catch (\Throwable $exception) {
                    $notice = $this->notice($exception->getMessage(), true);
                    $target = array_replace($target ?? [], $_POST);
                }
            }
        }

        $target = $target ?? [
            'id' => '',
            'username' => '',
            'display_name' => '',
            'email' => '',
            'phone' => '',
            'role' => $this->users->creatableRoles($user)[0] ?? AdminUserManager::ROLE_EDITOR,
            'admin_language' => (string) ($this->adminConfig['ui']['default_language'] ?? 'ru'),
            'active' => true,
        ];
        $roleOptions = '';
        foreach ($this->users->creatableRoles($user) as $role) {
            $roleOptions .= '<option value="' . mur_escape($role) . '"' . (AdminUserManager::normalizeRole((string) ($target['role'] ?? '')) === $role ? ' selected' : '') . '>' . mur_escape(AdminUserManager::roleLabel($role)) . '</option>';
        }
        $passwordRequired = $creating ? ' required' : '';

        return $notice . '<section class="admin-panel">
  <div class="admin-panel-head"><div><h2>' . ($creating ? 'Новый пользователь' : 'Редактирование пользователя') . '</h2><p>Пароль должен содержать не менее 14 символов, буквы обоих регистров, цифру и специальный символ.</p></div><a class="admin-button admin-button--ghost" href="/admin/settings/users">К списку</a></div>
  <form class="admin-form" method="post" action="/admin/settings/users/edit' . ($targetId !== '' ? '?id=' . rawurlencode($targetId) : '') . '">
    <input type="hidden" name="_csrf" value="' . mur_escape((string) ($user['csrf'] ?? '')) . '">
    <input type="hidden" name="id" value="' . mur_escape($targetId) . '">
    <div class="admin-form-grid">
      <label>Логин
        <input name="username" maxlength="64" autocomplete="username" value="' . mur_escape((string) ($target['username'] ?? '')) . '"' . (!$creating ? ' readonly' : ' required') . '>
      </label>
      <label>Имя в системе
        <input name="display_name" maxlength="100" value="' . mur_escape((string) ($target['display_name'] ?? '')) . '" required>
      </label>
      <label>E-mail
        <input name="email" type="email" maxlength="190" autocomplete="email" value="' . mur_escape((string) ($target['email'] ?? '')) . '" required>
      </label>
      <label>Контактный телефон
        <input name="phone" type="tel" maxlength="50" autocomplete="tel" value="' . mur_escape((string) ($target['phone'] ?? '')) . '">
      </label>
      <label>Уровень доступа
        <select name="role">' . $roleOptions . '</select>
      </label>
      <label>Язык интерфейса
        <select name="admin_language">' . $this->plainLanguageOptions($this->availableAdminLanguages(), (string) ($target['admin_language'] ?? 'ru')) . '</select>
      </label>
      <label>Новый пароль' . (!$creating ? ' (оставьте пустым без изменения)' : '') . '
        <input name="password" type="password" autocomplete="new-password" minlength="14"' . $passwordRequired . '>
      </label>
      <label>Повторите пароль
        <input name="password_confirm" type="password" autocomplete="new-password" minlength="14"' . $passwordRequired . '>
      </label>
    </div>
    ' . $this->checkbox('active', 'Пользователь активен', ($target['active'] ?? false) === true) . '
    <button class="admin-button" type="submit">Сохранить пользователя</button>
  </form>
</section>';
    }

    private function userDeleteConfirm(string $method, array $user): string
    {
        $targetId = trim((string) ($_POST['id'] ?? $_GET['id'] ?? ''));
        $target = $this->users->find($targetId);
        if ($target === null) {
            return $this->notice('Пользователь не найден.', true);
        }
        if (!$this->users->canManage($user, $target)) {
            return $this->notice('Недостаточно прав для удаления этого пользователя.', true);
        }

        if ($method === 'POST') {
            $guard = $this->guardPost($user, 'admin');
            if ($guard !== '') {
                return $guard;
            }
            if (!$this->documentDeleteConfirmed((string) ($_POST['confirm'] ?? ''))) {
                return $this->notice('Для удаления введите Y или 1.', true) . $this->userDeleteForm($target, $user);
            }

            try {
                $deleted = $this->users->delete($user, $targetId);
                $this->persistAdminUsers();
                return $this->notice('Пользователь «' . (string) ($deleted['username'] ?? '') . '» удалён.')
                    . '<p><a class="admin-button" href="/admin/settings/users">Вернуться к списку</a></p>';
            } catch (\Throwable $exception) {
                return $this->notice($exception->getMessage(), true);
            }
        }

        return $this->userDeleteForm($target, $user);
    }

    private function userDeleteForm(array $target, array $user): string
    {
        return '<section class="admin-panel">
  <h2>Удалить пользователя</h2>
  <p>Будет удалён пользователь <strong>' . mur_escape((string) ($target['display_name'] ?? '')) . '</strong> (<code>' . mur_escape((string) ($target['username'] ?? '')) . '</code>). Его активные сессии прекратят работу при следующем запросе.</p>
  <form class="admin-form" method="post" action="/admin/settings/users/delete?id=' . rawurlencode((string) ($target['id'] ?? '')) . '">
    <input type="hidden" name="_csrf" value="' . mur_escape((string) ($user['csrf'] ?? '')) . '">
    <input type="hidden" name="id" value="' . mur_escape((string) ($target['id'] ?? '')) . '">
    <label>Подтверждение
      <input name="confirm" pattern="[Yy1]" maxlength="1" autocomplete="off" required>
    </label>
    <div class="admin-actions"><button class="admin-button admin-button--danger" type="submit">Удалить</button><a class="admin-button admin-button--ghost" href="/admin/settings/users">Отмена</a></div>
  </form>
</section>';
    }

    private function themeSettings(string $method, array $user, string $section = 'general'): string
    {
        $section = in_array($section, ['general', 'home', 'footer'], true) ? $section : 'general';
        $notice = '';

        if ($method === 'POST') {
            $action = (string) ($_POST['theme_action'] ?? 'toggle_block');
            $notice = match ($action) {
                'settings' => $this->saveThemeSettings($user),
                'custom_css' => $this->saveThemeCustomCss($user),
                'block_layout' => $this->saveBlockLayout($user),
                'create_block' => $this->createThemeBlock($user),
                'create_folder' => $this->createThemeBlockFolder($user),
                'clone_block' => $this->cloneThemeBlock($user),
                default => $this->notice('Неизвестное действие с настройками шаблона.', true),
            };
        }

        $active = (string) ($this->themeConfig['active'] ?? 'default');
        $options = $this->themeOptions($active);
        $templates = $this->availableTemplates();
        $colorSchemes = mur_color_scheme_names($active);
        $colorSchemeOptions = array_combine($colorSchemes, $colorSchemes) ?: ['light' => 'light'];
        $listDefaults = is_array($options['list_defaults'] ?? null) ? $options['list_defaults'] : [];
        $brandMode = (string) ($options['brand_mode'] ?? 'html');
        $customCss = $this->themeCustomCss($active);

        if ($section === 'home' || $section === 'footer') {
            $action = $section === 'home' ? '/admin/template/home' : '/admin/template/footer';
            $title = $section === 'home' ? 'Блоки главной' : 'Блоки футера';

            return $notice
                . $this->themeBlockSettingsPanel($section, $title, $options, $user, $action)
                . $this->themeBlockDialogs($user, $action, $section)
                . $this->fileUploadPanel($section, $user)
                . $this->fileReplaceDialog($user);
        }

        return $notice . '<section class="admin-panel">
  <h2>Frontend-шаблон</h2>
  <p>Шаблон управляет публичной оболочкой сайта: layout, header, footer, critical CSS и основными frontend assets. Админка не зависит от этой настройки.</p>
  <form class="admin-form" method="post" action="/admin/template">
    <input type="hidden" name="_csrf" value="' . mur_escape((string) $user['csrf']) . '">
    <input type="hidden" name="theme_action" value="settings">
    <label>Активный шаблон
      <select name="active">' . $this->selectOptions(array_combine($templates, $templates) ?: ['default' => 'default'], $active) . '</select>
    </label>
    <div class="admin-form-grid admin-form-grid--compact">
      <label>Ширина контента, px
        <input name="content_width" type="number" min="760" max="1440" value="' . mur_escape((string) ($options['content_width'] ?? 1040)) . '">
      </label>
      <label>Цветовая схема
        <select name="color_scheme">' . $this->selectOptions($colorSchemeOptions, mur_color_scheme_name($active, (string) ($options['color_scheme'] ?? 'light'))) . '</select>
      </label>
    </div>
    ' . $this->checkbox('sticky_header', 'Закреплять шапку при прокрутке', !empty($options['sticky_header'])) . '
    ' . $this->checkbox('show_breadcrumbs', 'Показывать хлебные крошки на страницах', !empty($options['show_breadcrumbs'])) . '
    ' . $this->checkbox('show_page_summary', 'Показывать краткое описание под заголовком страницы', !empty($options['show_page_summary'])) . '
    ' . $this->checkbox('load_menu_js', 'Подключать JavaScript мобильного меню', !empty($options['load_menu_js'])) . '
    ' . $this->checkbox('footer_menu_enabled', 'Выводить второе меню в футере', !empty($options['footer_menu_enabled'])) . '
    <label>Имя меню футера
      <input name="footer_menu_name" value="' . mur_escape((string) ($options['footer_menu_name'] ?? 'footer')) . '" pattern="[A-Za-z0-9_-]+">
    </label>
    <fieldset class="admin-editor-section">
      <legend>Название или логотип в шапке</legend>
      <label>Что показывать
        <select name="brand_mode">' . $this->selectOptions([
            'site_name' => 'Название сайта из настроек',
            'html' => 'HTML-код',
            'image' => 'Изображение из uploads',
        ], in_array($brandMode, ['site_name', 'html', 'image'], true) ? $brandMode : 'html') . '</select>
      </label>
      <label>HTML внутри ссылки на главную
        <textarea name="brand_html" rows="4" spellcheck="false">' . mur_escape(trim((string) ($options['brand_html'] ?? '')) !== '' ? (string) $options['brand_html'] : mur_default_brand_html()) . '</textarea>
      </label>
      <label>Файл иконки сайта в uploads
        <input name="brand_image" value="' . mur_escape((string) ($options['brand_image'] ?? 'site-icon.svg')) . '" placeholder="site-icon.svg">
      </label>
      <label>Высота логотипа, px
        <input name="brand_image_height" type="number" min="24" max="120" value="' . mur_escape((string) ($options['brand_image_height'] ?? 50)) . '">
      </label>
      <p class="admin-help">Путь задаётся относительно <code>root/uploads/</code>. Поддерживаются SVG и растровые форматы. Изображение выводится только если файл существует и его размеры удалось определить; иначе используется название сайта.</p>
    </fieldset>
    <details class="admin-editor-disclosure" open>
      <summary>Настройки списков</summary>
      <div class="admin-editor-disclosure__body">
        ' . $this->themeListDefaultsFields('children', 'Списки дочерних страниц', is_array($listDefaults['children'] ?? null) ? $listDefaults['children'] : []) . '
        ' . $this->themeListDefaultsFields('category', 'Списки страниц категории', is_array($listDefaults['category'] ?? null) ? $listDefaults['category'] : []) . '
      </div>
    </details>
    <p class="admin-help">Файл активного шаблона: <code>root/murcms/templates/' . mur_escape($active) . '/template.php</code>. Assets шаблона находятся в <code>root/murcms/templates/' . mur_escape($active) . '/assets</code>.</p>
    <button class="admin-button" type="submit">Сохранить настройки шаблона</button>
  </form>
</section>
<details class="admin-panel admin-editor-disclosure">
  <summary>Пользовательские CSS</summary>
  <div class="admin-editor-disclosure__body">
  <p>Стили относятся только к шаблону <code>' . mur_escape($active) . '</code> и хранятся отдельно от файлов дистрибутива.</p>
  <form class="admin-form" method="post" action="/admin/template">
    <input type="hidden" name="_csrf" value="' . mur_escape((string) $user['csrf']) . '">
    <input type="hidden" name="theme_action" value="custom_css">
    <input type="hidden" name="template" value="' . mur_escape($active) . '">
    <label>Critical CSS
      <textarea class="admin-custom-css-textarea" name="critical_css" rows="14" spellcheck="false">' . mur_escape($customCss['critical']) . '</textarea>
      <span class="admin-help">Инлайн-стили первого экрана, максимум 64 KB. Сохраняйте этот блок небольшим.</span>
    </label>
    <label>Дополнительные CSS
      <textarea class="admin-custom-css-textarea" name="additional_css" rows="18" spellcheck="false">' . mur_escape($customCss['additional']) . '</textarea>
      <span class="admin-help">Подключаются последним обычным stylesheet, максимум 256 KB. CSS не проверяется, не минифицируется и не переписывается.</span>
    </label>
    <button class="admin-button" type="submit">Сохранить пользовательские CSS</button>
  </form>
  </div>
</details>
<section class="admin-panel">
  <h2>Меню</h2>
  <p>Файлы меню лежат в <code>root/data/menus/{lang}/</code>. Верхнее меню по умолчанию называется <code>main</code>, меню футера — <code>footer</code>.</p>
  ' . $this->menusTable() . '
</section>';
    }

    private function themeBlockSettingsPanel(string $kind, string $title, array $options, array $user, string $action): string
    {
        $mirror = ($this->languages['structure'] ?? 'flexible') === 'mirror';
        $languages = $mirror
            ? [(string) ($this->languages['default'] ?? 'ru')]
            : $this->orderedLanguages();
        $fields = '';

        foreach ($languages as $language) {
            $ids = $this->configuredThemeBlockIds($options, $kind, $language);
            $fieldId = 'block-layout-' . $kind . '-' . $language;
            $copyButtons = '';

            if (!$mirror) {
                foreach ($languages as $targetLanguage) {
                    if ($targetLanguage === $language) {
                        continue;
                    }
                    $copyButtons .= '<button class="admin-button admin-button--ghost" type="button" data-block-copy-from="' . mur_escape($fieldId) . '" data-block-copy-to="block-layout-' . mur_escape($kind . '-' . $targetLanguage) . '">Скопировать в ' . mur_escape($this->languageHeading($targetLanguage)) . '</button>';
                }
            }

            $label = $mirror
                ? 'Общий порядок (' . $this->languageHeading($language) . ' — основной язык)'
                : $this->languageHeading($language);
            $fields .= '<div class="admin-block-layout-field"><label for="' . mur_escape($fieldId) . '">' . mur_escape($label) . '</label><textarea id="' . mur_escape($fieldId) . '" name="block_ids[' . mur_escape($language) . ']" rows="8" spellcheck="false">' . mur_escape(implode("\n", $ids)) . '</textarea>'
                . ($copyButtons !== '' ? '<div class="admin-actions">' . $copyButtons . '</div>' : '') . '</div>';
        }

        $modeText = $mirror
            ? 'Зеркальный режим: один список применяется ко всем языкам. Для каждого ID сначала ищется файл текущего языка, затем файл основного языка.'
            : 'Гибкий режим: порядок задаётся отдельно для каждого языка. Если выбранного файла нет в неосновном языке, используется блок с тем же ID из основного языка.';

        return '<section class="admin-panel admin-theme-blocks">
  <div class="admin-panel-head"><div><h2>' . mur_escape($title) . '</h2><p>' . mur_escape($modeText) . '</p></div><div class="admin-actions"><button class="admin-button" type="button" data-theme-block-create="' . mur_escape($kind) . '">' . $this->icon('add') . '<span>Создать новый блок</span></button><button class="admin-button admin-button--ghost" type="button" data-theme-folder-create="' . mur_escape($kind) . '">Создать папку</button></div></div>
  <form class="admin-form" method="post" action="' . mur_escape($action) . '">
    <input type="hidden" name="_csrf" value="' . mur_escape((string) $user['csrf']) . '">
    <input type="hidden" name="theme_action" value="block_layout">
    <input type="hidden" name="block_kind" value="' . mur_escape($kind) . '">
    <p class="admin-help">Одно имя файла без <code>.mur.md</code> на строку. Порядок строк равен порядку на сайте; отсутствующие имена не выводятся. Для футера строка <code>menu:footer</code> вставляет указанное меню в точное место списка.</p>
    <div class="admin-block-layout-grid">' . $fields . '</div>
    <button class="admin-button" type="submit">Сохранить порядок ' . ($kind === 'home' ? 'главной' : 'футера') . '</button>
  </form>
  <h3>Файлы ' . ($kind === 'home' ? 'главной' : 'футера') . '</h3>
  ' . $this->themeBlockFilesTable($kind) . '
</section>';
    }

    private function configuredThemeBlockIds(array $options, string $kind, string $language): array
    {
        $layout = is_array($options['block_layout'][$kind] ?? null) ? $options['block_layout'][$kind] : null;
        if ($layout !== null && array_key_exists($language, $layout) && is_array($layout[$language])) {
            return array_values(array_map('strval', $layout[$language]));
        }

        $ids = [];
        foreach ($this->themeBlockFiles($kind, $language) as [$file]) {
            $document = $this->parser->parse($file, $kind, $language);
            $id = $this->themeBlockName($document->fileName);
            if (!in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    private function themeBlockFilesTable(string $kind): string
    {
        $html = '';

        foreach ($this->orderedLanguages() as $language) {
            $rows = '';
            foreach ($this->themeBlockFiles($kind, $language) as [$file, $relative]) {
                $document = $this->parser->parse($file, $kind, $language);
                $id = $this->themeBlockName($document->fileName);
                $rows .= '<tr><td><code>' . mur_escape($id) . '</code></td><td><code>' . mur_escape($relative) . '</code></td><td><div class="admin-row-actions">'
                    . '<a class="admin-link-button" href="/admin/files/edit?scope=' . rawurlencode($kind) . '&amp;file=' . rawurlencode($relative) . '" aria-label="Редактировать" title="Редактировать">' . $this->icon('edit') . '<span class="visually-hidden">Редактировать</span></a>'
                    . '<button class="admin-link-button" type="button" data-theme-block-clone="' . mur_escape($relative) . '" data-theme-block-id="' . mur_escape($id) . '" data-theme-block-language="' . mur_escape($language) . '" aria-label="Сделать дубликат" title="Сделать дубликат">' . $this->icon('copy') . '<span class="visually-hidden">Сделать дубликат</span></button>'
                    . '<a class="admin-link-button" href="/admin/files/download?scope=' . rawurlencode($kind) . '&amp;file=' . rawurlencode($relative) . '" aria-label="Скачать" title="Скачать">' . $this->icon('download') . '<span class="visually-hidden">Скачать</span></a>'
                    . '<a class="admin-link-button" href="#" data-file-replace-open data-file-scope="' . mur_escape($kind) . '" data-file-path="' . mur_escape($relative) . '" aria-label="Загрузить взамен" title="Загрузить взамен">' . $this->icon('replace') . '<span class="visually-hidden">Загрузить взамен</span></a>'
                    . '<a class="admin-link-button admin-link-button--danger" href="/admin/files/delete?scope=' . rawurlencode($kind) . '&amp;file=' . rawurlencode($relative) . '" aria-label="Удалить" title="Удалить">' . $this->icon('delete') . '<span class="visually-hidden">Удалить</span></a>'
                    . '</div></td></tr>';
            }

            if ($rows === '') {
                $rows = '<tr><td colspan="3">Файлы этой языковой ветки не найдены.</td></tr>';
            }
            $html .= '<div class="admin-language-group"><h4>' . mur_escape($this->languageHeading($language)) . '</h4><table class="admin-table"><thead><tr><th>ID</th><th>Файл</th><th>Действия</th></tr></thead><tbody>' . $rows . '</tbody></table></div>';
        }

        return $html;
    }

    private function themeBlockFiles(string $kind, string $language): array
    {
        if (!in_array($kind, ['home', 'footer'], true) || !in_array($language, $this->languages['enabled'] ?? [], true)) {
            return [];
        }

        $directory = mur_path('content/' . $language . '/' . $kind);
        if (!is_dir($directory)) {
            return [];
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile() && str_ends_with($file->getFilename(), '.mur.md')) {
                $files[] = [$file->getPathname(), $this->relativePath($file->getPathname())];
            }
        }
        usort($files, static fn (array $a, array $b): int => strnatcasecmp($a[1], $b[1]));

        return $files;
    }

    private function themeBlockName(string $file): string
    {
        $basename = basename($file);
        return str_ends_with($basename, '.mur.md') ? substr($basename, 0, -7) : pathinfo($basename, PATHINFO_FILENAME);
    }

    private function themeBlockDialogs(array $user, string $action, string $kind): string
    {
        $languageOptions = $this->selectOptions(array_combine($this->orderedLanguages(), array_map(fn (string $language): string => $this->languageHeading($language), $this->orderedLanguages())) ?: [], (string) ($this->languages['default'] ?? 'ru'));
        $kind = $kind === 'footer' ? 'footer' : 'home';
        $folders = $this->themeBlockFolderOptions($kind);

        return '<datalist id="theme-block-folders">' . $folders . '</datalist>
<dialog class="admin-dialog" id="theme-block-create-dialog"><form class="admin-form" method="post" action="' . mur_escape($action) . '">
  <input type="hidden" name="_csrf" value="' . mur_escape((string) $user['csrf']) . '"><input type="hidden" name="theme_action" value="create_block">
  <h2>Создать новый блок</h2><label>Язык<select name="block_language">' . $languageOptions . '</select></label><label>Папка относительно <code>content/{lang}/</code><input name="block_folder" list="theme-block-folders" value="' . mur_escape($kind) . '" required></label><label>ID блока<input name="block_id" pattern="[A-Za-z0-9_-]+" required></label>
  <div class="admin-actions"><button class="admin-button" type="submit">Создать блок</button><button class="admin-button admin-button--ghost" type="button" data-dialog-close>Отмена</button></div>
</form></dialog>
<dialog class="admin-dialog" id="theme-folder-create-dialog"><form class="admin-form" method="post" action="' . mur_escape($action) . '">
  <input type="hidden" name="_csrf" value="' . mur_escape((string) $user['csrf']) . '"><input type="hidden" name="theme_action" value="create_folder">
  <h2>Создать папку</h2><label>Язык<select name="block_language">' . $languageOptions . '</select></label><label>Путь относительно <code>content/{lang}/</code><input name="block_folder" list="theme-block-folders" value="' . mur_escape($kind) . '" placeholder="' . mur_escape($kind) . '/sections" required></label>
  <div class="admin-actions"><button class="admin-button" type="submit">Создать папку</button><button class="admin-button admin-button--ghost" type="button" data-dialog-close>Отмена</button></div>
</form></dialog>
<dialog class="admin-dialog" id="theme-block-clone-dialog"><form class="admin-form" method="post" action="' . mur_escape($action) . '">
  <input type="hidden" name="_csrf" value="' . mur_escape((string) $user['csrf']) . '"><input type="hidden" name="theme_action" value="clone_block"><input type="hidden" name="source_file" value="">
  <h2>Сделать дубликат блока</h2><p>Источник: <code data-clone-source-label></code></p><label>Язык копии<select name="block_language">' . $languageOptions . '</select></label><label>Путь и новый ID относительно <code>content/{lang}/</code><input name="block_target" placeholder="' . mur_escape($kind) . '/block-copy" required></label>
  <div class="admin-actions"><button class="admin-button" type="submit">Создать дубликат</button><button class="admin-button admin-button--ghost" type="button" data-dialog-close>Отмена</button></div>
</form></dialog>';
    }

    private function themeBlockFolderOptions(string $kind): string
    {
        $kind = $kind === 'footer' ? 'footer' : 'home';
        $folders = [$kind];
        foreach ($this->orderedLanguages() as $language) {
            $base = mur_path('content/' . $language);
            $directory = $base . '/' . $kind;
            if (!is_dir($directory)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
            foreach ($iterator as $item) {
                if ($item instanceof \SplFileInfo && $item->isDir()) {
                    $relative = ltrim(str_replace('\\', '/', substr($item->getPathname(), strlen($base))), '/');
                    if ($this->safeThemeBlockFolder($relative) !== '') {
                        $folders[] = $relative;
                    }
                }
            }
        }

        $folders = array_values(array_unique($folders));
        sort($folders, SORT_NATURAL);
        return implode('', array_map(static fn (string $folder): string => '<option value="' . mur_escape($folder) . '"></option>', $folders));
    }

    private function menusTable(): string
    {
        $rows = '';

        foreach ($this->orderedLanguages() as $language) {
            foreach (['main' => 'Верхнее меню', 'footer' => 'Меню футера'] as $name => $label) {
                $relative = 'menus/' . $language . '/' . $name . '.mur.md';
                $file = mur_path($relative);
                $status = is_file($file) ? 'Есть' : 'Не создано';
                $rows .= '<tr><td>' . mur_escape($this->languageHeading($language)) . '</td><td>' . mur_escape($label) . '</td><td><code>' . mur_escape($relative) . '</code></td><td>' . mur_escape($status) . '</td><td>' . $this->toolbar([
                    ['label' => 'Редактировать', 'url' => '/admin/settings/theme/menu?file=' . rawurlencode($relative)],
                ]) . '</td></tr>';
            }
        }

        return '<table class="admin-table"><thead><tr><th>Язык</th><th>Меню</th><th>Файл</th><th>Статус</th><th>Действия</th></tr></thead><tbody>' . $rows . '</tbody></table>';
    }

    private function themeListDefaultsFields(string $source, string $title, array $settings): string
    {
        $prefix = 'list_' . $source . '_';
        $view = (string) ($settings['view'] ?? 'cards');
        $sort = (string) ($settings['sort'] ?? 'order');
        $direction = (string) ($settings['direction'] ?? 'asc');
        $perPage = (string) ($settings['per_page'] ?? '');
        $position = (string) ($settings['position'] ?? 'bottom');

        return '<fieldset class="admin-editor-section">
          <legend>' . mur_escape($title) . '</legend>
          <div class="admin-form-grid admin-form-grid--compact">
            <label>Вид списка<select name="' . mur_escape($prefix) . 'view">' . $this->selectOptions(['cards' => 'Карточки', 'titles' => 'Только заголовки', 'feed' => 'Лента'], $view) . '</select></label>
            <label>Сортировка<select name="' . mur_escape($prefix) . 'sort">' . $this->selectOptions(['order' => 'Порядок', 'date' => 'Дата', 'updated_at' => 'Дата обновления', 'title' => 'Заголовок', 'id' => 'ID', 'filename' => 'Имя файла'], $sort) . '</select></label>
            <label>Направление<select name="' . mur_escape($prefix) . 'direction">' . $this->selectOptions(['asc' => 'По возрастанию', 'desc' => 'По убыванию'], $direction) . '</select></label>
            <label>Элементов на странице<input name="' . mur_escape($prefix) . 'per_page" type="number" min="1" max="100" value="' . mur_escape($perPage) . '" placeholder="без пагинации"></label>
            <label>Позиция списка<select name="' . mur_escape($prefix) . 'position">' . $this->selectOptions(['top' => 'Вверху страницы', 'bottom' => 'Внизу страницы', 'shortcode' => 'В месте shortcode [list]'], $position) . '</select></label>
          </div>
        </fieldset>';
    }

    private function genericFileEditor(string $method, array $user, string $scope): string
    {
        $notice = '';
        $relative = (string) ($_GET['file'] ?? $_POST['file'] ?? '');

        if ($method === 'POST') {
            $notice = $this->guardPost($user, 'editor');
            if ($notice === '') {
                $relative = (string) ($_POST['file'] ?? '');
                $file = mur_path($relative);
                if (!$this->allowedRelativeFile($relative, $scope)) {
                    return $this->notice('Недопустимый файл.', true);
                }
                $this->writeTextFile($file, str_replace(["\r\n", "\r"], "\n", (string) ($_POST['content'] ?? '')));
                $notice = $this->notice('Файл сохранен.');
            }
        }

        if (!$this->allowedRelativeFile($relative, $scope)) {
            return '<section class="admin-panel"><p>Файл не найден или недоступен.</p></section>';
        }

        $file = mur_path($relative);
        $content = is_file($file) ? (string) file_get_contents($file) : '';
        $backUrl = '/admin/template';
        $editorTools = $this->hooks->render('admin.editor_tools', [
            'editor' => 'file',
            'textarea' => 'content',
            'scope' => $scope,
            'file' => $relative,
        ]);
        $syntaxHint = $this->fileEditorSyntaxHint($relative, $scope);
        $defaultAction = '/admin/settings/theme/menu';
        $actionPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
        $action = (is_string($actionPath) && $actionPath !== '') ? $actionPath : $defaultAction;
        if ($relative !== '') {
            $action .= '?file=' . rawurlencode($relative);
        }

        return $notice . '<section class="admin-panel">
  <h2>Файловый редактор</h2>
  <p><code>' . mur_escape($relative) . '</code></p>
  <form class="admin-form" method="post" action="' . mur_escape($action) . '">
    <input type="hidden" name="_csrf" value="' . mur_escape((string) $user['csrf']) . '">
    <input type="hidden" name="file" value="' . mur_escape($relative) . '">
    ' . $editorTools . '
    ' . $syntaxHint . '
    <textarea name="content" rows="28" spellcheck="false">' . mur_escape($content) . '</textarea>
    <div class="admin-actions"><button class="admin-button" type="submit">Сохранить</button><a class="admin-button admin-button--ghost" href="' . mur_escape($backUrl) . '">Назад</a></div>
  </form>
</section>';
    }

    private function fileEditorSyntaxHint(string $relative, string $scope): string
    {
        $relative = str_replace('\\', '/', trim($relative));
        $hint = '';

        if ($scope === 'menus' || preg_match('~^menus/[a-z]{2}/[A-Za-z0-9_-]+\.mur\.md$~', $relative)) {
            $hint = 'Формат меню: <code>href | label | title | li_class | span_class | attrs</code> или <code>href | короткий label | длинный label | title | li_class | span_class | attrs</code>. '
                . '<code>href</code>: <code>page:id</code>, URL, <code>#</code> или путь. '
                . 'Для подменю: <code>[</code> перед родителем и <code>]</code> после последнего дочернего пункта; разделитель: <code>---</code>. '
                . '<code>attrs</code> может содержать <code>target="_blank" rel="noopener"</code>.';
        }

        return $hint !== '' ? '<p class="admin-help">' . $hint . '</p>' : '';
    }

    private function genericDeleteConfirm(string $method, array $user, string $scope): string
    {
        $relative = (string) ($_GET['file'] ?? $_POST['file'] ?? '');

        if (!$this->allowedRelativeFile($relative, $scope)) {
            return '<section class="admin-panel"><p>Файл не найден или недоступен.</p></section>';
        }

        if ($method === 'POST') {
            $guard = $this->guardPost($user, $scope === 'snippets' ? 'admin' : 'editor');
            if ($guard !== '') {
                return $guard;
            }
            if ((string) ($_POST['confirm'] ?? '') !== basename($relative)) {
                return $this->notice('Для удаления нужно ввести имя файла.', true) . $this->genericDeleteForm($relative, $user, $scope);
            }
            $this->deleteFile(mur_path($relative), mur_path());
            return $this->notice('Файл удален.') . '<section class="admin-panel"><a class="admin-button" href="/admin/content/snippets">Вернуться</a></section>';
        }

        return $this->genericDeleteForm($relative, $user, $scope);
    }

    private function genericDeleteForm(string $relative, array $user, string $scope): string
    {
        $action = '/admin/content/snippets/delete';

        return '<section class="admin-panel">
  <h2>Удалить файл</h2>
  <p>Будет удален <code>' . mur_escape($relative) . '</code>. Для подтверждения введите имя файла: <code>' . mur_escape(basename($relative)) . '</code>.</p>
  <form class="admin-form" method="post" action="' . $action . '">
    <input type="hidden" name="_csrf" value="' . mur_escape((string) $user['csrf']) . '">
    <input type="hidden" name="file" value="' . mur_escape($relative) . '">
    <label>Подтверждение<input name="confirm" required></label>
    <div class="admin-actions"><button class="admin-button admin-button--danger" type="submit">Удалить</button><a class="admin-button admin-button--ghost" href="/admin/content/snippets">Отмена</a></div>
  </form>
</section>';
    }

    private function snippetsEditor(string $method, array $user): string
    {
        $notice = isset($_GET['saved']) ? $this->notice('Snippet сохранен.') : '';
        if ($method === 'POST') {
            $notice = $this->saveSnippet($user);
        }
        $editLang = (string) ($_GET['lang'] ?? $_POST['lang'] ?? ($this->languages['default'] ?? 'ru'));
        $editName = (string) ($_GET['name'] ?? $_POST['name'] ?? '');
        $snippet = $this->loadSnippetForEdit($editLang, $editName);
        if ($method === 'POST') {
            $snippet = array_replace($snippet, [
                'body' => (string) ($_POST['body'] ?? ''), 'name' => (string) ($_POST['name'] ?? ''),
                'status' => (string) ($_POST['status'] ?? 'draft'), 'type' => (string) ($_POST['type'] ?? 'html'),
                'extra_meta' => (string) ($_POST['extra_meta'] ?? ''),
            ]);
        }
        $tab = (string) ($_GET['tab'] ?? 'editor');
        $tabs = ['editor' => 'Редактор', 'mine' => 'Мои сниппеты', 'system' => 'Системные', 'upload' => 'Загрузка файла'];
        if (!isset($tabs[$tab]) || $method === 'POST' || $editName !== '') {
            $tab = 'editor';
        }
        $scope = (string) ($_GET['system_scope'] ?? $editLang);
        if ($scope !== 'all' && !in_array($scope, $this->orderedLanguages(), true)) {
            $scope = (string) ($this->languages['default'] ?? 'ru');
        }
        $nav = '<nav class="admin-tabs" aria-label="Snippets">';
        foreach ($tabs as $key => $label) {
            $nav .= '<a href="/admin/content/snippets?tab=' . $key . '&amp;system_scope=' . rawurlencode($scope) . '"' . ($key === $tab ? ' class="is-active" aria-current="page"' : '') . '>' . $label . '</a>';
        }
        $nav .= '</nav>';
        $systems = $this->systemSnippetDescriptions();
        if ($tab === 'system') {
            return $notice . $nav . '<section class="admin-panel">' . $this->systemSnippetRegistry($systems, $scope) . '</section>' . $this->fileReplaceDialog($user);
        }
        if ($tab === 'upload') {
            return $notice . $nav . $this->fileUploadPanel('snippets', $user);
        }
        if ($tab === 'mine') {
            $groups = [];
            foreach ($this->snippetFiles() as $file) {
                [$language, $name] = $this->snippetIdentity($file);
                if (!isset($systems[$name])) {
                    $groups[$language][] = [$file, $name];
                }
            }
            $lists = '';
            foreach (array_merge($this->orderedLanguages(), ['all']) as $language) {
                $rows = '';
                foreach ($groups[$language] ?? [] as [$file, $name]) {
                    $rows .= '<tr><td><code>' . mur_escape($name) . '</code></td><td><code>' . mur_escape($this->relativePath($file)) . '</code></td><td>' . $this->snippetActions($file, $language, $name) . '</td></tr>';
                }
                $lists .= '<section class="admin-panel admin-language-group"><h2>' . mur_escape($this->languageHeading($language)) . '</h2><table class="admin-table"><thead><tr><th>Имя</th><th>Файл</th><th>Действия</th></tr></thead><tbody>' . ($rows !== '' ? $rows : '<tr><td colspan="3">Snippets этой области пока не созданы.</td></tr>') . '</tbody></table></section>';
            }
            return $notice . $nav . $lists . $this->fileReplaceDialog($user);
        }
        $editorTools = $this->hooks->render('admin.editor_tools', [
            'editor' => 'snippet', 'textarea' => 'body', 'scope' => $snippet['lang'],
            'name' => $snippet['name'], 'type' => $snippet['type'],
        ]);
        return $notice . $nav . '<section class="admin-panel"><h2>' . ($snippet['name'] !== '' ? 'Редактировать' : 'Создать') . '</h2>
  <form class="admin-form" method="post" action="/admin/content/snippets">
    <input type="hidden" name="_csrf" value="' . mur_escape((string) $user['csrf']) . '">
    <input type="hidden" name="original_name" value="' . mur_escape($snippet['name']) . '">
    <label class="admin-field-full">Имя<input name="name" value="' . mur_escape($snippet['name']) . '" pattern="[A-Za-z0-9_-]+" list="system-snippet-names" required></label>
    <datalist id="system-snippet-names">' . $this->systemSnippetDatalist($systems) . '</datalist>
    ' . $editorTools . '
    <label class="admin-field-full">Содержимое<textarea name="body" rows="22" spellcheck="false">' . mur_escape($snippet['body']) . '</textarea></label>
    <label>Область<select name="lang">' . $this->snippetScopeOptions($snippet['lang']) . '</select></label>
    <label>Статус<select name="status">' . $this->statusOptions($snippet['status']) . '</select></label>
    <label>Тип<select name="type">' . $this->selectOptions(['html' => 'html', 'php' => 'php'], $snippet['type']) . '</select></label>
    <label class="admin-field-full">Дополнительные параметры<textarea name="extra_meta" rows="6" spellcheck="false">' . mur_escape($snippet['extra_meta']) . '</textarea><span class="admin-help">Front matter: ключ: значение, например parser: murtext или murtext.pretty: 1. Параметры сохраняются перед ---.</span></label>
    <div class="admin-actions"><button class="admin-button" type="submit">' . $this->icon('save') . '<span>Сохранить snippet</span></button><a class="admin-button admin-button--ghost" href="/admin/content/snippets">' . $this->icon('add') . '<span>Новый snippet</span></a></div>
  </form>
  <p class="admin-help">Вставка: [snippet=name] или [ushka=name]. Поиск: текущий язык → Global → основной язык. Черновик останавливает fallback. PHP требует разрешения snippets.allow_php.</p>
</section>';
    }

    private function snippetActions(string $file, string $scope, string $name): string
    {
        $relative = $this->relativePath($file);
        return $this->toolbar([
            ['label' => 'Редактировать', 'url' => '/admin/content/snippets?tab=editor&lang=' . rawurlencode($scope) . '&name=' . rawurlencode($name)],
            ['label' => 'Скачать', 'url' => '/admin/content/snippets/download?file=' . rawurlencode($relative)],
            ['label' => 'Загрузить взамен', 'url' => '#', 'attributes' => 'data-file-replace-open data-file-scope="snippets" data-file-path="' . mur_escape($relative) . '"'],
            ['label' => 'Удалить', 'url' => '/admin/content/snippets/delete?file=' . rawurlencode($relative), 'danger' => true],
        ]);
    }

    private function saveSnippet(array $user): string
    {
        $guard = $this->guardPost($user);

        if ($guard !== '') {
            return $guard;
        }

        $scope = (string) ($_POST['lang'] ?? '');
        $name = $this->safeName((string) ($_POST['name'] ?? ''));
        $status = in_array((string) ($_POST['status'] ?? 'draft'), ['published', 'draft'], true) ? (string) $_POST['status'] : 'draft';
        $type = in_array((string) ($_POST['type'] ?? 'html'), ['html', 'php'], true) ? (string) $_POST['type'] : 'html';
        $body = str_replace(["\r\n", "\r"], "\n", (string) ($_POST['body'] ?? ''));

        if ($name === '') {
            return $this->notice('Имя snippet может содержать только латиницу, цифры, дефис и underscore.', true);
        }

        if ($scope === 'all') {
            $file = mur_path('snippets/' . $name . '.mur.md');
        } elseif (in_array($scope, $this->languages['enabled'] ?? [], true)) {
            $file = mur_path('snippets/' . $scope . '/' . $name . '.mur.md');
        } else {
            return $this->notice('Неверная область snippet.', true);
        }

        $existing = is_file($file) ? $this->parser->parse($file, 'snippet', $scope)->meta : [];
        $meta = array_key_exists('extra_meta', $_POST)
            ? $this->parser->parseHeader((string) $_POST['extra_meta']) : $existing;
        $meta = array_replace($meta, ['id' => $name, 'status' => $status, 'type' => $type]);
        if (is_file($file)) {
            $this->backupManagedFile($file);
        }
        $this->writeDocument($file, $meta, $body);
        $this->redirect('/admin/content/snippets?tab=editor&lang=' . rawurlencode($scope) . '&name=' . rawurlencode($name) . '&saved=1', 303);

        return $this->notice('Snippet сохранен: ' . $this->relativePath($file));
    }

    private function loadSnippetForEdit(string $lang, string $name): array
    {
        $scope = $lang === '' || $lang === 'all' ? 'all' : $this->safeLanguage($lang);
        $fallback = [
            'lang' => $scope,
            'name' => '',
            'status' => 'published',
            'type' => 'html',
            'body' => '',
            'extra_meta' => '',
        ];

        if (!preg_match('/^[A-Za-z0-9_-]+$/', $name)) {
            return $fallback;
        }

        $file = $scope === 'all' ? mur_path('snippets/' . $name . '.mur.md') : mur_path('snippets/' . $scope . '/' . $name . '.mur.md');

        if (!is_file($file)) {
            $fallback['name'] = $name;
            return $fallback;
        }

        $document = $this->parser->parse($file, 'snippet', $scope);

        return [
            'lang' => $scope,
            'name' => $name,
            'status' => $document->status(),
            'type' => strtolower((string) ($document->meta['type'] ?? 'html')),
            'body' => $document->body,
            'extra_meta' => $this->snippetExtraMeta($document->meta),
        ];
    }

    private function snippetFiles(): array
    {
        $files = array_merge(
            glob(mur_path('snippets/*.mur.md'), GLOB_NOSORT) ?: [],
            glob(mur_path('snippets/*/*.mur.md'), GLOB_NOSORT) ?: [],
        );
        sort($files, SORT_NATURAL);

        return $files;
    }

    private function snippetIdentity(string $file): array
    {
        $relative = $this->relativePath($file);
        $parts = explode('/', $relative);
        $basename = (string) end($parts);
        $name = str_ends_with($basename, '.mur.md') ? substr($basename, 0, -7) : pathinfo($basename, PATHINFO_FILENAME);
        $lang = count($parts) === 3 ? (string) ($parts[1] ?? '') : 'all';

        return [$lang, $name];
    }

    private function systemSnippetDescriptions(): array
    {
        return [
            'system-head' => 'Перед закрывающим тегом </head>.',
            'system-body-start' => 'Сразу после открывающего тега <body>.',
            'system-header-before' => 'Непосредственно перед основным header сайта.',
            'system-header-after' => 'Непосредственно после основного header сайта.',
            'system-main-before' => 'Непосредственно перед открывающим тегом <main>.',
            'system-body-end' => 'Перед закрывающим тегом </body>.',
            'system-content-start' => 'В начале <main>, до хлебных крошек и заголовка.',
            'system-article-start' => 'Перед текстом страницы или списком категории.',
            'system-article-end' => 'После текста страницы и списка категории.',
            'system-content-end' => 'В конце <main>, перед его закрывающим тегом.',
            'system-main-after' => 'Сразу после закрывающего тега </main>, перед footer.',
        ];
    }

    private function systemSnippetRegistry(array $snippets, string $scope): string
    {
        $links = '<nav class="admin-scope-links" aria-label="Область системных snippets">';
        foreach (array_merge($this->orderedLanguages(), ['all']) as $language) {
            $links .= '<a href="/admin/content/snippets?tab=system&amp;system_scope=' . rawurlencode($language) . '"' . ($language === $scope ? ' class="is-active" aria-current="page"' : '') . '>' . ($language === 'all' ? 'Global' : mur_escape(strtoupper($language))) . '</a>';
        }
        $rows = '';
        foreach ($snippets as $name => $description) {
            $file = $this->systemSnippetFile($scope, (string) $name);
            $exists = is_file($file);
            $state = $exists ? $this->systemSnippetFileState($file, $scope) : 'Локальный файл не создан';
            $fallback = $exists ? null : $this->systemSnippetFallback($scope, (string) $name);
            if ($fallback !== null) {
                $state .= ' · Fallback ' . $fallback['label'] . ': ' . $this->systemSnippetFileState($fallback['file'], $fallback['scope']);
            }
            $actions = $exists ? $this->snippetActions($file, $scope, (string) $name)
                : $this->toolbar([['label' => $fallback !== null ? 'Создать переопределение' : 'Создать', 'url' => '/admin/content/snippets?tab=editor&lang=' . rawurlencode($scope) . '&name=' . rawurlencode((string) $name)]]);
            $rows .= '<tr data-system-snippet="' . mur_escape((string) $name) . '"><td><code>' . mur_escape((string) $name) . '</code></td><td>' . mur_escape((string) $description) . '</td><td>' . mur_escape($state) . '</td><td>' . $actions . '</td></tr>';
        }
        return '<section class="admin-system-snippets"><h2>Системные позиции</h2>' . $links . '</nav><p class="admin-help">Создание сохраняет файл только после Save. Удаление локального файла оставляет системную позицию и восстанавливает fallback.</p><table class="admin-table"><thead><tr><th>Имя</th><th>Место вывода</th><th>Состояние</th><th>Действия</th></tr></thead><tbody>' . $rows . '</tbody></table></section>';
    }

    private function snippetExtraMeta(array $meta): string
    {
        $lines = [];
        foreach (array_diff_key($meta, array_flip(['id', 'status', 'type'])) as $key => $value) {
            if (is_scalar($value)) {
                $lines[] = $key . ': ' . (string) $value;
            }
        }
        return implode("\n", $lines);
    }

    private function systemSnippetFile(string $scope, string $name): string
    {
        return $scope === 'all'
            ? mur_path('snippets/' . $name . '.mur.md')
            : mur_path('snippets/' . $scope . '/' . $name . '.mur.md');
    }

    /** @return array{file:string,scope:string,label:string}|null */
    private function systemSnippetFallback(string $scope, string $name): ?array
    {
        if ($scope === 'all') {
            return null;
        }

        $global = $this->systemSnippetFile('all', $name);
        if (is_file($global)) {
            return ['file' => $global, 'scope' => 'all', 'label' => 'global'];
        }

        $default = (string) ($this->languages['default'] ?? '');
        if ($default !== '' && $default !== $scope) {
            $defaultFile = $this->systemSnippetFile($default, $name);
            if (is_file($defaultFile)) {
                return ['file' => $defaultFile, 'scope' => $default, 'label' => $this->languageHeading($default)];
            }
        }

        return null;
    }

    private function systemSnippetFileState(string $file, string $scope): string
    {
        try {
            $document = $this->parser->parse($file, 'snippet', $scope);
            return $document->isPublished() ? 'Файл существует · опубликован' : 'Файл существует · Черновик';
        } catch (\Throwable) {
            return 'Файл существует · ошибка чтения';
        }
    }

    private function systemSnippetDatalist(array $snippets): string
    {
        $html = '';
        foreach ($snippets as $name => $description) {
            $html .= '<option value="' . mur_escape((string) $name) . '">' . mur_escape((string) $description) . '</option>';
        }

        return $html;
    }

    private function mediaManager(string $method, array $user): string
    {
        $dir = $this->media->normalizeDir((string) ($_GET['dir'] ?? $_POST['dir'] ?? ''));
        $view = (string) ($_GET['view'] ?? $_POST['view'] ?? 'gallery');
        $view = $view === 'list' ? 'list' : 'gallery';
        $notice = '';

        if ($method === 'POST') {
            $guard = $this->guardPost($user, 'editor');
            if ($guard !== '') {
                $notice = $guard;
            } else {
                $action = (string) ($_POST['action'] ?? 'upload');

                try {
                    if ($action === 'mkdir') {
                        $created = $this->media->makeDirectory($dir, (string) ($_POST['directory'] ?? ''));
                        $notice = $this->notice('Папка создана: uploads/' . $created);
                    } elseif ($action === 'upload') {
                        $results = $this->media->uploadMany(
                            $_FILES['files'] ?? [],
                            $dir,
                            (int) ($_POST['quality'] ?? 80),
                            !empty($_POST['convert_webp']),
                        );
                        $notice = $this->mediaUploadNotice($results);
                    } elseif ($action === 'bulk_urls') {
                        $notice = $this->mediaBulkUrls();
                    } elseif ($action === 'bulk_move') {
                        $files = $this->selectedMediaFiles();
                        if ($files === []) {
                            throw new \RuntimeException('Выберите хотя бы один файл.');
                        }
                        $moved = $this->media->moveFiles($files, (string) ($_POST['target_dir'] ?? ''));
                        $notice = $this->notice('Перемещено файлов: ' . count($moved) . '.');
                    } elseif ($action === 'bulk_delete') {
                        $files = $this->selectedMediaFiles();
                        if ($files === []) {
                            throw new \RuntimeException('Выберите хотя бы один файл.');
                        }
                        $notice = $this->mediaBulkDeleteConfirmation($files, $dir, $view, $user);
                    } elseif ($action === 'bulk_delete_confirm') {
                        $files = $this->selectedMediaFiles();
                        if ($files === []) {
                            throw new \RuntimeException('Выберите хотя бы один файл.');
                        }
                        if (!$this->documentDeleteConfirmed((string) ($_POST['confirm'] ?? ''))) {
                            $notice = $this->notice('Для удаления введите Y или 1.', true)
                                . $this->mediaBulkDeleteConfirmation($files, $dir, $view, $user);
                        } else {
                            foreach ($files as $file) {
                                $this->media->deleteFile($file);
                            }
                            $notice = $this->notice('Удалено файлов: ' . count($files) . '.');
                        }
                    } else {
                        throw new \RuntimeException('Неизвестная операция файлового менеджера.');
                    }
                } catch (\Throwable $exception) {
                    $notice = $this->notice($exception->getMessage(), true);
                }
            }
        }

        $status = $this->media->converterStatus();
        $converter = $status['imagick'] ? 'Imagick' : ($status['gd'] ? 'GD imagewebp' : '');
        $converterNotice = $converter !== ''
            ? $this->notice('WebP-конвертер активен: ' . $converter . '. Рекомендованный дефолт качества: 80.')
            : $this->notice('WebP-конвертер недоступен: установите PHP GD с imagewebp или Imagick. Отключите конвертацию, чтобы сохранить JPEG, PNG или GIF в исходном формате.', true);

        $entries = $this->media->entries($dir);
        $directories = array_values(array_filter($entries, static fn (array $entry): bool => !empty($entry['is_dir'])));
        $files = array_values(array_filter($entries, static fn (array $entry): bool => empty($entry['is_dir'])));
        $pagination = $this->listPagination(count($files));
        $visibleFiles = (int) $pagination['per_page_count'] > 0
            ? array_slice($files, (int) $pagination['offset'], (int) $pagination['per_page_count'])
            : $files;
        $visibleEntries = array_merge($directories, $visibleFiles);
        $selectedLookup = array_flip($this->postedSelection('selected_files'));
        $breadcrumbs = $this->mediaBreadcrumbs($dir, $view, $pagination);
        $parent = $this->media->parentDir($dir);
        $parentLink = $dir !== '' ? '<a class="admin-button admin-button--ghost" href="' . mur_escape($this->mediaListUrl($parent, $view, $pagination, 1)) . '">Вверх</a>' : '';
        $listLink = $this->mediaListUrl($dir, 'list', $pagination, 1);
        $galleryLink = $this->mediaListUrl($dir, 'gallery', $pagination, 1);
        $summary = (int) $pagination['per_page_count'] > 0
            ? 'Файлы: ' . (int) $pagination['visible_start'] . '–' . (int) $pagination['visible_end'] . ' из ' . count($files)
            : 'Файлы: ' . count($files);
        $listing = $view === 'list'
            ? $this->mediaList($visibleEntries, $dir, $view, $selectedLookup, $pagination)
            : $this->mediaGallery($visibleEntries, $dir, $view, $selectedLookup, $pagination);

        return $notice . $converterNotice . '<section class="admin-panel">
  <div class="admin-panel-head">
    <div>
      <h2>Файловый менеджер</h2>
      <p class="admin-kicker">' . $breadcrumbs . '</p>
    </div>
    <div class="admin-actions">
      ' . $parentLink . '
      <a class="admin-button' . ($view === 'gallery' ? '' : ' admin-button--ghost') . '" href="' . mur_escape($galleryLink) . '">Галерея</a>
      <a class="admin-button' . ($view === 'list' ? '' : ' admin-button--ghost') . '" href="' . mur_escape($listLink) . '">Список</a>
    </div>
  </div>
  <form class="admin-form admin-media-pagination-form" method="get" action="/admin/media">
    <input type="hidden" name="dir" value="' . mur_escape($dir) . '">
    <input type="hidden" name="view" value="' . mur_escape($view) . '">
    <label>На странице
      <select name="per_page">' . $this->listPageSizeOptions((string) $pagination['per_page']) . '</select>
    </label>
    <button class="admin-button admin-button--ghost" type="submit">Применить</button>
    <span class="admin-filter-summary">' . mur_escape($summary) . '</span>
  </form>
  ' . $this->mediaPaginationLinks($dir, $view, $pagination, 'top') . '
  <div class="admin-media-tools">
    <form class="admin-form admin-upload" method="post" action="/admin/media?dir=' . rawurlencode($dir) . '&view=' . rawurlencode($view) . '" enctype="multipart/form-data">
      <input type="hidden" name="_csrf" value="' . mur_escape((string) $user['csrf']) . '">
      <input type="hidden" name="dir" value="' . mur_escape($dir) . '">
      <input type="hidden" name="view" value="' . mur_escape($view) . '">
      <input type="hidden" name="action" value="upload">
      <label class="admin-dropzone" data-media-dropzone>
        <span>Перетащите файлы сюда или выберите через поле. Загрузка начнется только после кнопки.</span>
        <input type="file" name="files[]" multiple accept="image/jpeg,image/png,image/webp,image/gif,text/csv,text/plain,application/pdf,.csv,.txt,.pdf" data-media-input>
      </label>
      <label class="admin-check">
        <input type="checkbox" name="convert_webp" value="1" checked data-webp-conversion>
        <span>Конвертировать JPEG, PNG и GIF в WebP</span>
      </label>
      <div class="admin-form-grid">
        <label data-webp-quality>WebP quality
          <input name="quality" type="number" min="1" max="100" value="80">
        </label>
      </div>
      <div class="admin-actions">
        <button class="admin-button" type="submit">Загрузить</button>
      </div>
    </form>
    <form class="admin-form" method="post" action="/admin/media?dir=' . rawurlencode($dir) . '&view=' . rawurlencode($view) . '">
      <input type="hidden" name="_csrf" value="' . mur_escape((string) $user['csrf']) . '">
      <input type="hidden" name="dir" value="' . mur_escape($dir) . '">
      <input type="hidden" name="view" value="' . mur_escape($view) . '">
      <input type="hidden" name="action" value="mkdir">
      <label>Новая папка
        <input name="directory" pattern="[A-Za-z0-9_-]+" placeholder="portfolio" required>
      </label>
      <div class="admin-actions">
        <button class="admin-button admin-button--ghost" type="submit">Создать папку</button>
      </div>
    </form>
  </div>
</section>
<form class="admin-bulk-form" method="post" action="/admin/media" data-selection-scope>
  <input type="hidden" name="_csrf" value="' . mur_escape((string) $user['csrf']) . '">
  <input type="hidden" name="dir" value="' . mur_escape($dir) . '">
  <input type="hidden" name="view" value="' . mur_escape($view) . '">
  <input type="hidden" name="per_page" value="' . mur_escape((string) $pagination['per_page']) . '">
  <input type="hidden" name="page" value="' . (int) $pagination['page'] . '">
  <section class="admin-panel">
    ' . $listing . '
  </section>
  ' . $this->mediaPaginationLinks($dir, $view, $pagination, 'bottom') . '
  ' . $this->mediaBulkControls() . '
  ' . $this->mediaUrlsDialog() . '
  ' . $this->mediaMoveDialog() . '
</form>
<script defer src="' . mur_escape(mur_asset_url('admin-media.js')) . '"></script>';
    }

    private function mediaDelete(string $method, array $user): string
    {
        $file = (string) ($_GET['file'] ?? $_POST['file'] ?? '');
        $dir = $this->media->normalizeDir((string) ($_GET['dir'] ?? $_POST['dir'] ?? ''));
        $view = (string) ($_GET['view'] ?? $_POST['view'] ?? 'gallery');
        $view = $view === 'list' ? 'list' : 'gallery';

        if (!$this->media->canDeleteFile($file)) {
            return '<section class="admin-panel"><p>Файл не найден или недоступен.</p><a class="admin-button admin-button--ghost" href="/admin/media?dir=' . rawurlencode($dir) . '&view=' . rawurlencode($view) . '">Назад</a></section>';
        }

        if ($method === 'POST') {
            $guard = $this->guardPost($user, 'editor');
            if ($guard !== '') {
                return $guard;
            }
            if ((string) ($_POST['confirm'] ?? '') !== basename($file)) {
                return $this->notice('Для удаления нужно ввести имя файла.', true) . $this->mediaDeleteForm($file, $dir, $view, $user);
            }
            $this->media->deleteFile($file);
            return $this->notice('Файл удален.') . '<section class="admin-panel"><a class="admin-button" href="/admin/media?dir=' . rawurlencode($dir) . '&view=' . rawurlencode($view) . '">Вернуться</a></section>';
        }

        return $this->mediaDeleteForm($file, $dir, $view, $user);
    }

    private function mediaDeleteForm(string $file, string $dir, string $view, array $user): string
    {
        return '<section class="admin-panel">
  <h2>Удалить файл</h2>
  <p>Будет удален <code>uploads/' . mur_escape($file) . '</code>. Для подтверждения введите имя файла: <code>' . mur_escape(basename($file)) . '</code>.</p>
  <form class="admin-form" method="post" action="/admin/media/delete">
    <input type="hidden" name="_csrf" value="' . mur_escape((string) $user['csrf']) . '">
    <input type="hidden" name="file" value="' . mur_escape($file) . '">
    <input type="hidden" name="dir" value="' . mur_escape($dir) . '">
    <input type="hidden" name="view" value="' . mur_escape($view) . '">
    <label>Подтверждение<input name="confirm" required></label>
    <div class="admin-actions">
      <button class="admin-button admin-button--danger" type="submit">Удалить</button>
      <a class="admin-button admin-button--ghost" href="/admin/media?dir=' . rawurlencode($dir) . '&view=' . rawurlencode($view) . '">Отмена</a>
    </div>
  </form>
</section>';
    }

    private function mediaList(array $entries, string $dir, string $view, array $selectedLookup, array $pagination): string
    {
        $rows = '';

        foreach ($entries as $entry) {
            if ($entry['is_dir']) {
                $folderUrl = $this->mediaListUrl((string) $entry['relative'], $view, $pagination, 1);
                $rows .= '<tr><td class="admin-media-select"></td><td>Папка</td><td><a href="' . mur_escape($folderUrl) . '"><code>' . mur_escape((string) $entry['name']) . '</code></a></td><td></td><td></td><td>' . $this->toolbar([
                    ['label' => 'Открыть', 'url' => $folderUrl],
                ]) . '</td></tr>';
                continue;
            }

            $relative = (string) $entry['relative'];
            $rows .= '<tr><td class="admin-media-select"><input type="checkbox" name="selected_files[]" value="' . mur_escape($relative) . '" aria-label="' . mur_escape('Выбрать файл «' . (string) $entry['name'] . '»') . '"' . (isset($selectedLookup[$relative]) ? ' checked' : '') . '></td><td>' . mur_escape(strtoupper((string) $entry['extension'])) . '</td><td><code>' . mur_escape((string) $entry['name']) . '</code>' . $this->mediaCodes($entry) . '</td><td>' . mur_escape($this->formatBytes((int) $entry['size'])) . '</td><td>' . mur_escape($entry['dimensions'] ? $entry['dimensions']['width'] . 'x' . $entry['dimensions']['height'] : '') . '</td><td>' . $this->mediaToolbar($entry, $dir, $view) . '</td></tr>';
        }

        if ($rows === '') {
            $rows = '<tr><td colspan="6">Папка пуста.</td></tr>';
        }

        return '<table class="admin-table admin-media-table"><thead><tr><th class="admin-media-select"><span class="visually-hidden">Выбор</span></th><th>Тип</th><th>Имя / код</th><th>Размер</th><th>Габариты</th><th>Действия</th></tr></thead><tbody>' . $rows . '</tbody></table>';
    }

    private function mediaGallery(array $entries, string $dir, string $view, array $selectedLookup, array $pagination): string
    {
        $cards = '';

        foreach ($entries as $entry) {
            if ($entry['is_dir']) {
                $cards .= '<article class="admin-media-card admin-media-card--folder">
  <a href="' . mur_escape($this->mediaListUrl((string) $entry['relative'], $view, $pagination, 1)) . '">
    <span class="admin-media-thumb">DIR</span>
    <strong>' . mur_escape((string) $entry['name']) . '</strong>
  </a>
</article>';
                continue;
            }

            $url = $this->media->url((string) $entry['relative']);
            $preview = $entry['image']
                ? '<img src="' . mur_escape($url) . '" alt="" loading="lazy" decoding="async">'
                : '<span class="admin-media-thumb">' . mur_escape(strtoupper((string) $entry['extension'])) . '</span>';
            $relative = (string) $entry['relative'];

            $cards .= '<article class="admin-media-card">
  <label class="admin-media-card-select"><input type="checkbox" name="selected_files[]" value="' . mur_escape($relative) . '" aria-label="' . mur_escape('Выбрать файл «' . (string) $entry['name'] . '»') . '"' . (isset($selectedLookup[$relative]) ? ' checked' : '') . '><span class="visually-hidden">Выбрать файл</span></label>
  <a class="admin-media-preview" href="' . mur_escape($url) . '" target="_blank" rel="noreferrer">' . $preview . '</a>
  <strong>' . mur_escape((string) $entry['name']) . '</strong>
  <small>' . mur_escape($this->formatBytes((int) $entry['size'])) . ($entry['dimensions'] ? ' · ' . mur_escape($entry['dimensions']['width'] . 'x' . $entry['dimensions']['height']) : '') . '</small>
  ' . $this->mediaCodes($entry) . '
  ' . $this->mediaToolbar($entry, $dir, $view) . '
</article>';
        }

        if ($cards === '') {
            return '<p>Папка пуста.</p>';
        }

        return '<div class="admin-media-grid">' . $cards . '</div>';
    }

    private function mediaToolbar(array $entry, string $dir, string $view): string
    {
        $relative = (string) $entry['relative'];
        $url = $this->media->url($relative);

        return $this->toolbar([
            ['label' => 'Открыть', 'url' => $url],
            ['label' => 'Скачать', 'url' => '/admin/media/download?file=' . rawurlencode($relative)],
            ['label' => 'Удалить', 'url' => '/admin/media/delete?file=' . rawurlencode($relative) . '&dir=' . rawurlencode($dir) . '&view=' . rawurlencode($view), 'danger' => true],
        ]);
    }

    private function mediaCodes(array $entry): string
    {
        $relative = (string) $entry['relative'];
        $url = $this->media->url($relative);
        $urlField = '<label class="admin-copy-field">URL<input readonly value="' . mur_escape($url) . '"></label>';

        if (empty($entry['image'])) {
            return '<div class="admin-media-codes">' . $urlField . '</div>';
        }

        $dimensions = is_array($entry['dimensions'] ?? null) ? $entry['dimensions'] : [];
        $width = isset($dimensions['width']) ? ' width="' . (int) $dimensions['width'] . '"' : '';
        $height = isset($dimensions['height']) ? ' height="' . (int) $dimensions['height'] . '"' : '';
        $img = '<img src="' . $url . '" alt=""' . $width . $height . ' loading="lazy" decoding="async">';

        return '<div class="admin-media-codes">' . $urlField . '<label class="admin-copy-field">IMG<input readonly value="' . mur_escape($img) . '"></label></div>';
    }

    private function mediaBreadcrumbs(string $dir, string $view, array $pagination): string
    {
        $items = [];

        foreach ($this->media->breadcrumbs($dir) as $crumb) {
            $items[] = '<a href="' . mur_escape($this->mediaListUrl((string) $crumb['dir'], $view, $pagination, 1)) . '">' . mur_escape((string) $crumb['label']) . '</a>';
        }

        return implode(' / ', $items);
    }

    private function mediaBulkControls(): string
    {
        return '<section class="admin-panel admin-bulk-panel">
  <h2>Операции с выбранными файлами</h2>
  <div class="admin-selection-tools">
    <button class="admin-button admin-button--ghost" type="button" data-selection-action="all">Выделить все</button>
    <button class="admin-button admin-button--ghost" type="button" data-selection-action="invert">Инвертировать выделение</button>
    <span class="admin-selection-count" data-selection-count>Выбрано: 0</span>
  </div>
  <div class="admin-bulk-actions">
    <button class="admin-button admin-button--ghost" type="submit" name="action" value="bulk_urls" data-media-urls-open data-selection-requires>Получить список адресов</button>
    <button class="admin-button" type="submit" formaction="/admin/media/bulk/download" formmethod="post" data-selection-requires>Скачать ZIP</button>
    <button class="admin-button admin-button--ghost" type="button" data-dialog-open="media-bulk-move-dialog" data-selection-requires>Переместить в папку</button>
    <button class="admin-button admin-button--danger" type="submit" name="action" value="bulk_delete" data-selection-requires>Удалить</button>
  </div>
</section>';
    }

    private function mediaUrlsDialog(): string
    {
        return '<dialog class="admin-dialog" id="media-bulk-urls-dialog">
  <h2>Адреса выбранных файлов</h2>
  <p data-media-urls-count>Файлов: 0.</p>
  <textarea class="admin-code" rows="12" readonly data-media-urls-output></textarea>
  <p class="admin-copy-status" data-media-urls-status aria-live="polite"></p>
  <div class="admin-actions">
    <button class="admin-button" type="button" data-media-urls-copy>Копировать адреса</button>
    <button class="admin-button admin-button--ghost" type="button" data-dialog-close>Закрыть</button>
  </div>
</dialog>';
    }

    private function mediaMoveDialog(): string
    {
        $options = '<option value="">uploads /</option>';
        foreach ($this->media->directories() as $directory) {
            $depth = substr_count($directory, '/');
            $options .= '<option value="' . mur_escape($directory) . '">' . mur_escape(str_repeat('— ', $depth) . $directory) . '</option>';
        }

        return '<dialog class="admin-dialog" id="media-bulk-move-dialog">
  <h2>Переместить файлы</h2>
  <p>Выберите папку внутри <code>uploads</code>. При совпадении имени MurCMS добавит числовой суффикс.</p>
  <label>Папка назначения<select name="target_dir">' . $options . '</select></label>
  <div class="admin-actions">
    <button class="admin-button" type="submit" name="action" value="bulk_move">Переместить</button>
    <button class="admin-button admin-button--ghost" type="button" data-dialog-close>Отмена</button>
  </div>
</dialog>';
    }

    private function selectedMediaFiles(): array
    {
        $files = $this->postedSelection('selected_files');
        foreach ($files as $file) {
            if (!$this->media->canDeleteFile($file)) {
                throw new \RuntimeException('Файл не найден или недоступен: ' . $file);
            }
        }

        return $files;
    }

    private function mediaBulkUrls(): string
    {
        $files = $this->selectedMediaFiles();
        if ($files === []) {
            throw new \RuntimeException('Выберите хотя бы один файл.');
        }

        $urls = array_map(fn (string $file): string => $this->media->url($file), $files);

        return '<section class="admin-panel admin-bulk-result">
  <h2>Адреса выбранных файлов</h2>
  <p>Файлов: ' . count($urls) . '.</p>
  <textarea class="admin-code" rows="' . min(16, max(4, count($urls))) . '" readonly>' . mur_escape(implode("\n", $urls)) . '</textarea>
</section>';
    }

    private function mediaBulkDeleteConfirmation(array $files, string $dir, string $view, array $user): string
    {
        $hidden = '';
        foreach ($files as $file) {
            $hidden .= '<input type="hidden" name="selected_files[]" value="' . mur_escape($file) . '">';
        }

        return '<section class="admin-panel admin-bulk-confirmation">
  <h2>Подтверждение группового удаления</h2>
  <p>Будет удалено файлов: <strong>' . count($files) . '</strong>. Для подтверждения введите <code>Y</code> или <code>1</code>.</p>
  <form class="admin-form" method="post" action="/admin/media">
    <input type="hidden" name="_csrf" value="' . mur_escape((string) $user['csrf']) . '">
    <input type="hidden" name="action" value="bulk_delete_confirm">
    <input type="hidden" name="dir" value="' . mur_escape($dir) . '">
    <input type="hidden" name="view" value="' . mur_escape($view) . '">
    <input type="hidden" name="per_page" value="' . mur_escape((string) ($_POST['per_page'] ?? 'all')) . '">
    <input type="hidden" name="page" value="' . max(1, (int) ($_POST['page'] ?? 1)) . '">
    ' . $hidden . '
    <label>Подтверждение<input name="confirm" pattern="[Yy1]" maxlength="1" autocomplete="off" required></label>
    <div class="admin-actions"><button class="admin-button admin-button--danger" type="submit">Удалить файлы</button><a class="admin-button admin-button--ghost" href="' . mur_escape($this->mediaListUrl($dir, $view, ['per_page' => (string) ($_POST['per_page'] ?? 'all')], max(1, (int) ($_POST['page'] ?? 1)))) . '">Отмена</a></div>
  </form>
</section>';
    }

    private function listPageSizeOptions(string $selected): string
    {
        return '<option value="all"' . ($selected === 'all' ? ' selected' : '') . '>Все</option>'
            . '<option value="50"' . ($selected === '50' ? ' selected' : '') . '>50</option>'
            . '<option value="10"' . ($selected === '10' ? ' selected' : '') . '>10</option>';
    }

    private function mediaListUrl(string $dir, string $view, array $pagination, int $page): string
    {
        $query = ['view' => $view === 'list' ? 'list' : 'gallery'];
        if ($dir !== '') {
            $query = ['dir' => $dir] + $query;
        }
        if ((string) ($pagination['per_page'] ?? 'all') !== 'all') {
            $query['per_page'] = (string) $pagination['per_page'];
        }
        if ($page > 1) {
            $query['page'] = $page;
        }

        return '/admin/media?' . http_build_query($query);
    }

    private function mediaPaginationLinks(string $dir, string $view, array $pagination, string $position): string
    {
        $page = (int) ($pagination['page'] ?? 1);
        $totalPages = (int) ($pagination['total_pages'] ?? 1);
        if ($totalPages <= 1) {
            return '';
        }

        $pageNumbers = [1, $totalPages];
        for ($number = max(1, $page - 2); $number <= min($totalPages, $page + 2); $number++) {
            $pageNumbers[] = $number;
        }
        $pageNumbers = array_values(array_unique($pageNumbers));
        sort($pageNumbers);

        $links = '';
        if ($page > 1) {
            $links .= '<a href="' . mur_escape($this->mediaListUrl($dir, $view, $pagination, $page - 1)) . '" rel="prev">Назад</a>';
        }
        $previousNumber = 0;
        foreach ($pageNumbers as $number) {
            if ($previousNumber > 0 && $number > $previousNumber + 1) {
                $links .= '<span class="admin-pagination-gap" aria-hidden="true">…</span>';
            }
            $links .= $number === $page
                ? '<span aria-current="page">' . $number . '</span>'
                : '<a href="' . mur_escape($this->mediaListUrl($dir, $view, $pagination, $number)) . '">' . $number . '</a>';
            $previousNumber = $number;
        }
        if ($page < $totalPages) {
            $links .= '<a href="' . mur_escape($this->mediaListUrl($dir, $view, $pagination, $page + 1)) . '" rel="next">Вперёд</a>';
        }

        return '<nav class="admin-pagination admin-pagination--' . mur_escape($position) . '" aria-label="' . mur_escape('Пагинация файлов ' . ($position === 'bottom' ? 'внизу' : 'вверху')) . '">' . $links . '</nav>';
    }

    private function postedSelection(string $field): array
    {
        $posted = is_array($_POST[$field] ?? null) ? $_POST[$field] : [];
        $selected = [];
        foreach (array_slice($posted, 0, 1000) as $value) {
            if (!is_scalar($value)) {
                continue;
            }
            $value = trim((string) $value);
            if ($value !== '' && strlen($value) <= 512 && !preg_match('/[\x00-\x1F\x7F]/', $value)) {
                $selected[$value] = true;
            }
        }

        return array_keys($selected);
    }

    private function mediaUploadNotice(array $results): string
    {
        if ($results === []) {
            return $this->notice('Файлы не выбраны.', true);
        }

        $ok = 0;
        $messages = [];

        foreach ($results as $result) {
            if (!empty($result['ok'])) {
                $ok++;
            }
            $messages[] = ((string) ($result['name'] ?? 'file')) . ': ' . ((string) ($result['message'] ?? ''));
        }

        return $this->notice('Загрузка: ' . $ok . '/' . count($results) . '. ' . implode(' ', $messages), $ok !== count($results));
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }

        if ($bytes < 1048576) {
            return round($bytes / 1024, 1) . ' KB';
        }

        return round($bytes / 1048576, 2) . ' MB';
    }

    private function pluginsPage(string $method, array $user): string
    {
        $notice = $method === 'POST' ? $this->savePluginsConfig($user) : '';
        $enabled = $this->enabledPlugins();
        $rows = '';
        foreach ($this->availablePlugins() as $plugin) {
            $checked = in_array($plugin, $enabled, true);
            $info = $this->plugins->metadata($plugin);
            $rows .= '<tr><td><strong>' . mur_escape($info['name'] ?? $plugin) . '</strong><small class="admin-help">' . mur_escape($info['description'] ?? '') . '</small></td><td><code>' . mur_escape($plugin) . '</code></td><td>' . mur_escape($info['version'] ?? '') . '</td><td>' . $this->statusIcon($checked ? 'published' : 'draft', $checked ? 'Включён' : 'Отключён') . '</td><td><label class="admin-check"><input type="checkbox" name="plugins[]" value="' . mur_escape($plugin) . '"' . ($checked ? ' checked' : '') . '><span>Включить</span></label></td></tr>';
        }
        return $notice . '<section class="admin-panel"><h2>Управление плагинами</h2><form class="admin-form" method="post" action="/admin/plugins"><input type="hidden" name="_csrf" value="' . mur_escape((string) $user['csrf']) . '"><table class="admin-table"><thead><tr><th>Плагин</th><th>ID</th><th>Версия</th><th>Состояние</th><th>Управление</th></tr></thead><tbody>' . $rows . '</tbody></table><button class="admin-button" type="submit">Сохранить список плагинов</button></form></section>';
    }

    private function saveLanguageSettings(array $user): string
    {
        $guard = $this->guardPost($user);

        if ($guard !== '') {
            return $guard;
        }

        $available = $this->availableFrontendLanguages();
        $enabled = array_values(array_intersect($available, array_values((array) ($_POST['enabled'] ?? []))));
        $default = (string) ($_POST['default'] ?? ($this->languages['default'] ?? 'ru'));

        if (!in_array($default, $enabled, true)) {
            $enabled[] = $default;
        }

        if ($enabled === []) {
            $enabled = [(string) ($this->languages['default'] ?? 'ru')];
        }

        $labels = [];
        $postedLabels = is_array($_POST['labels'] ?? null) ? $_POST['labels'] : [];

        foreach ($available as $language) {
            $label = trim((string) ($postedLabels[$language] ?? $this->languages['labels'][$language] ?? strtoupper($language)));
            $labels[$language] = $label !== '' ? $label : strtoupper($language);
        }

        $structure = (string) ($_POST['structure'] ?? 'flexible');
        $structure = $structure === 'mirror' ? 'mirror' : 'flexible';
        $this->languages['available'] = $available;
        $this->languages['enabled'] = array_values(array_unique($enabled));
        $this->languages['default'] = in_array($default, $this->languages['enabled'], true) ? $default : $this->languages['enabled'][0];
        $this->languages['structure'] = $structure;
        $this->languages['labels'] = $labels;
        $this->writeConfigFile('config/languages.php', $this->languages);
        $this->saveFavicon('frontend');

        return $this->notice($this->t('Настройки языков сохранены.'));
    }

    private function saveThemeSettings(array $user): string
    {
        $guard = $this->guardPost($user);

        if ($guard !== '') {
            return $guard;
        }

        $templates = $this->availableTemplates();
        $active = (string) ($_POST['active'] ?? $this->themeConfig['active'] ?? 'default');
        if (!in_array($active, $templates, true)) {
            $active = 'default';
        }

        $colorScheme = mur_color_scheme_name($active, (string) ($_POST['color_scheme'] ?? 'light'));
        $currentOptions = $this->themeOptions($active);
        $options = [
            'content_width' => max(760, min(1440, (int) ($_POST['content_width'] ?? 1040))),
            'color_scheme' => $colorScheme,
            'sticky_header' => !empty($_POST['sticky_header']),
            'show_breadcrumbs' => !empty($_POST['show_breadcrumbs']),
            'show_page_summary' => !empty($_POST['show_page_summary']),
            'load_menu_js' => !empty($_POST['load_menu_js']),
            'footer_blocks' => !array_key_exists('footer_blocks', $currentOptions) || !empty($currentOptions['footer_blocks']),
            'footer_menu_enabled' => !empty($_POST['footer_menu_enabled']),
            'footer_menu_name' => $this->safeName((string) ($_POST['footer_menu_name'] ?? 'footer')) ?: 'footer',
            'brand_mode' => in_array((string) ($_POST['brand_mode'] ?? ''), ['site_name', 'html', 'image'], true)
                ? (string) $_POST['brand_mode']
                : 'site_name',
            'brand_html' => trim((string) ($_POST['brand_html'] ?? '')),
            'brand_image' => $this->safeBrandImagePath((string) ($_POST['brand_image'] ?? 'site-icon.svg')),
            'brand_image_height' => max(24, min(120, (int) ($_POST['brand_image_height'] ?? 50))),
            'list_defaults' => [
                'children' => $this->postedListDefaults('children'),
                'category' => $this->postedListDefaults('category'),
            ],
        ];
        if (is_array($currentOptions['block_layout'] ?? null)) {
            $options['block_layout'] = $currentOptions['block_layout'];
        }

        $this->themeConfig['active'] = $active;
        $this->themeConfig['templates'] = is_array($this->themeConfig['templates'] ?? null) ? $this->themeConfig['templates'] : [];
        $this->themeConfig['templates'][$active] = ['options' => $options];
        $this->writeConfigFile('config/theme.php', $this->themeConfig);

        return $this->notice('Настройки frontend-шаблона сохранены.');
    }

    private function saveThemeCustomCss(array $user): string
    {
        $guard = $this->guardPost($user);
        if ($guard !== '') {
            return $guard;
        }

        $template = strtolower(trim((string) ($_POST['template'] ?? '')));
        if ($this->safeName($template) !== $template || !in_array($template, $this->availableTemplates(), true)) {
            return $this->notice('Недопустимое имя frontend-шаблона.', true);
        }

        $critical = (string) ($_POST['critical_css'] ?? '');
        $additional = (string) ($_POST['additional_css'] ?? '');
        if (strlen($critical) > self::CUSTOM_CRITICAL_CSS_LIMIT) {
            return $this->notice('Critical CSS превышает допустимый размер 64 KB.', true);
        }
        if (strlen($additional) > self::CUSTOM_ADDITIONAL_CSS_LIMIT) {
            return $this->notice('Дополнительные CSS превышают допустимый размер 256 KB.', true);
        }

        try {
            $this->syncThemeCustomCssFile($template, 'critical.css', $critical);
            $this->syncThemeCustomCssFile($template, 'custom.css', $additional);
        } catch (\Throwable $exception) {
            return $this->notice('Не удалось сохранить пользовательские CSS: ' . $exception->getMessage(), true);
        }

        return $this->notice('Пользовательские CSS для шаблона ' . $template . ' сохранены.');
    }

    /** @return array{critical:string,additional:string} */
    private function themeCustomCss(string $template): array
    {
        $read = static function (string $file): string {
            $content = is_file($file) && is_readable($file) ? file_get_contents($file) : false;
            return is_string($content) ? $content : '';
        };

        return [
            'critical' => $read(mur_template_custom_css_path($template, 'critical.css')),
            'additional' => $read(mur_template_custom_css_path($template, 'custom.css')),
        ];
    }

    private function syncThemeCustomCssFile(string $template, string $name, string $content): void
    {
        $file = mur_template_custom_css_path($template, $name);
        if (trim($content) === '') {
            if (is_file($file)) {
                $this->deleteFile($file, mur_uploads_path());
            }
            return;
        }

        $this->writeUploadsTextFile($file, $content);
    }

    private function writeUploadsTextFile(string $file, string $content): void
    {
        $base = realpath(mur_uploads_path());
        if ($base === false) {
            throw new \RuntimeException('каталог uploads недоступен');
        }

        $directory = dirname($file);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException('не удалось создать каталог пользовательских CSS');
        }
        if (!$this->isSafePath($directory, $base)) {
            throw new \RuntimeException('небезопасный путь пользовательских CSS');
        }

        $tmp = tempnam($directory, '.mur-css-');
        if ($tmp === false) {
            throw new \RuntimeException('не удалось создать временный CSS-файл');
        }

        try {
            $written = file_put_contents($tmp, $content, LOCK_EX);
            if ($written === false || $written !== strlen($content)) {
                throw new \RuntimeException('не удалось полностью записать временный CSS-файл');
            }
            if (!rename($tmp, $file)) {
                throw new \RuntimeException('не удалось атомарно заменить CSS-файл');
            }
        } finally {
            if (is_file($tmp)) {
                @unlink($tmp);
            }
        }
    }

    private function saveBlockLayout(array $user): string
    {
        $guard = $this->guardPost($user);
        if ($guard !== '') {
            return $guard;
        }

        $kind = (string) ($_POST['block_kind'] ?? '');
        if (!in_array($kind, ['home', 'footer'], true)) {
            return $this->notice('Неизвестный тип блоков.', true);
        }

        $active = (string) ($this->themeConfig['active'] ?? 'default');
        $options = $this->themeOptions($active);
        $layout = is_array($options['block_layout'] ?? null) ? $options['block_layout'] : [];
        $layout[$kind] = is_array($layout[$kind] ?? null) ? $layout[$kind] : [];
        $posted = is_array($_POST['block_ids'] ?? null) ? $_POST['block_ids'] : [];
        $languages = ($this->languages['structure'] ?? 'flexible') === 'mirror'
            ? [(string) ($this->languages['default'] ?? 'ru')]
            : $this->orderedLanguages();

        foreach ($languages as $language) {
            $layout[$kind][$language] = $this->parseBlockIdList((string) ($posted[$language] ?? ''), $kind);
        }

        $options['block_layout'] = $layout;
        $this->themeConfig['templates'][$active] = ['options' => $options];
        $this->writeConfigFile('config/theme.php', $this->themeConfig);

        return $this->notice('Порядок и видимость блоков сохранены.');
    }

    private function parseBlockIdList(string $value, string $kind): array
    {
        $ids = [];
        foreach (preg_split('/\R/u', $value) ?: [] as $line) {
            $line = trim($line);
            $id = $this->safeName($line);
            if ($kind === 'footer' && preg_match('/^menu:([A-Za-z0-9_-]+)$/', $line, $match)) {
                $id = 'menu:' . $match[1];
            }
            if ($id !== '' && !in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    private function createThemeBlock(array $user): string
    {
        $guard = $this->guardPost($user);
        if ($guard !== '') {
            return $guard;
        }

        $language = $this->safeLanguage((string) ($_POST['block_language'] ?? ''));
        $folder = $this->safeThemeBlockFolder((string) ($_POST['block_folder'] ?? ''));
        $id = $this->safeName(trim((string) ($_POST['block_id'] ?? '')));
        if ($folder === '' || $id === '') {
            return $this->notice('Укажите допустимые язык, папку и ID блока.', true);
        }

        $relative = 'content/' . $language . '/' . $folder . '/' . $id . '.mur.md';
        $file = mur_path($relative);
        if (is_file($file) || $this->themeBlockIdExists($language, $folder, $id)) {
            return $this->notice('Файл или блок с таким ID уже существует в этой области.', true);
        }

        $this->writeTextFile($file, "title: {$id}\n---\n\n");
        return $this->notice('Блок создан: ' . $relative);
    }

    private function createThemeBlockFolder(array $user): string
    {
        $guard = $this->guardPost($user);
        if ($guard !== '') {
            return $guard;
        }

        $language = $this->safeLanguage((string) ($_POST['block_language'] ?? ''));
        $folder = $this->safeThemeBlockFolder((string) ($_POST['block_folder'] ?? ''));
        if ($folder === '') {
            return $this->notice('Укажите допустимый путь внутри home или footer.', true);
        }

        $base = realpath(mur_path('content/' . $language));
        $directory = mur_path('content/' . $language . '/' . $folder);
        if ($base === false) {
            return $this->notice('Языковая папка content недоступна.', true);
        }
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            return $this->notice('Не удалось создать папку.', true);
        }
        $resolved = realpath($directory);
        if ($resolved === false || !str_starts_with($resolved, $base . DIRECTORY_SEPARATOR)) {
            return $this->notice('Недопустимый путь папки.', true);
        }

        return $this->notice('Папка создана: content/' . $language . '/' . $folder);
    }

    private function cloneThemeBlock(array $user): string
    {
        $guard = $this->guardPost($user);
        if ($guard !== '') {
            return $guard;
        }

        $sourceRelative = (string) ($_POST['source_file'] ?? '');
        $language = $this->safeLanguage((string) ($_POST['block_language'] ?? ''));
        $target = trim(str_replace('\\', '/', (string) ($_POST['block_target'] ?? '')), '/');
        if (!preg_match('~^(home|footer)(?:/[A-Za-z0-9_-]+)*/([A-Za-z0-9_-]+)$~', $target, $match)
            || !preg_match('~^content/[a-z]{2}/(home|footer)/(?:[A-Za-z0-9_-]+/)*[A-Za-z0-9_-]+\.mur\.md$~', str_replace('\\', '/', $sourceRelative), $sourceMatch)) {
            return $this->notice('Недопустимый источник или путь нового блока.', true);
        }
        $sourceCollection = $this->fileCollection((string) $sourceMatch[1]);
        if ($sourceCollection === null || !$this->collectionFileAllowed($sourceCollection, $sourceRelative)) {
            return $this->notice('Недопустимый источник или путь нового блока.', true);
        }

        $folder = dirname($target);
        $id = (string) $match[2];
        $relative = 'content/' . $language . '/' . $target . '.mur.md';
        $file = mur_path($relative);
        if (is_file($file) || $this->themeBlockIdExists($language, $folder, $id)) {
            return $this->notice('Файл или блок с таким ID уже существует в этой области.', true);
        }

        $content = (string) file_get_contents(mur_path($sourceRelative));
        $content = preg_replace('/^(?:id|status|order|footer\.position):\s*.*\R?/mi', '', $content) ?? $content;
        $this->writeTextFile($file, $content);

        return $this->notice('Дубликат создан: ' . $relative);
    }

    private function safeThemeBlockFolder(string $folder): string
    {
        $folder = trim(str_replace('\\', '/', $folder), '/');
        return preg_match('~^(?:home|footer)(?:/[A-Za-z0-9_-]+)*$~', $folder) ? $folder : '';
    }

    private function themeBlockIdExists(string $language, string $folder, string $id): bool
    {
        $kind = explode('/', $folder, 2)[0];
        foreach ($this->themeBlockFiles($kind, $language) as [$file]) {
            if ($this->themeBlockName($file) === $id) {
                return true;
            }
        }

        return false;
    }

    private function postedListDefaults(string $source): array
    {
        $prefix = 'list_' . $source . '_';
        $view = (string) ($_POST[$prefix . 'view'] ?? 'cards');
        $sort = (string) ($_POST[$prefix . 'sort'] ?? 'order');
        $direction = (string) ($_POST[$prefix . 'direction'] ?? 'asc');
        $position = (string) ($_POST[$prefix . 'position'] ?? 'bottom');
        $perPage = trim((string) ($_POST[$prefix . 'per_page'] ?? ''));

        return [
            'view' => in_array($view, ['cards', 'titles', 'feed'], true) ? $view : 'cards',
            'sort' => in_array($sort, ['order', 'date', 'updated_at', 'title', 'id', 'filename'], true) ? $sort : 'order',
            'direction' => in_array($direction, ['asc', 'desc'], true) ? $direction : 'asc',
            'per_page' => $perPage !== '' && ctype_digit($perPage) ? (string) max(1, min(100, (int) $perPage)) : '',
            'position' => in_array($position, ['top', 'bottom', 'shortcode'], true) ? $position : 'bottom',
        ];
    }

    private function safeBrandImagePath(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));
        $path = preg_replace('~^/?uploads/~i', '', $path) ?? '';

        if ($path === '' || str_contains($path, '..') || !preg_match('~^[a-zA-Z0-9_./-]+\.(?:avif|gif|jpe?g|png|svg|webp)$~i', $path)) {
            return 'site-icon.svg';
        }

        return ltrim($path, '/');
    }

    private function saveAdminSettings(array $user): string
    {
        $guard = $this->guardPost($user);

        if ($guard !== '') {
            return $guard;
        }

        $available = $this->availableAdminLanguages();
        $default = (string) ($_POST['default_language'] ?? ($this->adminConfig['ui']['default_language'] ?? 'ru'));
        $userLanguage = (string) ($_POST['user_language'] ?? ($user['admin_language'] ?? $default));

        if (!in_array($default, $available, true)) {
            $default = 'ru';
        }

        if (!in_array($userLanguage, $available, true)) {
            $userLanguage = $default;
        }

        $this->adminConfig['ui']['default_language'] = $default;
        $this->adminConfig['ui']['available_languages'] = $available;
        $this->adminConfig['ui']['help_enabled'] = isset($_POST['help_enabled']);

        foreach (($this->adminConfig['users'] ?? []) as &$adminUser) {
            if (!is_array($adminUser)) {
                continue;
            }

            if ((string) ($adminUser['id'] ?? '') === (string) ($user['id'] ?? '')) {
                $adminUser['admin_language'] = $userLanguage;
            }
        }
        unset($adminUser);

        $this->persistAdminConfig();
        $this->saveFavicon('admin');
        $this->auth->updateSessionUser(['admin_language' => $userLanguage]);
        $this->i18n->setLanguage($userLanguage);

        return $this->notice($this->t('Настройки админки сохранены.'));
    }

    private function saveProfileSettings(array $user): string
    {
        $guard = $this->guardPost($user, 'editor');

        if ($guard !== '') {
            return $guard;
        }

        $action = (string) ($_POST['profile_action'] ?? '');

        if ($action === 'contact') {
            try {
                $profile = $this->users->updateOwnProfile($user, $_POST);
                $this->persistAdminUsers();
                $this->auth->syncUserRecord($profile);
                return $this->notice('Профиль сохранён.');
            } catch (\Throwable $exception) {
                return $this->notice($exception->getMessage(), true);
            }
        }

        if ($action === 'language') {
            $available = $this->availableAdminLanguages();
            $language = (string) ($_POST['user_language'] ?? '');

            if (!in_array($language, $available, true)) {
                return $this->notice('Выбран недоступный язык интерфейса.', true);
            }

            $updated = $this->updateAdminUser(
                (string) ($user['id'] ?? ''),
                ['admin_language' => $language],
            );

            if (!is_array($updated)) {
                return $this->notice('Пользователь не найден в конфигурации.', true);
            }

            $this->persistAdminConfig();
            $this->auth->syncUserRecord($updated);
            $this->i18n->setLanguage($language);

            return $this->notice('Язык интерфейса сохранен.');
        }

        if ($action !== 'password') {
            return $this->notice('Неизвестное действие профиля.', true);
        }

        $currentPassword = (string) ($_POST['current_password'] ?? '');
        $newPassword = (string) ($_POST['new_password'] ?? '');
        $confirmation = (string) ($_POST['new_password_confirm'] ?? '');

        if (!$this->auth->verifyCurrentPassword($user, $currentPassword)) {
            return $this->notice('Текущий пароль указан неверно.', true);
        }

        if ($newPassword !== $confirmation) {
            return $this->notice('Новый пароль и подтверждение не совпадают.', true);
        }

        $passwordError = AdminUserManager::passwordPolicyError($newPassword);

        if ($passwordError !== '') {
            return $this->notice($passwordError, true);
        }

        if (password_verify($newPassword, $this->passwordHashForUser((string) ($user['id'] ?? '')))) {
            return $this->notice('Новый пароль должен отличаться от текущего.', true);
        }

        $updated = $this->updateAdminUser(
            (string) ($user['id'] ?? ''),
            ['password_hash' => password_hash($newPassword, PASSWORD_DEFAULT)],
        );

        if (!is_array($updated)) {
            return $this->notice('Пользователь не найден в конфигурации.', true);
        }

        $this->persistAdminConfig();
        $this->auth->syncUserRecord($updated);
        $this->auth->renewSecurityContext();

        return $this->notice('Пароль изменен. Новые данные уже действуют.');
    }

    private function passwordPolicyError(string $password): string
    {
        return AdminUserManager::passwordPolicyError($password);
    }

    private function passwordHashForUser(string $userId): string
    {
        foreach (($this->adminConfig['users'] ?? []) as $adminUser) {
            if (is_array($adminUser) && (string) ($adminUser['id'] ?? '') === $userId) {
                return (string) ($adminUser['password_hash'] ?? '');
            }
        }

        return '';
    }

    private function updateAdminUser(string $userId, array $fields): ?array
    {
        foreach ((array) ($this->adminConfig['users'] ?? []) as $index => $adminUser) {
            if (!is_array($adminUser) || (string) ($adminUser['id'] ?? '') !== $userId) {
                continue;
            }

            $updated = array_replace($adminUser, $fields);
            $this->adminConfig['users'][$index] = $updated;

            return $updated;
        }

        return null;
    }

    private function availableFrontendLanguages(): array
    {
        $available = array_values(array_filter(
            (array) ($this->languages['available'] ?? array_keys((array) ($this->languages['labels'] ?? []))),
            static fn (mixed $value): bool => is_string($value) && preg_match('/^[a-z]{2}$/', $value)
        ));

        foreach ((array) ($this->languages['enabled'] ?? []) as $language) {
            if (is_string($language) && preg_match('/^[a-z]{2}$/', $language) && !in_array($language, $available, true)) {
                $available[] = $language;
            }
        }

        return $available !== [] ? array_values(array_unique($available)) : ['ru'];
    }

    private function availableAdminLanguages(): array
    {
        $available = $this->i18n->availableLanguages();

        return in_array('ru', $available, true) ? $available : array_merge(['ru'], $available);
    }

    private function plainLanguageOptions(array $languages, string $selected): string
    {
        $html = '';

        foreach ($languages as $language) {
            $html .= '<option value="' . mur_escape($language) . '"' . ($language === $selected ? ' selected' : '') . '>' . mur_escape(strtoupper($language)) . '</option>';
        }

        return $html;
    }

    private function savePluginsConfig(array $user): string
    {
        $guard = $this->guardPost($user);
        if ($guard !== '') {
            return $guard;
        }

        $available = $this->availablePlugins();
        $selected = array_values(array_filter((array) ($_POST['plugins'] ?? []), static fn (mixed $plugin): bool => is_string($plugin)));
        $enabled = array_values(array_intersect($available, $selected));
        $this->writeConfigFile('config/plugins.php', ['enabled' => $enabled]);

        return $this->notice('Настройки плагинов сохранены. Изменения полностью применятся после следующего запроса.');
    }

    private function pluginRoute(string $route, string $method, array $nav, array $user): string
    {
        $context = [
            'route' => $route,
            'method' => $method,
            'user' => $user,
            'render_file_collection' => fn (string $scope): string => $this->fileCollectionPage($scope, $user),
        ];
        $definition = $this->pluginRouteDefinition($route);
        $page = $definition !== null
            ? ($definition['handler'])(null, $context, $this->hooks)
            : $this->hooks->filter('admin.route', null, $context);

        if (!is_string($page) || trim($page) === '') {
            return $this->renderer->errorPage(404, 'Страница плагина не найдена', 'Плагин не обработал этот admin route.', $nav, $user);
        }

        $active = str_replace('/', '-', $route);
        $title = 'Плагин';
        foreach ($nav as $group) {
            foreach ((array) ($group['items'] ?? []) as $item) {
                if ((string) ($item['id'] ?? '') === $active) {
                    $title = (string) ($item['label'] ?? $title);
                    break 2;
                }
            }
        }

        return $this->renderer->page($title, $page, $nav, $user, $active);
    }

    private function pluginRouteDefinition(string $route): ?array
    {
        foreach ($this->hooks->definitions('admin.route') as $definition) {
            if (!is_array($definition) || !is_callable($definition['handler'] ?? null)) {
                continue;
            }

            $path = $this->normalizePluginRoutePath((string) ($definition['path'] ?? ''));
            if ($path === '' || $path !== $route) {
                continue;
            }

            $role = trim((string) ($definition['role'] ?? 'editor'));

            return [
                'path' => $path,
                'role' => $role !== '' ? $role : 'editor',
                'handler' => $definition['handler'],
            ];
        }

        return null;
    }

    private function normalizePluginRoutePath(string $path): string
    {
        $path = parse_url(trim($path), PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            return '';
        }

        $path = '/' . trim($path, '/');
        if (str_starts_with($path, '/admin/')) {
            $path = substr($path, strlen('/admin/'));
        } else {
            $path = ltrim($path, '/');
        }

        return str_starts_with($path, 'plugin/') ? $path : '';
    }

    private function fileCollections(): array
    {
        $collections = [
            'pages' => $this->coreFileCollection('pages', 'Страницы', 'content-pages', '/admin/content/pages', 'editor'),
            'categories' => $this->coreFileCollection('categories', 'Категории', 'content-categories', '/admin/content/categories', 'editor'),
            'home' => $this->coreFileCollection('home', 'Блоки главной', 'template-home', '/admin/template/home', 'admin', true),
            'footer' => $this->coreFileCollection('footer', 'Блоки футера', 'template-footer', '/admin/template/footer', 'admin', true),
            'snippets' => [
                'id' => 'snippets', 'label' => 'Ушки / snippets', 'menu_id' => 'content-snippets',
                'route' => '/admin/content/snippets', 'role' => 'admin', 'languages' => true,
                'allow_global' => true, 'recursive' => false,
                'targets' => ['default' => ['label' => 'Snippets', 'directory' => 'snippets/{lang}', 'global_directory' => 'snippets']],
            ],
        ];
        $pluginCollections = $this->hooks->filter('admin.file_collections', [], []);
        if (is_array($pluginCollections)) {
            foreach ($pluginCollections as $id => $collection) {
                if (is_string($id) && preg_match('/^[a-z0-9][a-z0-9_-]*$/', $id) && is_array($collection) && !isset($collections[$id])) {
                    $collections[$id] = $collection + ['id' => $id];
                }
            }
        }

        return $collections;
    }

    private function coreFileCollection(string $id, string $label, string $menuId, string $route, string $role, bool $recursive = false): array
    {
        return [
            'id' => $id,
            'label' => $label,
            'menu_id' => $menuId,
            'route' => $route,
            'role' => $role,
            'languages' => true,
            'allow_global' => false,
            'recursive' => $recursive,
            'targets' => [
                'default' => [
                    'label' => $label,
                    'directory' => 'content/{lang}/' . $id,
                ],
            ],
        ];
    }

    private function fileCollection(string $scope): ?array
    {
        $collection = $this->fileCollections()[$scope] ?? null;
        if (!is_array($collection) || !is_array($collection['targets'] ?? null) || ($collection['targets'] ?? []) === []) {
            return null;
        }

        return $collection + ['id' => $scope, 'role' => 'editor', 'route' => '/admin/', 'menu_id' => '', 'languages' => true, 'allow_global' => false, 'recursive' => false];
    }

    private function collectionDirectory(array $collection, string $target, string $language): string
    {
        $targets = (array) ($collection['targets'] ?? []);
        if (!isset($targets[$target]) || !is_array($targets[$target])) {
            throw new \RuntimeException('Неизвестный тип файловой коллекции.');
        }

        $definition = $targets[$target];
        if ($language === 'all') {
            if (empty($collection['allow_global']) || !isset($definition['global_directory'])) {
                throw new \RuntimeException('Global scope недоступен для этой коллекции.');
            }
            $directory = (string) $definition['global_directory'];
        } else {
            if (empty($collection['languages']) || !in_array($language, $this->orderedLanguages(), true)) {
                throw new \RuntimeException('Недопустимый язык назначения.');
            }
            $directory = str_replace('{lang}', $language, (string) ($definition['directory'] ?? ''));
        }

        $directory = str_replace('\\', '/', trim($directory, '/'));
        if ($directory === '' || str_contains($directory, '..') || preg_match('~^[A-Za-z0-9_-]+(?:/[A-Za-z0-9_-]+)*$~', $directory) !== 1) {
            throw new \RuntimeException('Plugin зарегистрировал небезопасную target directory.');
        }

        return $directory;
    }

    private function collectionFileAllowed(array $collection, string $relative, bool $mustExist = true): bool
    {
        $relative = str_replace('\\', '/', trim($relative, '/'));
        if ($relative === '' || str_contains($relative, '..') || !str_ends_with($relative, '.mur.md')) {
            return false;
        }

        $languages = $this->orderedLanguages();
        if (!empty($collection['allow_global'])) {
            $languages[] = 'all';
        }
        foreach (array_keys((array) ($collection['targets'] ?? [])) as $target) {
            foreach ($languages as $language) {
                try {
                    $directory = $this->collectionDirectory($collection, (string) $target, (string) $language);
                } catch (\Throwable) {
                    continue;
                }
                $prefix = $directory . '/';
                if (!str_starts_with($relative, $prefix)) {
                    continue;
                }
                $tail = substr($relative, strlen($prefix));
                $pattern = !empty($collection['recursive'])
                    ? '~^(?:[A-Za-z0-9_-]+/)*[A-Za-z0-9][A-Za-z0-9._-]*\.mur\.md$~'
                    : '~^[A-Za-z0-9][A-Za-z0-9._-]*\.mur\.md$~';
                if (preg_match($pattern, $tail) !== 1) {
                    return false;
                }
                $file = mur_path($relative);
                return !$mustExist || (is_file($file) && $this->isSafePath($file, mur_data_path()));
            }
        }

        return false;
    }

    private function managedFilePage(string $operation, string $method, array $nav, array $user): string
    {
        $scope = (string) ($_GET['scope'] ?? $_POST['scope'] ?? '');
        $collection = $this->fileCollection($scope);
        if ($collection === null) {
            return $this->renderer->errorPage(404, 'Файл не найден', 'Файловая коллекция недоступна.', $nav, $user);
        }
        if (!$this->auth->can($user, (string) $collection['role'])) {
            return $this->renderer->errorPage(403, 'Доступ запрещен', 'Для этого раздела нужны более высокие права доступа.', $nav, $user);
        }

        $title = $operation === 'delete' ? 'Удалить файл' : 'Файловый редактор';
        $content = $operation === 'delete'
            ? $this->managedFileDelete($collection, $method, $user)
            : $this->managedFileEditor($collection, $method, $user);

        return $this->renderer->page($title, $content, $nav, $user, (string) $collection['menu_id']);
    }

    private function managedFileEditor(array $collection, string $method, array $user): string
    {
        $relative = (string) ($_GET['file'] ?? $_POST['file'] ?? '');
        if (!$this->collectionFileAllowed($collection, $relative)) {
            return '<section class="admin-panel"><p>Файл не найден или недоступен.</p></section>';
        }

        $notice = '';
        if ($method === 'POST') {
            $guard = $this->guardPost($user, (string) $collection['role']);
            if ($guard !== '') {
                return $guard;
            }
            try {
                $file = mur_path($relative);
                $this->backupManagedFile($file);
                $body = str_replace(["\r\n", "\r"], "\n", (string) ($_POST['content'] ?? ''));
                if (in_array($collection['id'], ['home', 'footer'], true) && array_key_exists('extra_meta', $_POST)) {
                    $meta = $this->parser->parseHeader((string) $_POST['extra_meta']);
                    $title = trim((string) ($_POST['title'] ?? ''));
                    if ($title !== '') {
                        $meta['title'] = $title;
                    } else {
                        unset($meta['title']);
                    }
                    $this->writeDocument($file, $meta, $body);
                } else {
                    $this->writeTextFile($file, $body);
                }
                $notice = $this->notice('Файл сохранен.');
            } catch (\Throwable $exception) {
                $notice = $this->notice($exception->getMessage(), true);
            }
        }

        $file = mur_path($relative);
        $content = (string) file_get_contents($file);
        $contentBlock = in_array($collection['id'], ['home', 'footer'], true);
        $titleField = '';
        $metaField = '';
        if ($contentBlock) {
            $document = $this->parser->parse($file, (string) $collection['id'], $this->languageFromRelativePath($relative));
            $meta = $document->meta;
            $title = (string) ($meta['title'] ?? '');
            unset($meta['title']);
            $content = $document->body;
            $lines = [];
            foreach ($meta as $key => $value) {
                $lines[] = $key . ': ' . $value;
            }
            $titleField = '<label class="admin-field-full">Заголовок<input name="title" value="' . mur_escape($title) . '"></label>';
            $metaField = '<label class="admin-field-full">Дополнительные параметры<textarea name="extra_meta" rows="6" spellcheck="false">' . mur_escape(implode("\n", $lines)) . '</textarea><span class="admin-help">Front matter: ключ: значение. Параметры сохраняются перед ---; содержимое — после.</span></label>';
        }
        $hint = trim((string) ($collection['hint'] ?? ''));
        $editorTools = $this->hooks->render('admin.editor_tools', [
            'editor' => 'file', 'textarea' => 'content', 'scope' => (string) $collection['id'], 'file' => $relative,
        ]);

        return $notice . '<section class="admin-panel"><h2>Файловый редактор</h2><p><code>' . mur_escape($relative) . '</code></p>'
            . '<form class="admin-form" method="post" action="/admin/files/edit?scope=' . rawurlencode((string) $collection['id']) . '&amp;file=' . rawurlencode($relative) . '">'
            . '<input type="hidden" name="_csrf" value="' . mur_escape((string) $user['csrf']) . '"><input type="hidden" name="scope" value="' . mur_escape((string) $collection['id']) . '"><input type="hidden" name="file" value="' . mur_escape($relative) . '">'
            . $titleField . $editorTools . ($hint !== '' ? '<p class="admin-help">' . $hint . '</p>' : '')
            . '<textarea name="content" aria-label="Содержимое" rows="28" spellcheck="false">' . mur_escape($content) . '</textarea>' . $metaField . '<div class="admin-actions"><button class="admin-button" type="submit">Сохранить</button><a class="admin-button admin-button--ghost" href="' . mur_escape((string) $collection['route']) . '">Назад</a></div></form></section>';
    }

    private function managedFileDelete(array $collection, string $method, array $user): string
    {
        $relative = (string) ($_GET['file'] ?? $_POST['file'] ?? '');
        if (!$this->collectionFileAllowed($collection, $relative)) {
            return '<section class="admin-panel"><p>Файл не найден или недоступен.</p></section>';
        }
        if ($method === 'POST') {
            $guard = $this->guardPost($user, (string) $collection['role']);
            if ($guard !== '') {
                return $guard;
            }
            if ((string) ($_POST['confirm'] ?? '') !== basename($relative)) {
                return $this->notice('Для удаления нужно ввести имя файла.', true) . $this->managedFileDeleteForm($collection, $relative, $user);
            }
            try {
                $file = mur_path($relative);
                $this->backupManagedFile($file);
                $this->deleteFile($file, mur_data_path());
                return $this->notice('Файл удален.') . '<p><a class="admin-button" href="' . mur_escape((string) $collection['route']) . '">Вернуться</a></p>';
            } catch (\Throwable $exception) {
                return $this->notice($exception->getMessage(), true) . $this->managedFileDeleteForm($collection, $relative, $user);
            }
        }

        return $this->managedFileDeleteForm($collection, $relative, $user);
    }

    private function managedFileDeleteForm(array $collection, string $relative, array $user): string
    {
        return '<section class="admin-panel"><h2>Удалить файл</h2><p>Будет удален <code>' . mur_escape($relative) . '</code>. Для подтверждения введите имя файла: <code>' . mur_escape(basename($relative)) . '</code>.</p>'
            . '<form class="admin-form" method="post" action="/admin/files/delete?scope=' . rawurlencode((string) $collection['id']) . '&amp;file=' . rawurlencode($relative) . '"><input type="hidden" name="_csrf" value="' . mur_escape((string) $user['csrf']) . '"><input type="hidden" name="scope" value="' . mur_escape((string) $collection['id']) . '"><input type="hidden" name="file" value="' . mur_escape($relative) . '"><label>Подтверждение<input name="confirm" required></label><div class="admin-actions"><button class="admin-button admin-button--danger" type="submit">Удалить</button><a class="admin-button admin-button--ghost" href="' . mur_escape((string) $collection['route']) . '">Отмена</a></div></form></section>';
    }

    private function fileUploadAction(string $method, array $nav, array $user): string
    {
        return $this->fileMutationAction('upload', $method, $nav, $user);
    }

    private function fileReplaceAction(string $method, array $nav, array $user): string
    {
        return $this->fileMutationAction('replace', $method, $nav, $user);
    }

    private function fileMutationAction(string $operation, string $method, array $nav, array $user): string
    {
        $scope = (string) ($_POST['scope'] ?? '');
        $collection = $this->fileCollection($scope);
        $message = '';
        $error = false;
        if ($method !== 'POST' || $collection === null) {
            $message = 'Файловая операция недоступна.';
            $error = true;
        } else {
            $guard = $this->guardPost($user, (string) $collection['role']);
            if ($guard !== '') {
                $message = 'Недостаточно прав или CSRF token недействителен.';
                $error = true;
            } else {
                try {
                    $upload = is_array($_FILES['mur_file'] ?? null) ? $_FILES['mur_file'] : [];
                    if ($operation === 'replace') {
                        $relative = (string) ($_POST['file'] ?? '');
                        if (!$this->collectionFileAllowed($collection, $relative)) {
                            throw new \RuntimeException('Недопустимый target file.');
                        }
                        $result = $this->files->replace($upload, $relative);
                        $message = 'Файл успешно заменён: ' . $result['filename'];
                    } else {
                        $target = (string) ($_POST['target'] ?? array_key_first((array) $collection['targets']));
                        $language = (string) ($_POST['language'] ?? ($this->languages['default'] ?? ''));
                        $directory = $this->collectionDirectory($collection, $target, $language);
                        $result = $this->files->upload($upload, $directory);
                        $message = 'Файл успешно загружен: ' . $result['filename'];
                    }
                } catch (\Throwable $exception) {
                    $message = $exception->getMessage();
                    $error = true;
                }
            }
        }

        $ajax = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest'
            || str_contains(strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? '')), 'application/json');
        if ($ajax) {
            header('Content-Type: application/json; charset=utf-8');
            if ($error) {
                http_response_code(422);
            }
            return (string) json_encode(['ok' => !$error, 'message' => $message], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $route = is_array($collection) ? (string) $collection['route'] : '/admin/';
        $active = is_array($collection) ? (string) $collection['menu_id'] : '';
        return $this->renderer->page($operation === 'replace' ? 'Замена файла' : 'Загрузка файла', $this->notice($message, $error) . '<p><a class="admin-button" href="' . mur_escape($route) . '">Вернуться</a></p>', $nav, $user, $active);
    }

    private function fileCollectionPage(string $scope, array $user): string
    {
        $collection = $this->fileCollection($scope);
        if ($collection === null || !$this->auth->can($user, (string) $collection['role'])) {
            return '<section class="admin-panel"><p>Файловая коллекция недоступна.</p></section>';
        }

        $rows = '';
        foreach ($this->collectionFiles($collection) as $entry) {
            $relative = $entry['relative'];
            $rows .= '<tr><td>' . mur_escape($entry['scope']) . '</td><td>' . mur_escape($entry['target']) . '</td><td><code>' . mur_escape($relative) . '</code></td><td>' . $this->toolbar([
                ['label' => 'Редактировать', 'url' => '/admin/files/edit?scope=' . rawurlencode($scope) . '&file=' . rawurlencode($relative)],
                ['label' => 'Скачать', 'url' => '/admin/files/download?scope=' . rawurlencode($scope) . '&file=' . rawurlencode($relative)],
                ['label' => 'Загрузить взамен', 'url' => '#', 'attributes' => 'data-file-replace-open data-file-scope="' . mur_escape($scope) . '" data-file-path="' . mur_escape($relative) . '"'],
                ['label' => 'Удалить', 'url' => '/admin/files/delete?scope=' . rawurlencode($scope) . '&file=' . rawurlencode($relative), 'danger' => true],
            ]) . '</td></tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="4">Файлы пока не найдены.</td></tr>';
        }

        return '<section class="admin-panel"><h2>' . mur_escape((string) $collection['label']) . '</h2><table class="admin-table"><thead><tr><th>Язык / scope</th><th>Тип</th><th>Файл</th><th>Действия</th></tr></thead><tbody>' . $rows . '</tbody></table></section>'
            . $this->fileUploadPanel($scope, $user)
            . $this->fileReplaceDialog($user);
    }

    private function collectionFiles(array $collection): array
    {
        $entries = [];
        $languages = $this->orderedLanguages();
        if (!empty($collection['allow_global'])) {
            $languages[] = 'all';
        }
        foreach ((array) $collection['targets'] as $target => $definition) {
            foreach ($languages as $language) {
                try {
                    $directory = mur_data_path() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $this->collectionDirectory($collection, (string) $target, (string) $language));
                } catch (\Throwable) {
                    continue;
                }
                if (!is_dir($directory)) {
                    continue;
                }
                $iterator = !empty($collection['recursive'])
                    ? new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS))
                    : new \IteratorIterator(new \DirectoryIterator($directory));
                foreach ($iterator as $file) {
                    if (!$file instanceof \SplFileInfo || !$file->isFile() || !str_ends_with($file->getFilename(), '.mur.md')) {
                        continue;
                    }
                    $relative = $this->relativePath($file->getPathname());
                    if ($this->collectionFileAllowed($collection, $relative)) {
                        $entries[$relative] = ['relative' => $relative, 'scope' => $this->languageHeading((string) $language), 'target' => (string) ($definition['label'] ?? $target)];
                    }
                }
            }
        }
        ksort($entries, SORT_NATURAL);

        return array_values($entries);
    }

    private function fileUploadPanel(string $scope, array $user): string
    {
        $collection = $this->fileCollection($scope);
        if ($collection === null || !$this->auth->can($user, (string) $collection['role'])) {
            return '';
        }
        $languages = [];
        foreach ($this->orderedLanguages() as $language) {
            $languages[$language] = $this->languageHeading($language);
        }
        if (!empty($collection['allow_global'])) {
            $languages['all'] = $this->languageHeading('all');
        }
        $selectedLanguage = (string) ($this->languages['default'] ?? array_key_first($languages));
        $requestedLanguage = (string) ($_GET['lang'] ?? '');
        if ($requestedLanguage === '' && isset($_GET['filter'])) {
            $filterParts = explode(':', (string) $_GET['filter']);
            $requestedLanguage = (string) ($filterParts[1] ?? '');
        }
        if (isset($languages[$requestedLanguage])) {
            $selectedLanguage = $requestedLanguage;
        }
        $targets = [];
        foreach ((array) $collection['targets'] as $id => $definition) {
            $targets[(string) $id] = (string) ($definition['label'] ?? $id);
        }
        $targetField = count($targets) > 1
            ? '<label>' . mur_escape($this->t('Тип файла')) . '<select name="target">' . $this->selectOptions($targets, (string) array_key_first($targets)) . '</select></label>'
            : '<input type="hidden" name="target" value="' . mur_escape((string) array_key_first($targets)) . '">';

        $languageRadios = '<fieldset class="admin-radio-group"><legend>' . mur_escape($this->t('Целевой язык / scope')) . '</legend>';
        foreach ($languages as $language => $label) {
            $languageRadios .= '<label class="admin-check"><input type="radio" name="language" value="' . mur_escape($language) . '"' . ($language === $selectedLanguage ? ' checked' : '') . ' required><span>' . ($language === 'all' ? 'Global' : mur_escape($label)) . '</span></label>';
        }
        $languageRadios .= '</fieldset>';

        return '<section class="admin-panel admin-file-upload"><h2>' . mur_escape($this->t('Загрузка файла')) . '</h2><p class="admin-help">' . mur_escape($this->t('Загружайте только заранее подготовленные файлы MurCMS .mur.md. Содержимое документа не проверяется на ошибки, конфликты ID, URL, slug, translation key и другие логические проблемы.')) . ' ' . mur_escape($this->t('Максимальный размер файла: 2 MB.')) . '</p><form class="admin-form" method="post" action="/admin/files/upload" enctype="multipart/form-data" data-mur-file-upload><input type="hidden" name="_csrf" value="' . mur_escape((string) $user['csrf']) . '"><input type="hidden" name="scope" value="' . mur_escape($scope) . '"><div class="admin-form-grid">' . $languageRadios . $targetField . '</div><label class="admin-file-drop" data-file-drop>' . mur_escape($this->t('Перетащите один файл .mur.md сюда или выберите его')) . '<input type="file" name="mur_file" accept=".mur.md" required></label><button class="admin-button" type="submit">' . mur_escape($this->t('Загрузить')) . '</button><div class="admin-file-result" data-file-result role="status" aria-live="polite"></div></form></section>';
    }

    private function fileReplaceDialog(array $user): string
    {
        return '<dialog class="admin-dialog" id="admin-file-replace-dialog"><form class="admin-form" method="post" action="/admin/files/replace" enctype="multipart/form-data" data-mur-file-replace><input type="hidden" name="_csrf" value="' . mur_escape((string) $user['csrf']) . '"><input type="hidden" name="scope" value=""><input type="hidden" name="file" value=""><h2>' . mur_escape($this->t('Загрузить взамен')) . '</h2><p>' . mur_escape($this->t('Файл полностью заменит текущий файл объекта. Загружайте только заранее подготовленные файлы MurCMS .mur.md. Система не проверяет корректность ID, slug, метаданных, связей и разметки документа. Перед заменой текущий файл будет сохранён в резервную копию.')) . '</p><p>' . mur_escape($this->t('Целевой файл')) . ': <code data-file-replace-target></code></p><label class="admin-file-drop" data-file-drop>' . mur_escape($this->t('Перетащите один файл .mur.md сюда или выберите его')) . '<input type="file" name="mur_file" accept=".mur.md" required></label><div class="admin-file-result" data-file-result role="status" aria-live="polite"></div><div class="admin-actions"><button class="admin-button" type="submit">' . mur_escape($this->t('Заменить файл')) . '</button><button class="admin-button admin-button--ghost" type="button" data-dialog-close>' . mur_escape($this->t('Отмена')) . '</button></div></form></dialog>';
    }

    private function postedDocumentMeta(string $collection, string $id, string $language): array
    {
        $keys = [
            'id', 'title', 'slug', 'status', 'parent', 'template', 'translation_key', 'order',
            'seo.title', 'seo.description', 'summary', 'page.header',
            'list.source', 'list.view', 'list.sort', 'list.direction', 'list.per_page', 'list.position',
        ];
        $reservedKeys = array_flip([...$keys, 'categories', 'updated_at']);
        $meta = [];

        foreach ($keys as $key) {
            $value = $this->postedMetaValue($key);
            if ($value !== '') {
                $meta[$key] = $value;
            }
        }

        $meta['id'] = $id;
        $meta['status'] = $meta['status'] ?? 'draft';
        $meta['title'] = $meta['title'] ?? $id;
        $meta['slug'] = $this->safeSlug($meta['slug'] ?? $id);

        if ($collection === 'pages') {
            $availableCategories = array_flip($this->availableCategoryIds($language));
            $selectedCategories = array_values(array_unique(array_filter(
                array_map(fn (mixed $value): string => $this->safeName((string) $value), (array) ($_POST['categories'] ?? [])),
                static fn (string $categoryId): bool => $categoryId !== '' && isset($availableCategories[$categoryId]),
            )));

            if ($selectedCategories !== []) {
                $meta['categories'] = implode(', ', $selectedCategories);
            }
        }

        if ($collection === 'categories' && ($meta['template'] ?? '') === '') {
            $meta['template'] = 'category';
        }

        foreach ($this->parser->parseHeader((string) ($_POST['extra_meta'] ?? '')) as $key => $value) {
            if (!isset($reservedKeys[$key]) && !array_key_exists($key, $meta)) {
                $meta[$key] = $value;
            }
        }

        $meta = $this->hooks->filter('admin.document_meta', $meta, [
            'collection' => $collection,
            'id' => $id,
            'language' => $language,
            'post' => $_POST,
        ]);

        return is_array($meta) ? $meta : [];
    }

    private function postedMetaValue(string $key): string
    {
        $normalizedKey = str_replace('.', '_', $key);

        return trim((string) ($_POST[$key] ?? $_POST[$normalizedKey] ?? ''));
    }

    private function writeDocument(string $file, array $meta, string $body): void
    {
        $this->writeTextFile($file, $this->parser->serialize($meta, $body));
    }

    private function documentCollisionError(
        string $collection,
        string $language,
        array $meta,
        ?Document $original,
        string $targetFile,
    ): string {
        $originalFile = $original?->filePath ?? '';

        if (is_file($targetFile) && !$this->sameFilePath($targetFile, $originalFile)) {
            return 'Файл с таким ID уже существует в выбранной языковой ветке.';
        }

        $id = (string) ($meta['id'] ?? '');
        $parentId = trim((string) ($meta['parent'] ?? ''));
        $translationKey = trim((string) ($meta['translation_key'] ?? ''));
        $documents = $this->content->all($language);
        $byId = [];

        foreach ($documents as $document) {
            if ($document instanceof Document) {
                $byId[$document->id()] = $document;
            }
        }

        foreach ($documents as $document) {
            if (!$document instanceof Document || $this->sameFilePath($document->filePath, $originalFile)) {
                continue;
            }

            if ($document->id() === $id) {
                return 'Документ с таким ID уже существует в этой языковой ветке.';
            }

            if ($translationKey !== '' && $document->translationKey() === $translationKey) {
                return 'Ключ перевода уже используется другим документом в этой языковой ветке.';
            }
        }

        if ($parentId !== '') {
            $parent = $byId[$parentId] ?? null;

            if (!$parent instanceof Document || $parent->collection !== $collection) {
                return 'Выбранный родитель не найден в этой коллекции и языковой ветке.';
            }

            if ($parentId === $id || $this->parentWouldCreateCycle($parentId, $id, $byId)) {
                return 'Выбранный родитель создает циклическую структуру документов.';
            }
        }

        $candidatePath = $this->documentUrlPath($collection, $meta, $byId);

        foreach ($documents as $document) {
            if (
                $document instanceof Document
                && !$this->sameFilePath($document->filePath, $originalFile)
                && $document->urlPath === $candidatePath
            ) {
                return 'Такой URL уже используется документом «' . $document->title() . '».';
            }
        }

        return '';
    }

    private function documentUrlPath(string $collection, array $meta, array $byId): string
    {
        $parts = [(string) ($meta['slug'] ?? $meta['id'] ?? '')];
        $parentId = trim((string) ($meta['parent'] ?? ''));
        $visited = [];

        while ($parentId !== '' && !isset($visited[$parentId])) {
            $visited[$parentId] = true;
            $parent = $byId[$parentId] ?? null;

            if (!$parent instanceof Document) {
                break;
            }

            array_unshift($parts, $parent->slug());
            $parentId = $parent->parentId();
        }

        $prefix = match ($collection) {
            'pages' => '',
            'services' => 'services',
            'info' => 'info',
            'news' => 'news',
            'categories' => 'category',
            default => $collection,
        };

        if ($prefix !== '') {
            array_unshift($parts, $prefix);
        }

        return implode('/', array_filter($parts));
    }

    private function documentDeleteDependencyError(Document $document): string
    {
        foreach ($this->content->all($document->language) as $candidate) {
            if (!$candidate instanceof Document || $candidate->filePath === $document->filePath) {
                continue;
            }

            if ($candidate->parentId() === $document->id()) {
                return 'Сначала переназначьте родителя у документа «' . $candidate->title() . '».';
            }

            if (
                $document->collection === 'categories'
                && in_array($document->id(), $candidate->categoryIds(), true)
            ) {
                return 'Сначала снимите эту рубрику со страницы «' . $candidate->title() . '».';
            }
        }

        return '';
    }

    private function defaultDocumentMeta(string $collection): array
    {
        return [
            'id' => '',
            'title' => '',
            'slug' => '',
            'status' => 'draft',
            'template' => $collection === 'categories' ? 'category' : 'page',
        ];
    }

    private function extraMetaText(array $meta): string
    {
        $knownKeys = [
            'id', 'title', 'slug', 'status', 'parent', 'template', 'translation_key', 'order',
            'seo.title', 'seo.description', 'summary', 'page.header', 'categories', 'updated_at',
            'list.source', 'list.view', 'list.sort', 'list.direction', 'list.per_page', 'list.position',
        ];
        $knownKeys = $this->hooks->filter('admin.known_meta_keys', $knownKeys);
        $known = array_flip(array_map('strval', is_array($knownKeys) ? $knownKeys : []));
        $lines = [];
        foreach ($meta as $key => $value) {
            if (!isset($known[$key]) && is_scalar($value)) {
                $lines[] = $key . ': ' . (string) $value;
            }
        }
        return implode("\n", $lines);
    }

    private function suggestCopyId(string $language, string $collection, string $id): string
    {
        for ($i = 1; $i < 100; $i++) {
            $candidate = $id . '-copy' . ($i > 1 ? '-' . $i : '');
            if (!is_file(mur_path('content/' . $language . '/' . $collection . '/' . $candidate . '.mur.md'))) {
                return $candidate;
            }
        }
        return $id . '-copy-' . time();
    }

    private function writeConfigFile(string $relative, array $config): void
    {
        $export = var_export($config, true);
        $file = mur_path($relative);
        $this->writeTextFile(
            $file,
            "<?php\ndeclare(strict_types=1);\n\nif (!defined('MUR_CMS_BOOTSTRAPPED')) {\n    http_response_code(403);\n    exit('No direct script access allowed.');\n}\n\nreturn " . $export . ";\n"
        );

        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($file, true);
        }
    }

    private function persistAdminUsers(): void
    {
        $this->adminConfig['users'] = $this->users->users();
        $this->persistAdminConfig();
    }

    private function persistAdminConfig(): void
    {
        $this->backupConfigFile('config/admin.php');
        $this->writeConfigFile('config/admin.php', $this->adminConfig);
        $this->users = new AdminUserManager((array) ($this->adminConfig['users'] ?? []));
    }

    private function backupConfigFile(string $relative): void
    {
        $file = mur_path($relative);
        $configPath = realpath(mur_path('config'));
        if ($configPath === false || !$this->isSafePath($file, $configPath) || !is_file($file)) {
            throw new \RuntimeException('Unsafe config backup source: ' . $relative);
        }

        $backupBase = mur_path('storage/backups/' . str_replace('\\', '/', $relative));
        $latest = $backupBase . '.bak.1';
        $previous = $backupBase . '.bak.2';
        if (is_file($latest)) {
            $latestContent = file_get_contents($latest);
            if ($latestContent === false) {
                throw new \RuntimeException('Unable to read config backup: ' . $latest);
            }
            $this->writeTextFile($previous, $latestContent);
        }

        $content = file_get_contents($file);
        if ($content === false) {
            throw new \RuntimeException('Unable to read config backup source: ' . $file);
        }
        $this->writeTextFile($latest, $content);
    }

    private function keyValueTable(array $data): string
    {
        $rows = '';

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            $rows .= '<tr><th><code>' . mur_escape((string) $key) . '</code></th><td>' . mur_escape((string) $value) . '</td></tr>';
        }

        return '<table class="admin-table admin-table--kv"><tbody>' . $rows . '</tbody></table>';
    }

    private function toolbar(array $items): string
    {
        $html = '<div class="admin-row-actions">';
        foreach ($items as $item) {
            $class = 'admin-link-button' . (!empty($item['danger']) ? ' admin-link-button--danger' : '');
            $label = (string) ($item['label'] ?? '');
            $translatedLabel = $this->t($label);
            $icon = (string) ($item['icon'] ?? $this->actionIcon($label));
            $attributes = trim((string) ($item['attributes'] ?? ''));
            $html .= '<a class="' . $class . '" href="' . mur_escape((string) $item['url']) . '" aria-label="' . mur_escape($translatedLabel) . '" title="' . mur_escape($translatedLabel) . '"' . ($attributes !== '' ? ' ' . $attributes : '') . '>'
                . $this->icon($icon)
                . '<span class="visually-hidden">' . mur_escape($translatedLabel) . '</span></a>';
        }
        return $html . '</div>';
    }

    private function actionIcon(string $label): string
    {
        return match ($label) {
            'Редактировать', 'Изменить' => 'edit',
            'Создать', 'Создать переопределение' => 'add',
            'Клонировать', 'Создать копию', 'Копировать' => 'copy',
            'Скачать' => 'download',
            'Загрузить взамен' => 'replace',
            'Удалить' => 'delete',
            'Открыть', 'Открыть папку', 'Просмотр', 'Открыть на сайте' => 'view',
            'Назад', 'Вернуться' => 'back',
            default => 'view',
        };
    }

    private function icon(string $name): string
    {
        return '<svg class="admin-icon" aria-hidden="true" focusable="false"><use href="#admin-icon-' . mur_escape($name) . '"></use></svg>';
    }

    private function statusIcon(string $status, ?string $label = null): string
    {
        $published = strtolower(trim($status)) === 'published';
        $label ??= $published ? 'Опубликован' : 'Черновик';
        $class = $published ? 'published' : 'draft';

        return '<span class="admin-status admin-status--' . $class . '" aria-label="' . mur_escape($label) . '" title="' . mur_escape($label) . '">'
            . $this->icon($class)
            . '<span class="visually-hidden">' . mur_escape($label) . '</span></span>';
    }

    private function editorIntro(bool $isPage, bool $help): string
    {
        if (!$help) {
            return '';
        }

        $text = $isPage
            ? 'Здесь редактируются адрес, структура, рубрики, SEO-параметры и основное содержимое страницы. Поле «Родитель» строит URL, а блок «Рубрики» связывает страницу с одной или несколькими категориями.'
            : 'Категории образуют отдельное дерево рубрик. Поле «Родитель» задает вложенность категории, а страницы назначаются в рубрики в редакторе страниц.';

        return '<p class="admin-editor-intro">' . mur_escape($text) . '</p>';
    }

    private function documentField(string $label, string $control, string $help, string $id, bool $showHelp): string
    {
        return '<div class="admin-document-field">
  <label for="' . mur_escape($id) . '">' . mur_escape($label) . '</label>
  <div class="admin-document-control">' . $control . $this->helpText($help, $showHelp) . '</div>
</div>';
    }

    private function compactDocumentField(string $label, string $control, string $help, bool $showHelp): string
    {
        return '<label class="admin-compact-field"><span>' . mur_escape($label) . '</span>' . $control . $this->helpText($help, $showHelp) . '</label>';
    }

    private function helpText(string $text, bool $showHelp): string
    {
        return $showHelp ? '<small class="admin-help">' . $text . '</small>' : '';
    }

    private function helpEnabled(): bool
    {
        return (bool) ($this->adminConfig['ui']['help_enabled'] ?? true);
    }

    private function categoryChecklist(string $language, array $selected, bool $showHelp): string
    {
        $documents = $this->collectionDocuments($language, 'categories');

        if ($documents === []) {
            $content = '<p class="admin-empty">Для языка ' . mur_escape(strtoupper($language)) . ' категории пока не созданы.</p>';
        } else {
            $selectedLookup = array_flip($selected);
            $items = '';

            foreach ($this->hierarchyRows($documents) as [$document, $depth]) {
                $categoryId = $document->id();
                $treeDepth = max(0, min(8, (int) $depth));
                $items .= '<label class="admin-category-option admin-tree-depth-' . $treeDepth . '">
  <input type="checkbox" name="categories[]" value="' . mur_escape($categoryId) . '"' . (isset($selectedLookup[$categoryId]) ? ' checked' : '') . '>
  <span>' . mur_escape($document->title()) . ' <code>' . mur_escape($categoryId) . '</code></span>
</label>';
            }

            $content = '<div class="admin-category-tree" role="group" aria-label="Рубрики страницы">' . $items . '</div>';
        }

        return '<fieldset class="admin-editor-section">
  <legend>Рубрики</legend>
  ' . $this->helpText('Отметьте все категории, в которых должна находиться страница. Можно выбрать несколько рубрик, включая вложенные.', $showHelp) . '
  ' . $content . '
</fieldset>';
    }

    private function parentOptions(string $language, string $collection, string $selected, string $currentId): string
    {
        $documents = $this->collectionDocuments($language, $collection);
        $byId = [];

        foreach ($documents as $document) {
            $byId[$document->id()] = $document;
        }

        $html = '<option value="">Без родителя</option>';

        foreach ($this->hierarchyRows($documents) as [$document, $depth]) {
            $candidateId = $document->id();

            if ($candidateId === $currentId || $this->parentWouldCreateCycle($candidateId, $currentId, $byId)) {
                continue;
            }

            $prefix = str_repeat('— ', $depth);
            $html .= '<option value="' . mur_escape($candidateId) . '"' . ($candidateId === $selected ? ' selected' : '') . '>'
                . mur_escape($prefix . $document->title() . ' [' . $candidateId . ']')
                . '</option>';
        }

        return $html;
    }

    private function parentWouldCreateCycle(string $candidateId, string $currentId, array $byId): bool
    {
        if ($currentId === '') {
            return false;
        }

        $parentId = $candidateId;
        $visited = [];

        while ($parentId !== '' && !isset($visited[$parentId])) {
            if ($parentId === $currentId) {
                return true;
            }

            $visited[$parentId] = true;
            $parent = $byId[$parentId] ?? null;
            $parentId = $parent instanceof Document ? $parent->parentId() : '';
        }

        return false;
    }

    private function hierarchyRows(array $documents): array
    {
        $byId = [];
        $children = [];

        foreach ($documents as $document) {
            if (!$document instanceof Document) {
                continue;
            }

            $byId[$document->id()] = $document;
        }

        foreach ($byId as $document) {
            $parentId = $document->parentId();
            $key = $parentId !== '' && isset($byId[$parentId]) && $parentId !== $document->id() ? $parentId : '';
            $children[$key][] = $document;
        }

        foreach ($children as &$items) {
            usort($items, static fn (Document $a, Document $b): int => [
                (int) ($a->meta['order'] ?? 1000),
                $a->title(),
            ] <=> [
                (int) ($b->meta['order'] ?? 1000),
                $b->title(),
            ]);
        }
        unset($items);

        $rows = [];
        $visited = [];
        $walk = function (string $parentId, int $depth) use (&$walk, &$rows, &$visited, $children): void {
            foreach ($children[$parentId] ?? [] as $document) {
                if (isset($visited[$document->id()])) {
                    continue;
                }

                $visited[$document->id()] = true;
                $rows[] = [$document, $depth];
                $walk($document->id(), $depth + 1);
            }
        };
        $walk('', 0);

        foreach ($byId as $document) {
            if (!isset($visited[$document->id()])) {
                $rows[] = [$document, 0];
            }
        }

        return $rows;
    }

    private function collectionDocuments(string $language, string $collection): array
    {
        $directory = mur_path('content/' . $language . '/' . $collection);

        if (!is_dir($directory)) {
            return [];
        }

        $documents = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile() || !str_ends_with($file->getFilename(), '.mur.md')) {
                continue;
            }

            $documents[] = $this->parser->parse($file->getPathname(), $collection, $language);
        }

        return $documents;
    }

    private function availableCategoryIds(string $language): array
    {
        return array_map(
            static fn (Document $document): string => $document->id(),
            $this->collectionDocuments($language, 'categories'),
        );
    }

    private function parseCategoryIds(string $value): array
    {
        if (trim($value) === '') {
            return [];
        }

        return array_values(array_unique(array_filter(
            array_map('trim', explode(',', $value)),
            static fn (string $categoryId): bool => $categoryId !== '',
        )));
    }

    private function checkbox(string $name, string $label, bool $checked): string
    {
        return '<label class="admin-check"><input type="checkbox" name="' . mur_escape($name) . '" value="1"' . ($checked ? ' checked' : '') . '> <span>' . mur_escape($label) . '</span></label>';
    }

    private function languageSelect(string $selected): string
    {
        return '<select name="lang">' . $this->languageOptions($selected) . '</select>';
    }

    private function languageOptions(string $selected): string
    {
        $html = '';
        foreach (($this->languages['enabled'] ?? []) as $language) {
            $language = (string) $language;
            $html .= '<option value="' . mur_escape($language) . '"' . ($language === $selected ? ' selected' : '') . '>' . mur_escape(strtoupper($language)) . '</option>';
        }
        return $html;
    }

    private function snippetScopeOptions(string $selected): string
    {
        $html = '<option value="all"' . ($selected === 'all' ? ' selected' : '') . '>global / all languages</option>';
        return $html . $this->languageOptions($selected);
    }

    private function statusOptions(string $selected): string
    {
        return '<option value="published"' . ($selected === 'published' ? ' selected' : '') . '>Опубликован</option><option value="draft"' . ($selected === 'draft' ? ' selected' : '') . '>Черновик</option>';
    }

    private function pageHeaderOptions(string $selected): string
    {
        return '<option value=""' . ($selected === '' ? ' selected' : '') . '>По умолчанию</option><option value="true"' . ($selected === 'true' ? ' selected' : '') . '>Показывать</option><option value="false"' . ($selected === 'false' ? ' selected' : '') . '>Скрыть</option>';
    }

    private function directionOptions(string $selected): string
    {
        return '<option value=""' . ($selected === '' ? ' selected' : '') . '>По умолчанию</option><option value="asc"' . ($selected === 'asc' ? ' selected' : '') . '>По возрастанию</option><option value="desc"' . ($selected === 'desc' ? ' selected' : '') . '>По убыванию</option>';
    }

    private function listPositionOptions(string $selected): string
    {
        return '<option value=""' . ($selected === '' ? ' selected' : '') . '>По умолчанию</option>'
            . $this->selectOptions([
                'top' => 'Вверху страницы',
                'bottom' => 'Внизу страницы',
                'shortcode' => 'В месте shortcode [list]',
            ], $selected);
    }

    private function templateOptions(string $selected, bool $isPage): string
    {
        $options = $isPage
            ? ['page' => 'Обычная страница', 'listing' => 'Страница со списком']
            : ['category' => 'Категория', 'listing' => 'Категория со списком'];

        return $this->selectOptions($options, $selected);
    }

    private function listSourceOptions(string $selected, bool $isPage): string
    {
        $defaultLabel = $isPage ? 'По умолчанию: дочерние страницы' : 'По умолчанию: страницы этой рубрики';
        $options = $isPage
            ? ['children' => 'Дочерние страницы']
            : ['category' => 'Страницы текущей рубрики', 'children' => 'Дочерние категории'];

        return '<option value=""' . ($selected === '' ? ' selected' : '') . '>' . $defaultLabel . '</option>'
            . $this->selectOptions($options, $selected);
    }

    private function listViewOptions(string $selected): string
    {
        return '<option value=""' . ($selected === '' ? ' selected' : '') . '>По умолчанию: карточки</option>'
            . $this->selectOptions([
                'cards' => 'Карточки',
                'titles' => 'Только заголовки',
                'feed' => 'Лента',
            ], $selected);
    }

    private function listSortOptions(string $selected): string
    {
        return '<option value=""' . ($selected === '' ? ' selected' : '') . '>По умолчанию: порядок</option>'
            . $this->selectOptions([
                'order' => 'Порядок',
                'date' => 'Дата',
                'updated_at' => 'Дата обновления',
                'title' => 'Заголовок',
                'id' => 'ID',
                'filename' => 'Имя файла',
            ], $selected);
    }

    private function selectOptions(array $options, string $selected): string
    {
        $html = '';

        foreach ($options as $value => $label) {
            $html .= '<option value="' . mur_escape((string) $value) . '"' . ((string) $value === $selected ? ' selected' : '') . '>'
                . mur_escape((string) $label)
                . '</option>';
        }

        return $html;
    }

    private function guardPost(array $user, string $role = 'admin'): string
    {
        if (!$this->auth->verifyCsrf((string) ($_POST['_csrf'] ?? ''))) {
            return $this->notice('CSRF token устарел. Обновите страницу и повторите действие.', true);
        }

        if (!$this->auth->can($user, $role)) {
            return $this->notice('Недостаточно прав для сохранения.', true);
        }

        return '';
    }

    private function notice(string $message, bool $error = false): string
    {
        return '<div class="admin-alert' . ($error ? ' admin-alert--error' : ' admin-alert--ok') . '" role="status">' . mur_escape($message) . '</div>';
    }

    private function t(string $key, array $replace = []): string
    {
        return $this->i18n->t($key, $replace);
    }

    private function writeTextFile(string $file, string $content): void
    {
        $base = realpath(mur_data_path());
        $directory = dirname($file);
        if ($base === false) {
            throw new \RuntimeException('Base path is not available.');
        }
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException('Unable to create directory: ' . $directory);
        }
        $realDirectory = realpath($directory);
        if ($realDirectory === false || !str_starts_with($realDirectory, $base)) {
            throw new \RuntimeException('Unsafe write path: ' . $file);
        }
        $tmp = $file . '.tmp';
        if (file_put_contents($tmp, $content, LOCK_EX) === false) {
            throw new \RuntimeException('Unable to write temporary file: ' . $tmp);
        }
        if (!rename($tmp, $file)) {
            @unlink($tmp);
            throw new \RuntimeException('Unable to replace file: ' . $file);
        }
    }

    private function deleteFile(string $file, string $basePath): void
    {
        if (!$this->isSafePath($file, $basePath) || !is_file($file)) {
            throw new \RuntimeException('Unsafe delete path: ' . $file);
        }
        unlink($file);
    }

    private function ensureDirectory(string $directory): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException('Не удалось создать папку: ' . $directory);
        }
    }

    private function uniqueArchivePath(string $directory, string $baseName): string
    {
        $baseName = preg_replace('/[^A-Za-z0-9_-]+/', '-', $baseName) ?? 'archive';
        for ($i = 1; $i < 1000; $i++) {
            $suffix = $i > 1 ? '-' . $i : '';
            $candidate = $directory . DIRECTORY_SEPARATOR . $baseName . $suffix . '.zip';
            if (!file_exists($candidate)) {
                return $candidate;
            }
        }

        return $directory . DIRECTORY_SEPARATOR . $baseName . '-' . bin2hex(random_bytes(4)) . '.zip';
    }

    private function createZipArchive(string $archive, array $files): void
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('Для ZIP-архивов требуется PHP extension zip.');
        }
        if ($files === []) {
            throw new \RuntimeException('Нет файлов для ZIP-архива.');
        }

        $this->ensureDirectory(dirname($archive));
        $zip = new \ZipArchive();
        if ($zip->open($archive, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Не удалось создать ZIP-архив.');
        }

        try {
            foreach ($files as $file) {
                $path = (string) ($file['path'] ?? '');
                $name = trim(str_replace('\\', '/', (string) ($file['name'] ?? '')), '/');
                if ($path === '' || !$this->isSafePath($path, mur_root_path()) || !is_file($path)) {
                    throw new \RuntimeException('Недопустимый файл для ZIP-архива.');
                }
                if ($name === '' || str_contains($name, '..') || preg_match('/[\x00-\x1F\x7F]/', $name)) {
                    throw new \RuntimeException('Недопустимое имя файла в ZIP-архиве.');
                }
                if (!$zip->addFile($path, $name)) {
                    throw new \RuntimeException('Не удалось добавить файл в ZIP-архив.');
                }
            }
        } catch (\Throwable $exception) {
            $zip->close();
            @unlink($archive);
            throw $exception;
        }

        if (!$zip->close() || !is_file($archive)) {
            @unlink($archive);
            throw new \RuntimeException('Не удалось завершить ZIP-архив.');
        }
    }

    private function backupManagedFile(string $file): void
    {
        if (!$this->isSafePath($file, mur_data_path()) || !is_file($file) || !str_ends_with(str_replace('\\', '/', $file), '.mur.md')) {
            throw new \RuntimeException('Unsafe backup source: ' . $file);
        }

        $relative = $this->relativePath($file);
        $backupBase = mur_path('storage/backups/' . $relative);
        $latest = $backupBase . '.bak.1';
        $previous = $backupBase . '.bak.2';

        if (is_file($latest)) {
            $latestContent = file_get_contents($latest);
            if ($latestContent === false) {
                throw new \RuntimeException('Unable to read backup: ' . $latest);
            }
            $this->writeTextFile($previous, $latestContent);
        }

        $content = file_get_contents($file);
        if ($content === false) {
            throw new \RuntimeException('Unable to read backup source: ' . $file);
        }
        $this->writeTextFile($latest, $content);
    }

    private function sameFilePath(string $left, string $right): bool
    {
        if ($left === '' || $right === '') {
            return false;
        }

        $normalize = static function (string $path): string {
            $resolved = realpath($path);
            $normalized = str_replace('\\', '/', $resolved !== false ? $resolved : $path);

            return DIRECTORY_SEPARATOR === '\\' ? strtolower($normalized) : $normalized;
        };

        return $normalize($left) === $normalize($right);
    }

    private function isSafePath(string $path, string $basePath): bool
    {
        $base = realpath($basePath);
        $target = realpath($path);
        return $base !== false && $target !== false && ($target === $base || str_starts_with($target, $base . DIRECTORY_SEPARATOR));
    }

    private function allowedRelativeFile(string $relative, string $scope): bool
    {
        $relative = str_replace('\\', '/', ltrim($relative, '/'));
        if ($relative === '' || str_contains($relative, '..')) {
            return false;
        }
        $allowed = match ($scope) {
            'snippets' => (bool) preg_match('~^snippets/(?:[a-z]{2}/)?[A-Za-z0-9_-]+\.mur\.md$~', $relative),
            'menus' => (bool) preg_match('~^menus/[a-z]{2}/[A-Za-z0-9_-]+\.mur\.md$~', $relative),
            default => false,
        };
        if (!$allowed) {
            return false;
        }

        $file = mur_path($relative);

        if (is_file($file)) {
            return $this->isSafePath($file, mur_path());
        }

        $directory = dirname($file);

        return is_dir($directory) && $this->isSafePath($directory, mur_path());
    }

    private function relativePath(string $file): string
    {
        $base = str_replace('\\', '/', mur_data_path());
        return ltrim(str_replace($base, '', str_replace('\\', '/', $file)), '/');
    }

    private function safeLanguage(string $language): string
    {
        return in_array($language, $this->languages['enabled'] ?? [], true) ? $language : (string) ($this->languages['default'] ?? 'ru');
    }

    private function safeName(string $value): string
    {
        return preg_match('/^[A-Za-z0-9_-]+$/', $value) ? $value : '';
    }

    private function safeSlug(string $value): string
    {
        $value = trim($value);
        return preg_match('/^[A-Za-z0-9_\\-\\/]+$/', $value) ? trim($value, '/') : '';
    }

    private function languageFromRelativePath(string $relative): string
    {
        $parts = explode('/', str_replace('\\', '/', trim($relative, '/')));

        foreach ($parts as $part) {
            if (in_array($part, $this->languages['enabled'] ?? [], true)) {
                return $part;
            }
        }

        return 'all';
    }

    private function orderedLanguages(): array
    {
        $enabled = array_values(array_map('strval', (array) ($this->languages['enabled'] ?? [])));
        $default = (string) ($this->languages['default'] ?? '');

        if ($default !== '' && in_array($default, $enabled, true)) {
            $enabled = array_values(array_diff($enabled, [$default]));
            array_unshift($enabled, $default);
        }

        return $enabled;
    }

    private function languageHeading(string $language): string
    {
        if ($language === 'all') {
            return 'Общие для всех языков';
        }

        $label = (string) (($this->languages['labels'] ?? [])[$language] ?? strtoupper($language));
        $default = (string) ($this->languages['default'] ?? '');

        return $label . ($language === $default ? ' — основной язык' : '');
    }

    private function themeOptions(string $template): array
    {
        $defaults = [
            'content_width' => 1040,
            'color_scheme' => 'light',
            'sticky_header' => true,
            'show_breadcrumbs' => true,
            'show_page_summary' => true,
            'load_menu_js' => true,
            'footer_blocks' => true,
            'footer_menu_enabled' => false,
            'footer_menu_name' => 'footer',
            'list_defaults' => [
                'children' => ['view' => 'cards', 'sort' => 'order', 'direction' => 'asc', 'per_page' => '', 'position' => 'bottom'],
                'category' => ['view' => 'cards', 'sort' => 'order', 'direction' => 'asc', 'per_page' => '', 'position' => 'bottom'],
            ],
        ];
        $options = $this->themeConfig['templates'][$template]['options'] ?? [];

        return array_replace_recursive($defaults, is_array($options) ? $options : []);
    }

    private function availableTemplates(): array
    {
        $templates = [];
        foreach (glob(mur_engine_path('templates/*/template.php'), GLOB_NOSORT) ?: [] as $file) {
            $name = basename(dirname($file));
            if (preg_match('/^[a-z0-9][a-z0-9_-]*$/', $name)) {
                $templates[] = $name;
            }
        }
        sort($templates, SORT_NATURAL);

        return $templates !== [] ? $templates : ['default'];
    }

    private function enabledPlugins(): array
    {
        $config = is_file(mur_path('config/plugins.php')) ? require mur_path('config/plugins.php') : [];
        return array_values(array_filter((array) ($config['enabled'] ?? []), static fn (mixed $value): bool => is_string($value)));
    }

    private function availablePlugins(): array
    {
        $plugins = [];
        foreach (glob(mur_engine_path('plugins/*/plugin.php'), GLOB_NOSORT) ?: [] as $file) {
            $plugins[] = basename(dirname($file));
        }
        sort($plugins, SORT_NATURAL);
        return $plugins;
    }

    private function redirect(string $url, int $status = 302): void
    {
        http_response_code(302);
        header('Location: ' . $url, true, $status);
    }

    private function securityHeaders(): void
    {
        header('Cache-Control: no-store, private');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: same-origin');
        header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'");
    }
}
