<?php

declare(strict_types=1);

namespace MurCMS\MurTextPlugin;

use Mur\Core\PluginContext;

if (!defined('MUR_CMS_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('No direct script access allowed.');
}

/**
 * Admin settings, editor assistance and per-document overrides.
 */
final class AdminUi
{
    private array $config;
    private bool $editorAssetsPrinted = false;

    public function __construct(
        private readonly PluginContext $context,
        private readonly string $pluginPath,
        private readonly ConfigStore $configStore,
    ) {
        $this->config = $this->configStore->config();
    }

    public function adminDocumentFields(string $html, array $adminContext): string
    {
        if (($this->config['editor']['document_overrides'] ?? true) !== true) {
            return $html;
        }

        $collection = (string) ($adminContext['collection'] ?? '');
        if (!in_array($collection, ['pages', 'categories'], true)) {
            return $html;
        }

        $meta = is_array($adminContext['meta'] ?? null) ? $adminContext['meta'] : [];
        $parser = strtolower(trim((string) ($meta['parser'] ?? '')));
        $paragraph = strtolower(trim((string) ($meta['murtext.paragraph_mode'] ?? '')));
        $pretty = strtolower(trim((string) ($meta['murtext.pretty'] ?? '')));
        $strict = strtolower(trim((string) ($meta['murtext.strict'] ?? '')));

        return $html . '<details class="admin-editor-disclosure murtext-document-options">
  <summary>MurText</summary>
  <div class="admin-editor-disclosure__body">
    <div class="admin-form-grid admin-form-grid--compact">
      ' . $this->selectField('Парсер', 'murtext_parser', [
          '' => 'Наследовать глобальную настройку',
          'murtext' => 'Включить MurText',
          'native' => 'Использовать штатный рендерер',
      ], $parser) . '
      ' . $this->selectField('Абзацы', 'murtext_paragraph_mode', [
          '' => 'Наследовать',
          'blank_lines' => 'Пустая строка разделяет абзацы',
          'line' => 'Каждая строка — абзац',
      ], $paragraph) . '
      ' . $this->selectField('Формат HTML', 'murtext_pretty', [
          '' => 'Наследовать',
          '0' => 'Компактный',
          '1' => 'Читаемый',
      ], $pretty) . '
      ' . $this->selectField('Строгая проверка', 'murtext_strict', [
          '' => 'Наследовать',
          '0' => 'Выключена',
          '1' => 'Включена',
      ], $strict) . '
    </div>
    <p class="admin-help">В файле можно указать <code>parser: murtext</code> или <code>parser: native</code>. Пустые значения наследуют глобальные настройки.</p>
  </div>
</details>';
    }

    public function saveDocumentMeta(array $meta, array $adminContext): array
    {
        $post = is_array($adminContext['post'] ?? null) ? $adminContext['post'] : [];
        $this->storeChoice($meta, 'parser', (string) ($post['murtext_parser'] ?? ''), ['murtext', 'native']);
        $this->storeChoice($meta, 'murtext.paragraph_mode', (string) ($post['murtext_paragraph_mode'] ?? ''), ['blank_lines', 'line']);
        $this->storeChoice($meta, 'murtext.pretty', (string) ($post['murtext_pretty'] ?? ''), ['0', '1']);
        $this->storeChoice($meta, 'murtext.strict', (string) ($post['murtext_strict'] ?? ''), ['0', '1']);
        return $meta;
    }

    public function knownMetaKeys(array $keys): array
    {
        return array_values(array_unique(array_merge($keys, [
            'parser',
            'murtext.paragraph_mode',
            'murtext.pretty',
            'murtext.strict',
        ])));
    }

    public function editorTools(string $html, array $adminContext): string
    {
        $mode = (string) ($this->config['editor']['mode'] ?? 'help');
        if ($mode === 'off' || !$this->editorSurfaceEnabled($adminContext)) {
            return $html;
        }

        if (($adminContext['editor'] ?? '') === 'snippet' && ($adminContext['type'] ?? '') === 'php') {
            return $html;
        }

        $target = preg_replace('/[^A-Za-z0-9_-]+/', '', (string) ($adminContext['textarea'] ?? 'body')) ?: 'body';
        $toolbar = $mode === 'toolbar' ? $this->toolbarButtons() : '';
        $assets = '';

        if (!$this->editorAssetsPrinted) {
            $this->editorAssetsPrinted = true;
            $assets = '<link rel="stylesheet" href="' . mur_escape(mur_asset_url('plugins/murtext/admin.css')) . '"><script src="' . mur_escape(mur_asset_url('plugins/murtext/admin.js')) . '" defer></script>';
        }

        return $html . $assets . '<div class="murtext-editor-tools" data-murtext-target="' . mur_escape($target) . '">
  <div class="murtext-editor-toolbar">' . $toolbar . '<button class="murtext-help-button" type="button" data-murtext-help>MurText — справка</button></div>
</div>
<dialog class="murtext-help-dialog" data-murtext-dialog>
  <button class="murtext-dialog-close" type="button" data-murtext-close aria-label="Закрыть">×</button>
  ' . $this->helpContent() . '
</dialog>';
    }

    public function adminMenu(array $items): array
    {
        $items[] = [
            'id' => 'plugin-murtext',
            'label' => 'MurText',
            'url' => '/admin/plugin/murtext',
            'role' => 'admin',
        ];
        return $items;
    }

    public function adminRoute(mixed $html, array $adminContext): mixed
    {
        if (($adminContext['route'] ?? '') !== 'plugin/murtext') {
            return $html;
        }

        $user = is_array($adminContext['user'] ?? null) ? $adminContext['user'] : [];
        $method = (string) ($adminContext['method'] ?? 'GET');
        $notice = $method === 'POST' ? $this->saveSettings($user) : '';
        return $notice . $this->settingsPage($user);
    }

    private function settingsPage(array $user): string
    {
        $parser = is_array($this->config['parser'] ?? null) ? $this->config['parser'] : [];
        $scopes = is_array($this->config['scopes'] ?? null) ? $this->config['scopes'] : [];
        $errors = is_array($this->config['errors'] ?? null) ? $this->config['errors'] : [];
        $editor = is_array($this->config['editor'] ?? null) ? $this->config['editor'] : [];
        $site = rtrim((string) ($this->context->site['base_url'] ?? ''), '/');
        $warnings = $this->configWarningsHtml();
        $versionLine = 'Plugin ' . Version::PLUGIN . ' · Core ' . $this->coreVersion() . ' · ' . Version::DIALECT;

        return $warnings . '<section class="admin-panel">
  <div class="admin-panel-head"><div><h2>MurText</h2><p>Лёгкий управляемый парсер контента для MurCMS.</p></div><code>' . mur_escape($versionLine) . '</code></div>
  <form class="admin-form" method="post" action="/admin/plugin/murtext">
    <input type="hidden" name="_csrf" value="' . mur_escape((string) ($user['csrf'] ?? '')) . '">

    <fieldset class="admin-editor-section"><legend>Активация</legend>
      ' . $this->checkField('default_enabled', 'Применять MurText по умолчанию', (bool) ($this->config['default_enabled'] ?? false)) . '
      <p class="admin-help">Если выключено, MurText работает только у файлов с <code>parser: murtext</code>. Если включено — во всех отмеченных областях, пока файл не содержит <code>parser: native</code>.</p>
      <div class="admin-form-grid admin-form-grid--compact">' .
          $this->scopeCheck('pages', 'Страницы', $scopes) .
          $this->scopeCheck('categories', 'Категории', $scopes) .
          $this->scopeCheck('home', 'Блоки главной', $scopes) .
          $this->scopeCheck('footer', 'Блоки футера', $scopes) .
          $this->scopeCheck('snippet', 'Snippets', $scopes) .
      '</div>
    </fieldset>

    <fieldset class="admin-editor-section"><legend>Разбор текста</legend>
      <div class="admin-form-grid admin-form-grid--compact">
        ' . $this->selectField('Режим абзацев', 'paragraph_mode', [
            'blank_lines' => 'Пустая строка разделяет абзацы',
            'line' => 'Каждая строка — абзац',
        ], (string) ($parser['paragraph_mode'] ?? 'blank_lines')) . '
        ' . $this->selectField('Соседние цитаты', 'blockquote_join', [
            'br' => 'Объединять через <br>',
            'space' => 'Объединять пробелом',
            'separate' => 'Оставлять отдельными',
        ], (string) ($parser['blockquote_join'] ?? 'br')) . '
        ' . $this->selectField('loading изображений', 'image_loading', [
            'lazy' => 'lazy', 'eager' => 'eager', '' => 'Не добавлять',
        ], (string) ($parser['image_loading'] ?? 'lazy')) . '
        ' . $this->selectField('decoding изображений', 'image_decoding', [
            'async' => 'async', 'sync' => 'sync', 'auto' => 'auto', '' => 'Не добавлять',
        ], (string) ($parser['image_decoding'] ?? 'async')) . '
      </div>
      ' . $this->checkField('pretty', 'Форматировать итоговый HTML переносами строк', (bool) ($parser['pretty'] ?? false)) . '
      ' . $this->checkField('strict', 'Включить строгую диагностику структуры', (bool) ($parser['strict'] ?? false)) . '
      ' . $this->checkField('hard_break_spaces', 'Два пробела в конце строки создают <br>', (bool) ($parser['hard_break_spaces'] ?? true)) . '
      ' . $this->checkField('hard_break_backslash', 'Обратный слэш в конце строки создаёт <br>', (bool) ($parser['hard_break_backslash'] ?? true)) . '
      ' . $this->checkField('hard_break_command', 'Отдельная команда br создаёт перенос', (bool) ($parser['hard_break_command'] ?? true)) . '
    </fieldset>

    <fieldset class="admin-editor-section"><legend>Адреса</legend>
      <p class="admin-help">Пустое поле берёт значение автоматически из <code>site.base_url</code>.</p>
      <div class="admin-form-grid">
        ' . $this->textField('Корень сайта', 'site_url', (string) ($parser['site_url'] ?? ''), $site) . '
        ' . $this->textField('Каталог uploads', 'uploads_url', (string) ($parser['uploads_url'] ?? ''), $site . '/uploads') . '
        ' . $this->textField('Базовый адрес pages', 'pages_url', (string) ($parser['pages_url'] ?? ''), $site) . '
      </div>
    </fieldset>

    <fieldset class="admin-editor-section"><legend>Ошибки и журнал</legend>
      <div class="admin-form-grid admin-form-grid--compact">
        ' . $this->selectField('При ошибке', 'error_policy', [
            'render' => 'Исправить и вывести MurText',
            'fallback' => 'Вернуться к штатному рендереру',
        ], (string) ($errors['policy'] ?? 'render')) . '
        ' . $this->selectField('Журнал', 'diagnostic_log', [
            'off' => 'Выключен', 'errors' => 'Только ошибки', 'all' => 'Ошибки и предупреждения',
        ], (string) ($errors['log'] ?? 'off')) . '
      </div>
    </fieldset>

    <fieldset class="admin-editor-section"><legend>Редактор</legend>
      <div class="admin-form-grid admin-form-grid--compact">
        ' . $this->selectField('Помощник', 'editor_mode', [
            'off' => 'Выключен', 'help' => 'Только справка', 'toolbar' => 'Справка и панель вставки',
        ], (string) ($editor['mode'] ?? 'help')) . '
      </div>
      ' . $this->checkField('document_overrides', 'Показывать настройки MurText у страниц и категорий', (bool) ($editor['document_overrides'] ?? true)) . '
      ' . $this->checkField('editor_document', 'Показывать помощник в редакторе документов', (bool) ($editor['surfaces']['document'] ?? true)) . '
      ' . $this->checkField('editor_snippet', 'Показывать помощник в редакторе snippets', (bool) ($editor['surfaces']['snippet'] ?? true)) . '
      ' . $this->checkField('editor_content_blocks', 'Показывать помощник в файловом редакторе блоков', (bool) ($editor['surfaces']['content_blocks'] ?? true)) . '
    </fieldset>

    <div class="admin-actions"><button class="admin-button" type="submit">Сохранить настройки</button></div>
  </form>
</section>
<section class="admin-panel" id="murtext-documentation">' . $this->helpContent() . '</section>';
    }

    private function saveSettings(array $user): string
    {
        if (!hash_equals((string) ($user['csrf'] ?? ''), (string) ($_POST['_csrf'] ?? ''))) {
            return '<div class="admin-notice admin-notice--error">Сессия устарела. Обновите страницу.</div>';
        }

        $this->config = [
            'config_version' => 2,
            'default_enabled' => isset($_POST['default_enabled']),
            'scopes' => [
                'pages' => isset($_POST['scope_pages']),
                'categories' => isset($_POST['scope_categories']),
                'home' => isset($_POST['scope_home']),
                'footer' => isset($_POST['scope_footer']),
                'snippet' => isset($_POST['scope_snippet']),
            ],
            'parser' => [
                'dialect' => Version::DIALECT,
                'paragraph_mode' => $this->choice((string) ($_POST['paragraph_mode'] ?? ''), ['blank_lines', 'line'], 'blank_lines'),
                'pretty' => isset($_POST['pretty']),
                'strict' => isset($_POST['strict']),
                'hard_break_spaces' => isset($_POST['hard_break_spaces']),
                'hard_break_backslash' => isset($_POST['hard_break_backslash']),
                'hard_break_command' => isset($_POST['hard_break_command']),
                'blockquote_join' => $this->choice((string) ($_POST['blockquote_join'] ?? ''), ['br', 'space', 'separate'], 'br'),
                'image_loading' => $this->choice((string) ($_POST['image_loading'] ?? ''), ['', 'lazy', 'eager'], 'lazy'),
                'image_decoding' => $this->choice((string) ($_POST['image_decoding'] ?? ''), ['', 'async', 'sync', 'auto'], 'async'),
                'site_url' => $this->cleanUrl((string) ($_POST['site_url'] ?? '')),
                'uploads_url' => $this->cleanUrl((string) ($_POST['uploads_url'] ?? '')),
                'pages_url' => $this->cleanUrl((string) ($_POST['pages_url'] ?? '')),
            ],
            'errors' => [
                'policy' => $this->choice((string) ($_POST['error_policy'] ?? ''), ['render', 'fallback'], 'render'),
                'log' => $this->choice((string) ($_POST['diagnostic_log'] ?? ''), ['off', 'errors', 'all'], 'off'),
            ],
            'editor' => [
                'mode' => $this->choice((string) ($_POST['editor_mode'] ?? ''), ['off', 'help', 'toolbar'], 'help'),
                'document_overrides' => isset($_POST['document_overrides']),
                'surfaces' => [
                    'document' => isset($_POST['editor_document']),
                    'snippet' => isset($_POST['editor_snippet']),
                    'content_blocks' => isset($_POST['editor_content_blocks']),
                ],
            ],
        ];

        if (!$this->configStore->save($this->config)) {
            return '<div class="admin-notice admin-notice--error">Не удалось записать <code>data/config/murtext.php</code>. ' . mur_escape((string) ($this->configStore->lastError() ?? 'Проверьте права на data/config/.')) . '</div>';
        }

        return '<div class="admin-notice">Настройки MurText сохранены в <code>data/config/murtext.php</code>.</div>';
    }

    private function toolbarButtons(): string
    {
        $buttons = [
            ['H2', 'line', 'h2 ', ''], ['B', 'wrap', '**', '**'], ['I', 'wrap', '*', '*'],
            ['P', 'line', '_ ', ''], ['UL', 'line', '- ', ''], ['Link', 'wrap', '[', '](https://)'],
            ['IMG', 'insert', '![Описание](//image.webp)', ''], ['DIV', 'block', 'div(class)', '/div'],
            ['SEC', 'block', 'section(class)', '/section'], ['DET', 'block', "details\nsummary Заголовок", '/details'],
            ['TABLE', 'insert', "table\n|: Заголовок 1 | Заголовок 2 :|\n| Значение 1 | Значение 2 |\n/table", ''],
            ['RAW', 'block', 'raw', '/raw'],
        ];
        $html = '';
        foreach ($buttons as [$label, $action, $before, $after]) {
            $html .= '<button type="button" data-murtext-action="' . mur_escape($action) . '" data-murtext-before="' . mur_escape($before) . '" data-murtext-after="' . mur_escape($after) . '">' . mur_escape($label) . '</button>';
        }
        return $html;
    }

    private function editorSurfaceEnabled(array $context): bool
    {
        $surfaces = is_array($this->config['editor']['surfaces'] ?? null) ? $this->config['editor']['surfaces'] : [];
        return match ((string) ($context['editor'] ?? '')) {
            'document' => ($surfaces['document'] ?? true) === true,
            'snippet' => ($surfaces['snippet'] ?? true) === true,
            'file' => ($context['scope'] ?? '') === 'content-blocks' && ($surfaces['content_blocks'] ?? true) === true,
            default => false,
        };
    }

    private function helpContent(): string
    {
        return '<div class="murtext-help-content"><h2>MurText: краткая справка</h2>
<table class="admin-table"><tbody>
<tr><th>Абзац</th><td><code>_ Текст</code> или обычный текст</td></tr>
<tr><th>Заголовок</th><td><code>h2 Заголовок</code> или <code>## Заголовок</code></td></tr>
<tr><th>Жирный / курсив</th><td><code>**жирный**</code>, <code>*курсив*</code></td></tr>
<tr><th>Ссылка</th><td><code>[Название](https://example.com)</code></td></tr>
<tr><th>Изображение</th><td><code>![Описание](//photo.webp)</code></td></tr>
<tr><th>Блок</th><td><code>div(class)</code> … <code>/div</code></td></tr>
<tr><th>Список</th><td><code>- Пункт</code>; два пробела создают вложенность</td></tr>
<tr><th>Таблица</th><td><code>|: Заголовок :|</code> и <code>| Ячейка |</code> внутри <code>table</code></td></tr>
<tr><th>Без обработки</th><td><code>raw</code> … <code>/raw</code></td></tr>
</tbody></table>
<p>Полная документация MurText входит в documentation package MurCMS. Активация: <code>parser: murtext</code>; отключение: <code>parser: native</code>.</p></div>';
    }

    private function selectField(string $label, string $name, array $options, string $current): string
    {
        $html = '<label>' . mur_escape($label) . '<select name="' . mur_escape($name) . '">';
        foreach ($options as $value => $text) {
            $html .= '<option value="' . mur_escape((string) $value) . '"' . $this->selected($current, (string) $value) . '>' . mur_escape((string) $text) . '</option>';
        }
        return $html . '</select></label>';
    }

    private function textField(string $label, string $name, string $value, string $placeholder): string
    {
        return '<label>' . mur_escape($label) . '<input name="' . mur_escape($name) . '" value="' . mur_escape($value) . '" placeholder="' . mur_escape($placeholder) . '"></label>';
    }

    private function checkField(string $name, string $label, bool $checked): string
    {
        return '<label class="admin-check"><input type="checkbox" name="' . mur_escape($name) . '" value="1"' . $this->checked($checked) . '> ' . mur_escape($label) . '</label>';
    }

    private function scopeCheck(string $name, string $label, array $scopes): string
    {
        return $this->checkField('scope_' . $name, $label, (bool) ($scopes[$name] ?? false));
    }

    private function storeChoice(array &$meta, string $key, string $value, array $allowed): void
    {
        $value = strtolower(trim($value));
        if (in_array($value, $allowed, true)) {
            $meta[$key] = $value;
        } else {
            unset($meta[$key]);
        }
    }

    private function choice(string $value, array $allowed, string $default): string
    {
        return in_array($value, $allowed, true) ? $value : $default;
    }

    private function cleanUrl(string $url): string
    {
        $url = trim($url);
        return $url !== '' && preg_match('~^https?://~i', $url) ? rtrim($url, '/') : '';
    }

    private function configWarningsHtml(): string
    {
        $items = $this->configStore->warnings();
        if ($items === []) {
            return '';
        }

        $html = '<div class="admin-notice admin-notice--warning"><strong>MurText config:</strong><ul>';
        foreach ($items as $item) {
            $html .= '<li>' . mur_escape($item) . '</li>';
        }

        return $html . '</ul></div>';
    }

    private function coreVersion(): string
    {
        require_once $this->pluginPath . '/core/murtext.php';
        return \MurCMS\MurText\MurText::version();
    }

    private function selected(string $current, string $value): string
    {
        return $current === $value ? ' selected' : '';
    }

    private function checked(bool $value): string
    {
        return $value ? ' checked' : '';
    }
}
