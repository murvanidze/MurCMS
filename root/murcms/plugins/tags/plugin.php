<?php
declare(strict_types=1);

if (!defined('MUR_CMS_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('No direct script access allowed.');
}

use Mur\Core\Document;
use Mur\Core\PluginContext;
use Mur\Core\Renderer;
use Mur\Core\Slugger;

return static function (PluginContext $context): void {
    $plugin = new class ($context) {
        private array $config = [
            'cloud' => [
                'max' => 40,
                'min_count' => 1,
                'show_counts' => true,
                'sort' => 'name',
                'order' => 'asc',
            ],
            'pages' => [
                'per_page' => 20,
            ],
            'seo' => [
                'title_templates' => [
                    'ru' => [
                        '{tag} — материалы и страницы | {site}',
                        'Все материалы по теме «{tag}» | {site}',
                        'Метка «{tag}»: подборка страниц | {site}',
                        '{tag}: статьи, услуги и материалы | {site}',
                        'Страницы с меткой «{tag}» | {site}',
                    ],
                    'ro' => [
                        '{tag} — articole si pagini | {site}',
                        'Toate materialele despre {tag} | {site}',
                        'Eticheta «{tag}»: selectie de pagini | {site}',
                        '{tag}: servicii, articole si materiale | {site}',
                        'Pagini cu eticheta «{tag}» | {site}',
                    ],
                    'default' => [
                        '{tag} — pages and articles | {site}',
                        'All materials about {tag} | {site}',
                        'Tag «{tag}»: selected pages | {site}',
                        '{tag}: articles, services and materials | {site}',
                        'Pages tagged «{tag}» | {site}',
                    ],
                ],
                'description_templates' => [
                    'ru' => [
                        'Подборка материалов сайта {site} по теме «{tag}»: страницы, услуги, кейсы и справочная информация. Найдено материалов: {count}.',
                        'Все опубликованные страницы с меткой «{tag}» на сайте {site}. Удобная тематическая подборка материалов и разделов.',
                        'Раздел метки «{tag}» объединяет связанные материалы сайта {site}: статьи, страницы услуг, портфолио и полезные заметки.',
                        'Материалы по теме «{tag}» на сайте {site}: смотрите страницы, связанные разделы и дополнительные материалы в одной подборке.',
                        'Страницы с меткой «{tag}»: тематическая навигация по опубликованным материалам сайта {site}.',
                    ],
                    'ro' => [
                        'Selectie de materiale pe tema «{tag}» pe site-ul {site}: pagini, servicii, proiecte si informatii utile. Total materiale: {count}.',
                        'Toate paginile publicate cu eticheta «{tag}» pe site-ul {site}. O selectie tematica pentru navigare rapida.',
                        'Eticheta «{tag}» grupeaza materiale relevante de pe site-ul {site}: articole, servicii, portofoliu si note utile.',
                        'Materiale despre «{tag}» pe site-ul {site}: pagini conexe, sectiuni si informatii suplimentare intr-o singura lista.',
                        'Pagini cu eticheta «{tag}»: navigare tematica prin materialele publicate pe site-ul {site}.',
                    ],
                    'default' => [
                        'A selection of materials about «{tag}» on {site}: pages, services, cases and useful information. Total items: {count}.',
                        'All published pages tagged «{tag}» on {site}. A focused topic page for faster navigation.',
                        'The «{tag}» tag groups related materials on {site}: articles, service pages, portfolio items and notes.',
                        'Materials about «{tag}» on {site}: related pages, sections and additional information in one list.',
                        'Pages tagged «{tag}»: topic navigation across published materials on {site}.',
                    ],
                ],
            ],
            'sitemap' => true,
            'show_document_tags' => true,
        ];
        private bool $used = false;

        public function __construct(private readonly PluginContext $context)
        {
            $configPath = $this->context->path('config/tags.php');

            if (is_file($configPath)) {
                $config = require $configPath;

                if (is_array($config)) {
                    $this->config = array_replace_recursive($this->config, $config);
                }
            }
        }

        public function filterShortcode(string $html, array $hookContext): string
        {
            $language = (string) ($hookContext['language'] ?? $this->context->languages['default'] ?? '');

            return (string) preg_replace_callback(
                '/\[tagcloud(?:\s+([^\]]+))?\]/',
                fn (array $matches): string => $this->renderCloud($language, $this->parseAttributes((string) ($matches[1] ?? ''))),
                $html,
            );
        }

        public function head(string $html): string
        {
            if (!$this->used) {
                return $html;
            }

            return $html . "\n  " . '<link rel="stylesheet" href="' . mur_escape(mur_asset_url('plugins/tags/tags.css')) . '">';
        }

        public function documentTags(string $html, array $hookContext): string
        {
            if (($this->config['show_document_tags'] ?? true) !== true) {
                return $html;
            }

            $document = $hookContext['document'] ?? null;

            if (!$document instanceof Document || $document->collection !== 'pages') {
                return $html;
            }

            $tags = $this->documentTagsList($document);

            if ($tags === []) {
                return $html;
            }

            $this->used = true;
            $items = '';

            foreach ($tags as $tag) {
                $items .= '<a href="' . mur_escape($this->tagUrl($document->language, $tag['slug'])) . '" rel="tag">' . mur_escape($tag['label']) . '</a>';
            }

            return $html . '<nav class="mur-document-tags" aria-label="Метки"><span>Метки:</span>' . $items . '</nav>';
        }

        public function publicRoute(mixed $html, array $routeContext): mixed
        {
            $segments = $routeContext['segments'] ?? [];

            if (!is_array($segments) || ($segments[0] ?? '') !== 'tag' || !isset($segments[1])) {
                return $html;
            }

            $language = (string) ($routeContext['language'] ?? $this->context->languages['default'] ?? '');
            $slug = preg_replace('/[^a-z0-9_-]+/', '', strtolower((string) $segments[1])) ?? '';
            $renderer = $routeContext['renderer'] ?? null;

            if ($slug === '' || !$renderer instanceof Renderer) {
                return $html;
            }

            $tag = $this->tagBySlug($language, $slug);

            if ($tag === null) {
                return $renderer->renderNotFound($language);
            }

            $documents = $this->documentsByTag($language, $slug);
            $count = count($documents);
            $this->used = true;

            $heading = $this->tagHeading($language, (string) $tag['label']);
            $summary = $this->tagSummary($language, (string) $tag['label'], $count);
            $body = '<header class="page-header"><p class="breadcrumbs"><a href="/' . mur_escape($language) . '/">' . mur_escape($this->homeLabel($language)) . '</a><span aria-hidden="true">/</span><span>' . mur_escape($this->tagsLabel($language)) . '</span></p><h1>' . mur_escape($heading) . '</h1><p class="page-summary">' . mur_escape($summary) . '</p></header>';

            if ($documents === []) {
                $body .= '<p>Опубликованных материалов с этой меткой пока нет.</p>';
                $seo = $this->tagSeo($language, $tag, 0, 1);

                return $renderer->renderVirtualPage($language, $seo['title'], $body, $seo['description']);
            }

            [$documents, $pagination, $paginationMeta] = $this->paginate($documents);
            $seo = $this->tagSeo($language, $tag, $count, (int) ($paginationMeta['page'] ?? 1));
            $body .= '<section class="content-list content-list-titles" aria-label="' . mur_escape($heading) . '">';

            foreach ($documents as $document) {
                $description = trim((string) ($document->meta['summary'] ?? $document->meta['description'] ?? $document->meta['seo.description'] ?? ''));
                $body .= '<article class="content-list-item"><div class="content-list-body"><h2 class="content-list-title"><a href="/' . mur_escape($document->language . '/' . $document->urlPath) . '">' . mur_escape($document->title()) . '</a></h2>';
                if ($description !== '') {
                    $body .= '<p class="content-list-description">' . mur_escape($description) . '</p>';
                }
                $body .= '</div></article>';
            }

            $body .= '</section>' . $pagination;

            return $renderer->renderVirtualPage($language, $seo['title'], $body, $seo['description'], $paginationMeta);
        }

        public function sitemap(array $urls): array
        {
            if (($this->config['sitemap'] ?? true) !== true) {
                return $urls;
            }

            foreach (($this->context->languages['enabled'] ?? []) as $language) {
                foreach ($this->allTags((string) $language) as $tag) {
                    $urls[] = [
                        'path' => '/' . $language . '/tag/' . $tag['slug'],
                        'lastmod' => '',
                    ];
                }
            }

            return $urls;
        }

        public function adminFields(string $html, array $adminContext): string
        {
            if (($adminContext['collection'] ?? '') !== 'pages') {
                return $html;
            }

            $language = (string) ($adminContext['language'] ?? $this->context->languages['default'] ?? '');
            $meta = is_array($adminContext['meta'] ?? null) ? $adminContext['meta'] : [];
            $help = (bool) ($adminContext['help'] ?? true);
            $value = (string) ($meta['tags'] ?? '');
            $suggestions = '';

            foreach ($this->allTags($language) as $tag) {
                $suggestions .= '<button class="admin-tag-pill" type="button" data-mur-tag="' . mur_escape($tag['label']) . '">' . mur_escape($tag['label']) . '<span>' . (int) $tag['count'] . '</span></button>';
            }

            $helpText = $help ? '<p class="admin-help">Метки связывают страницы вне дерева и рубрик. Пишите через запятую: <code>seo, pagespeed, cms</code>. Если плагин выключить, поле останется в файле как обычная meta.</p>' : '';

            return $html . '<fieldset class="admin-editor-section admin-tags-editor">
      <legend>Метки</legend>
      <div class="admin-document-field"><label for="field-tags">Метки</label><div class="admin-document-control"><input id="field-tags" name="tags" value="' . mur_escape($value) . '" placeholder="seo, pagespeed, cms">' . $helpText . ($suggestions !== '' ? '<div class="admin-tag-suggestions" aria-label="Существующие метки">' . $suggestions . '</div>' : '') . '</div></div>
      <script src="' . mur_escape(mur_asset_url('plugins/tags/admin-tags.js')) . '" defer></script>
    </fieldset>';
        }

        public function saveMeta(array $meta, array $adminContext): array
        {
            if (($adminContext['collection'] ?? '') !== 'pages') {
                return $meta;
            }

            $post = is_array($adminContext['post'] ?? null) ? $adminContext['post'] : [];
            $tags = $this->normalizeTags((string) ($post['tags'] ?? ''));

            if ($tags !== '') {
                $meta['tags'] = $tags;
            } else {
                unset($meta['tags']);
            }

            return $meta;
        }

        public function knownMetaKeys(array $keys): array
        {
            $keys[] = 'tags';

            return array_values(array_unique($keys));
        }

        public function adminMenu(array $items): array
        {
            $items[] = [
                'id' => 'plugin-tags',
                'label' => 'Метки',
                'url' => '/admin/plugin/tags',
                'role' => 'editor',
            ];

            return $items;
        }

        public function adminRoute(mixed $html, array $adminContext): mixed
        {
            if (($adminContext['route'] ?? '') !== 'plugin/tags') {
                return $html;
            }

            $method = (string) ($adminContext['method'] ?? 'GET');
            $user = is_array($adminContext['user'] ?? null) ? $adminContext['user'] : [];
            $notice = '';

            if ($method === 'POST') {
                $notice = $this->saveSettings($user);
            }

            return $notice . $this->adminPage($user);
        }

        private function renderCloud(string $language, array $attributes = []): string
        {
            $tags = $this->allTags($language);
            $max = max(1, min(200, (int) ($attributes['max'] ?? $this->config['cloud']['max'] ?? 40)));
            $minCount = max(1, (int) ($attributes['min_count'] ?? $this->config['cloud']['min_count'] ?? 1));
            $showCounts = $this->boolValue($attributes['show_counts'] ?? $this->config['cloud']['show_counts'] ?? true);
            $sort = strtolower((string) ($attributes['sort'] ?? $this->config['cloud']['sort'] ?? 'name'));
            $order = strtolower((string) ($attributes['order'] ?? $this->config['cloud']['order'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';

            $tags = array_values(array_filter($tags, static fn (array $tag): bool => (int) $tag['count'] >= $minCount));

            usort($tags, static function (array $a, array $b) use ($sort, $order): int {
                $result = $sort === 'count'
                    ? [(int) $a['count'], (string) $a['label']] <=> [(int) $b['count'], (string) $b['label']]
                    : strnatcasecmp((string) $a['label'], (string) $b['label']);

                return $order === 'desc' ? -$result : $result;
            });

            $tags = array_slice($tags, 0, $max);

            if ($tags === []) {
                return '';
            }

            $this->used = true;
            $items = '';

            foreach ($tags as $tag) {
                $items .= '<a href="' . mur_escape($this->tagUrl($language, $tag['slug'])) . '" rel="tag">' . mur_escape($tag['label']) . ($showCounts ? ' <span>' . (int) $tag['count'] . '</span>' : '') . '</a>';
            }

            return '<nav class="mur-tag-cloud" aria-label="Облако меток">' . $items . '</nav>';
        }

        private function allTags(string $language): array
        {
            $tags = [];

            foreach ($this->context->content->published($language) as $document) {
                if ($document->collection !== 'pages') {
                    continue;
                }

                foreach ($this->documentTagsList($document) as $tag) {
                    $slug = $tag['slug'];
                    $tags[$slug] ??= [
                        'label' => $tag['label'],
                        'slug' => $slug,
                        'count' => 0,
                    ];
                    $tags[$slug]['count']++;
                }
            }

            return array_values($tags);
        }

        private function documentsByTag(string $language, string $slug): array
        {
            $documents = [];

            foreach ($this->context->content->published($language) as $document) {
                if ($document->collection !== 'pages') {
                    continue;
                }

                foreach ($this->documentTagsList($document) as $tag) {
                    if ($tag['slug'] === $slug) {
                        $documents[] = $document;
                        break;
                    }
                }
            }

            return $this->context->content->sort($documents, 'date', 'desc');
        }

        private function documentTagsList(Document $document): array
        {
            $value = trim((string) ($document->meta['tags'] ?? ''));

            if ($value === '') {
                return [];
            }

            $tags = [];

            foreach (preg_split('/[,;]/u', $value) ?: [] as $label) {
                $label = trim($label);

                if ($label === '') {
                    continue;
                }

                $slug = Slugger::make($label, '');

                if ($slug === '') {
                    continue;
                }

                $tags[$slug] = [
                    'label' => $label,
                    'slug' => $slug,
                ];
            }

            return array_values($tags);
        }

        private function tagBySlug(string $language, string $slug): ?array
        {
            foreach ($this->allTags($language) as $tag) {
                if ($tag['slug'] === $slug) {
                    return $tag;
                }
            }

            return null;
        }

        private function tagUrl(string $language, string $slug): string
        {
            return '/' . $language . '/tag/' . $slug;
        }

        private function tagSeo(string $language, array $tag, int $count, int $page): array
        {
            $titleTemplate = $this->selectSeoTemplate('title_templates', $language, (string) $tag['slug']);
            $descriptionTemplate = $this->selectSeoTemplate('description_templates', $language, (string) $tag['slug']);
            $values = [
                '{tag}' => (string) $tag['label'],
                '{site}' => (string) ($this->context->site['name'] ?? ''),
                '{count}' => (string) $count,
            ];
            $title = strtr($titleTemplate, $values);
            $description = strtr($descriptionTemplate, $values);

            if ($page > 1) {
                $title .= ' — ' . $this->pageLabel($language, $page);
                $description .= ' ' . $this->pageLabel($language, $page) . '.';
            }

            return [
                'title' => trim($title),
                'description' => trim($description),
            ];
        }

        private function selectSeoTemplate(string $key, string $language, string $slug): string
        {
            $groups = is_array($this->config['seo'][$key] ?? null) ? $this->config['seo'][$key] : [];
            $templates = is_array($groups[$language] ?? null) ? $groups[$language] : [];

            if ($templates === []) {
                $templates = is_array($groups['default'] ?? null) ? $groups['default'] : [];
            }

            if ($templates === []) {
                return $key === 'title_templates' ? '{tag} | {site}' : 'Материалы с меткой {tag}.';
            }

            $templates = array_values(array_filter($templates, static fn (mixed $template): bool => is_string($template) && trim($template) !== ''));
            if ($templates === []) {
                return $key === 'title_templates' ? '{tag} | {site}' : 'Материалы с меткой {tag}.';
            }

            $index = (int) (abs(crc32($language . ':' . $slug . ':' . $key)) % count($templates));

            return $templates[$index];
        }

        private function tagHeading(string $language, string $tag): string
        {
            return match ($language) {
                'ro' => 'Eticheta: ' . $tag,
                default => 'Метка: ' . $tag,
            };
        }

        private function tagSummary(string $language, string $tag, int $count): string
        {
            return match ($language) {
                'ro' => 'Materiale cu eticheta «' . $tag . '»: ' . $count . '.',
                default => 'Материалы с меткой «' . $tag . '»: ' . $count . '.',
            };
        }

        private function homeLabel(string $language): string
        {
            return match ($language) {
                'ro' => 'Acasa',
                default => 'Главная',
            };
        }

        private function tagsLabel(string $language): string
        {
            return match ($language) {
                'ro' => 'Etichete',
                default => 'Метки',
            };
        }

        private function pageLabel(string $language, int $page): string
        {
            return match ($language) {
                'ro' => 'pagina ' . $page,
                default => 'страница ' . $page,
            };
        }

        private function parseAttributes(string $raw): array
        {
            $attributes = [];

            preg_match_all('/([a-zA-Z0-9_.-]+)=("[^"]*"|\'[^\']*\'|[^\s]+)/', $raw, $matches, PREG_SET_ORDER);

            foreach ($matches as $match) {
                $attributes[strtolower($match[1])] = trim($match[2], " \t\n\r\0\x0B\"'");
            }

            return $attributes;
        }

        private function normalizeTags(string $value): string
        {
            $labels = [];

            foreach (preg_split('/[,;]/u', $value) ?: [] as $label) {
                $label = trim(preg_replace('/\s+/u', ' ', $label) ?? '');

                if ($label !== '') {
                    $labels[Slugger::make($label, $label)] = $label;
                }
            }

            return implode(', ', array_values($labels));
        }

        private function paginate(array $documents): array
        {
            $perPage = max(1, min(100, (int) ($this->config['pages']['per_page'] ?? 20)));
            $total = count($documents);
            $totalPages = max(1, (int) ceil($total / $perPage));
            $page = min(max(1, (int) ($_GET['page'] ?? 1)), $totalPages);
            $documents = array_slice($documents, ($page - 1) * $perPage, $perPage);
            $meta = [
                'page' => $page,
                'total_pages' => $totalPages,
                'total_items' => $total,
                'per_page' => $perPage,
            ];

            if ($totalPages <= 1) {
                return [$documents, '', $meta];
            }

            $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
            $items = '';

            for ($i = 1; $i <= $totalPages; $i++) {
                $url = $i > 1 ? $path . '?page=' . $i : $path;
                $items .= $i === $page
                    ? '<span class="pagination-page is-current" aria-current="page">' . $i . '</span>'
                    : '<a class="pagination-page" href="' . mur_escape($url) . '">' . $i . '</a>';
            }

            return [$documents, '<nav class="pagination" aria-label="Pagination">' . $items . '</nav>', $meta];
        }

        private function adminPage(array $user): string
        {
            $language = (string) ($this->context->languages['default'] ?? 'ru');
            $rows = '';

            foreach ($this->allTags($language) as $tag) {
                $rows .= '<tr><td><a href="' . mur_escape($this->tagUrl($language, $tag['slug'])) . '" target="_blank" rel="noreferrer">' . mur_escape($tag['label']) . '</a></td><td><code>' . mur_escape($tag['slug']) . '</code></td><td>' . (int) $tag['count'] . '</td></tr>';
            }

            if ($rows === '') {
                $rows = '<tr><td colspan="3">Метки пока не найдены.</td></tr>';
            }

            $isAdmin = \Mur\Admin\AdminUserManager::roleRank((string) ($user['role'] ?? ''))
                >= \Mur\Admin\AdminUserManager::roleRank(\Mur\Admin\AdminUserManager::ROLE_ADMIN);
            $form = '';

            if ($isAdmin) {
                $form = '<section class="admin-panel"><h2>Настройки</h2><form class="admin-form" method="post" action="/admin/plugin/tags">
      <input type="hidden" name="_csrf" value="' . mur_escape((string) ($user['csrf'] ?? '')) . '">
      <div class="admin-form-grid admin-form-grid--compact">
        <label>Максимум меток в облаке<input name="cloud_max" type="number" min="1" max="200" value="' . mur_escape((string) ($this->config['cloud']['max'] ?? 40)) . '"></label>
        <label>Минимум материалов<input name="cloud_min_count" type="number" min="1" max="100" value="' . mur_escape((string) ($this->config['cloud']['min_count'] ?? 1)) . '"></label>
        <label>Материалов на странице метки<input name="pages_per_page" type="number" min="1" max="100" value="' . mur_escape((string) ($this->config['pages']['per_page'] ?? 20)) . '"></label>
        <label>Сортировка<select name="cloud_sort"><option value="name"' . $this->selected((string) ($this->config['cloud']['sort'] ?? 'name'), 'name') . '>По имени</option><option value="count"' . $this->selected((string) ($this->config['cloud']['sort'] ?? 'name'), 'count') . '>По количеству</option></select></label>
        <label>Направление<select name="cloud_order"><option value="asc"' . $this->selected((string) ($this->config['cloud']['order'] ?? 'asc'), 'asc') . '>По возрастанию</option><option value="desc"' . $this->selected((string) ($this->config['cloud']['order'] ?? 'asc'), 'desc') . '>По убыванию</option></select></label>
      </div>
      <label class="admin-check"><input type="checkbox" name="show_counts" value="1"' . $this->checked((bool) ($this->config['cloud']['show_counts'] ?? true)) . '> Показывать счетчики в облаке</label>
      <label class="admin-check"><input type="checkbox" name="show_document_tags" value="1"' . $this->checked((bool) ($this->config['show_document_tags'] ?? true)) . '> Показывать метки в конце статьи</label>
      <label class="admin-check"><input type="checkbox" name="sitemap" value="1"' . $this->checked((bool) ($this->config['sitemap'] ?? true)) . '> Добавлять страницы меток в sitemap.xml</label>
      <div class="admin-actions"><button class="admin-button" type="submit">Сохранить</button></div>
    </form></section>';
            }

            return '<section class="admin-panel"><h2>Метки</h2><p>Метки хранятся в meta-поле <code>tags</code> у страниц. Shortcode облака: <code>[tagcloud]</code>, с параметрами: <code>[tagcloud max=20 sort=count order=desc]</code>.</p><table class="admin-table"><thead><tr><th>Метка</th><th>Slug</th><th>Страниц</th></tr></thead><tbody>' . $rows . '</tbody></table></section>' . $form;
        }

        private function saveSettings(array $user): string
        {
            if (\Mur\Admin\AdminUserManager::roleRank((string) ($user['role'] ?? ''))
                < \Mur\Admin\AdminUserManager::roleRank(\Mur\Admin\AdminUserManager::ROLE_ADMIN)) {
                return '<div class="admin-notice admin-notice--error">Для сохранения настроек нужны права admin.</div>';
            }

            if (!hash_equals((string) ($user['csrf'] ?? ''), (string) ($_POST['_csrf'] ?? ''))) {
                return '<div class="admin-notice admin-notice--error">Сессия устарела. Обновите страницу и попробуйте снова.</div>';
            }

            $this->config = [
                'cloud' => [
                    'max' => max(1, min(200, (int) ($_POST['cloud_max'] ?? 40))),
                    'min_count' => max(1, min(100, (int) ($_POST['cloud_min_count'] ?? 1))),
                    'show_counts' => isset($_POST['show_counts']),
                    'sort' => in_array((string) ($_POST['cloud_sort'] ?? 'name'), ['name', 'count'], true) ? (string) $_POST['cloud_sort'] : 'name',
                    'order' => in_array((string) ($_POST['cloud_order'] ?? 'asc'), ['asc', 'desc'], true) ? (string) $_POST['cloud_order'] : 'asc',
                ],
                'pages' => [
                    'per_page' => max(1, min(100, (int) ($_POST['pages_per_page'] ?? 20))),
                ],
                'seo' => is_array($this->config['seo'] ?? null) ? $this->config['seo'] : [],
                'sitemap' => isset($_POST['sitemap']),
                'show_document_tags' => isset($_POST['show_document_tags']),
            ];

            if (!$this->writeConfig()) {
                return '<div class="admin-notice admin-notice--error">Не удалось записать настройки меток. Проверьте права на файл и каталог config.</div>';
            }

            return '<div class="admin-notice">Настройки меток сохранены.</div>';
        }

        private function writeConfig(): bool
        {
            $export = var_export($this->config, true);
            $path = $this->context->path('config/tags.php');
            $tmp = $path . '.tmp';
            $content = "<?php\n"
                . "declare(strict_types=1);\n\n"
                . "if (!defined('MUR_CMS_BOOTSTRAPPED')) {\n"
                . "    http_response_code(403);\n"
                . "    exit('No direct script access allowed.');\n"
                . "}\n\n"
                . 'return ' . $export . ";\n";

            if (@file_put_contents($tmp, $content, LOCK_EX) === false) {
                return false;
            }

            if (!@rename($tmp, $path)) {
                @unlink($tmp);
                return false;
            }

            return true;
        }

        private function boolValue(mixed $value): bool
        {
            if (is_bool($value)) {
                return $value;
            }

            return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
        }

        private function selected(string $current, string $value): string
        {
            return $current === $value ? ' selected' : '';
        }

        private function checked(bool $value): string
        {
            return $value ? ' checked' : '';
        }
    };

    foreach (['content.home', 'content.document', 'layout.footer'] as $hook) {
        $context->hooks->add($hook, [$plugin, 'filterShortcode']);
    }

    $context->hooks->add('layout.head', [$plugin, 'head']);
    $context->hooks->add('content.article_end', [$plugin, 'documentTags'], 20);
    $context->hooks->add('route.public', [$plugin, 'publicRoute']);
    $context->hooks->add('seo.sitemap_urls', [$plugin, 'sitemap']);
    $context->hooks->add('admin.document_fields', [$plugin, 'adminFields']);
    $context->hooks->add('admin.document_meta', [$plugin, 'saveMeta']);
    $context->hooks->add('admin.known_meta_keys', [$plugin, 'knownMetaKeys']);
    $context->hooks->add('admin.menu', [$plugin, 'adminMenu']);
    $context->registerAdminRoute([
        'path' => '/admin/plugin/tags',
        'role' => 'editor',
        'handler' => [$plugin, 'adminRoute'],
    ]);
};
