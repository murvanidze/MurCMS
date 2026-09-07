<?php

declare(strict_types=1);

namespace MurCMS\MurConsent;

use Mur\Core\PluginContext;

if (!defined('MUR_CMS_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('No direct script access allowed.');
}

final class AdminUi
{
    private const VERSION = Plugin::VERSION;
    /** @var array<string,mixed> */ private array $raw = [];
    /** @var array<string,mixed> */ private array $runtime = [];
    /** @var array<string,mixed> */ private array $defaults = [];
    private bool $migrated = false;

    public function __construct(
        private readonly PluginContext $context,
        private readonly string $pluginPath,
        private readonly string $configPath,
        private readonly string $defaultsPath,
        private readonly string $legacyPath,
    ) {
        $this->reload();
    }

    public function adminMenu(array $items): array
    {
        $items[] = ['id' => 'plugin-murconsent', 'label' => 'MurConsent', 'url' => '/admin/plugin/murconsent', 'role' => 'admin'];
        return $items;
    }

    public function adminRoute(mixed $html, array $adminContext): mixed
    {
        if (($adminContext['route'] ?? '') !== 'plugin/murconsent') return $html;
        $user = is_array($adminContext['user'] ?? null) ? $adminContext['user'] : [];
        if (!$this->isAdmin($user)) return '<section class="admin-panel"><h2>MurConsent</h2><p>Раздел доступен только администратору.</p></section>';
        $notice = '';
        if (strtoupper((string) ($adminContext['method'] ?? 'GET')) === 'POST') $notice = $this->save($user);
        $this->reload();
        $diagnostics = (new Diagnostics($this->context, $this->pluginPath, $this->configPath))->run($this->raw, $this->runtime);
        return $this->assets() . $notice . $this->overview($diagnostics) . $this->form($user, $diagnostics);
    }

    private function reload(): void
    {
        $loaded = Config::load($this->configPath, $this->defaultsPath, $this->legacyPath);
        $this->raw = $loaded['raw'];
        $this->runtime = $loaded['runtime'];
        $this->migrated = (bool) $loaded['migrated'];
        $this->defaults = Config::defaults($this->defaultsPath);
    }

    private function assets(): string
    {
        return '<link rel="stylesheet" href="' . mur_escape(mur_asset_url('plugins/murconsent/admin.css')) . '"><script defer src="' . mur_escape(mur_asset_url('plugins/murconsent/admin.js')) . '"></script>';
    }

    /** @param list<array{level:string,code:string,message:string,details:list<string>}> $items */
    private function overview(array $items): string
    {
        $counts = ['error' => 0, 'warning' => 0, 'info' => 0];
        foreach ($items as $item) if (isset($counts[$item['level']])) $counts[$item['level']]++;
        $cap = $this->runtime['capabilities'] ?? [];
        $active = [];
        if ($cap['analytics'] ?? false) $active[] = 'Аналитика';
        if ($cap['marketing'] ?? false) $active[] = 'Маркетинг';
        if ($cap['flagCounter'] ?? false) $active[] = 'Flag Counter';
        $migrated = $this->migrated ? '<div class="admin-notice">Обнаружена конфигурация старого <code>privacy-consent.php</code>. Значения предварительно перенесены в форму. Сохраните настройки, чтобы создать <code>murconsent.php</code>.</div>' : '';
        $test = ($this->runtime['testMode'] ?? false) ? '<div class="admin-notice admin-notice--error">Включён тестовый режим: сервисы не загружаются и выбор не сохраняется.</div>' : '';
        return '<section class="admin-panel murconsent-admin__hero"><div class="admin-panel-head"><div><h2>MurConsent</h2><p>Строгая предварительная блокировка GA4, Google Ads, GTM и Flag Counter с независимыми категориями согласия.</p></div><code>v' . self::VERSION . '</code></div>'
            . '<div class="admin-grid admin-grid--stats murconsent-admin__stats">'
            . '<article class="admin-card murconsent-admin__stat-text"><strong>' . ($active ? mur_escape(implode(', ', $active)) : 'Не настроены') . '</strong><span>Рабочие категории</span></article>'
            . '<article class="admin-card"><strong>' . mur_escape((string) ($this->runtime['schemaVersion'] ?? '1.0')) . '</strong><span>Схема согласия</span></article>'
            . '<article class="admin-card"><strong>' . (int) ($this->runtime['days'] ?? 365) . '</strong><span>Дней хранения</span></article>'
            . '<article class="admin-card"><strong>' . $counts['error'] . ' / ' . $counts['warning'] . '</strong><span>Ошибки / предупреждения</span></article></div>'
            . $migrated . $test
            . '<div class="admin-actions"><a class="admin-button admin-button--ghost" href="/?murconsent_preview=1" target="_blank" rel="noreferrer">Безопасный предпросмотр</a><a class="admin-button admin-button--ghost" href="#murconsent-diagnostics">Диагностика</a></div></section>';
    }

    /** @param list<array{level:string,code:string,message:string,details:list<string>}> $diagnostics */
    private function form(array $user, array $diagnostics): string
    {
        $cookie = is_array($this->raw['cookie'] ?? null) ? $this->raw['cookie'] : [];
        $google = is_array($this->raw['google'] ?? null) ? $this->raw['google'] : [];
        $ga4 = is_array($google['ga4'] ?? null) ? $google['ga4'] : [];
        $ads = is_array($google['ads'] ?? null) ? $google['ads'] : [];
        $gtm = is_array($google['gtm'] ?? null) ? $google['gtm'] : [];
        $flag = is_array($this->raw['flag_counter'] ?? null) ? $this->raw['flag_counter'] : [];
        $appearance = is_array($this->raw['appearance'] ?? null) ? $this->raw['appearance'] : [];

        $tabs = [
            'main' => 'Основные', 'content' => 'Тексты и cookie', 'google' => 'Google',
            'flag' => 'Flag Counter', 'appearance' => 'Оформление', 'diagnostics' => 'Диагностика',
        ];
        $tabButtons = '';
        foreach ($tabs as $id => $label) $tabButtons .= '<button type="button" class="murconsent-admin__tab-button" data-murconsent-tab-button="' . $id . '" aria-controls="murconsent-tab-' . $id . '"' . ($id === 'main' ? ' aria-selected="true"' : ' aria-selected="false"') . '>' . mur_escape($label) . '</button>';

        $html = '<section class="admin-panel murconsent-admin" data-murconsent-admin><form method="post" action="/admin/plugin/murconsent" class="admin-form"><input type="hidden" name="_csrf" value="' . mur_escape((string) ($user['csrf'] ?? '')) . '">';
        $html .= '<nav class="murconsent-admin__tabs" aria-label="Разделы настроек">' . $tabButtons . '</nav><div class="murconsent-admin__panels">';

        $html .= '<section id="murconsent-tab-main" class="murconsent-admin__tab" data-murconsent-tab="main">'
            . '<h2>Основные настройки</h2><p class="admin-help">Плагин включается и выключается штатно на странице «Плагины». Внутреннего дублирующего переключателя нет.</p>'
            . $this->check('test_mode', 'Тестовый режим: всегда показывать интерфейс, не сохранять выбор и не загружать сервисы', (bool) ($this->raw['test_mode'] ?? false))
            . '<div class="admin-form-grid">'
            . $this->text('schema_version', 'Версия схемы согласия', (string) ($this->raw['schema_version'] ?? '1.0'), '1.0')
            . $this->number('decision_days', 'Срок хранения выбора, дней', (int) ($cookie['days'] ?? 365), 1, 3650)
            . $this->text('cookie_name', 'Имя cookie выбора', (string) ($cookie['name'] ?? 'murconsent_preferences'), 'murconsent_preferences')
            . $this->text('operator_name', 'Наименование оператора', (string) ($this->raw['operator_name'] ?? ''), 'Компания / владелец сайта')
            . '</div><div class="admin-form-grid">';
        foreach (Config::LANGUAGES as $language) $html .= $this->text('policy_urls[' . $language . ']', 'URL политики ' . strtoupper($language), (string) ($this->raw['policy_urls'][$language] ?? ''), '/' . $language . '/privacy');
        $html .= '</div>'
            . $this->check('auto_footer_link', 'Автоматически добавлять ссылку «Настройки конфиденциальности» в footer', (bool) ($this->raw['auto_footer_link'] ?? true))
            . $this->check('reload_on_withdraw', 'Перезагружать страницу после отзыва уже загруженных Google-сервисов', (bool) ($this->raw['reload_on_withdraw'] ?? true))
            . '<p class="admin-help">Для ручной ссылки используйте <code>&lt;a href="#" data-murconsent-open&gt;Настройки конфиденциальности&lt;/a&gt;</code>.</p></section>';

        $html .= '<section id="murconsent-tab-content" class="murconsent-admin__tab" data-murconsent-tab="content" hidden><h2>Тексты и обязательные cookie</h2><p class="admin-help">Все значения выводятся как безопасный текст без HTML. Пустые основные поля возвращаются к штатному переводу.</p>' . $this->contentPanels() . '</section>';

        $html .= '<section id="murconsent-tab-google" class="murconsent-admin__tab" data-murconsent-tab="google" hidden><h2>Независимые интеграции Google</h2><div class="admin-notice">Прямой GA4, прямой Google Ads и GTM могут работать одновременно, но не должны отправлять одно и то же событие в одно назначение повторно.</div>'
            . '<article class="murconsent-admin__integration"><h3>Google Analytics 4 напрямую</h3>'
            . $this->check('ga4_enabled', 'Использовать GA4 напрямую', (bool) ($ga4['enabled'] ?? false))
            . $this->text('ga4_id', 'Measurement ID', (string) ($ga4['id'] ?? ''), 'G-XXXXXXXXXX') . '</article>'
            . '<article class="murconsent-admin__integration"><h3>Google Ads напрямую</h3>'
            . $this->check('ads_enabled', 'Использовать Google Ads и измерение конверсий напрямую', (bool) ($ads['enabled'] ?? false))
            . '<div class="admin-form-grid">' . $this->text('ads_id', 'Conversion ID', (string) ($ads['id'] ?? ''), 'AW-123456789') . $this->text('ads_label', 'Conversion Label', (string) ($ads['label'] ?? ''), 'AbCdEfGh...') . '</div></article>'
            . '<article class="murconsent-admin__integration"><h3>Google Tag Manager</h3>'
            . $this->check('gtm_enabled', 'Использовать Google Tag Manager', (bool) ($gtm['enabled'] ?? false))
            . $this->text('gtm_id', 'Container ID', (string) ($gtm['id'] ?? ''), 'GTM-XXXXXXX')
            . '<div class="murconsent-admin__suboptions"><strong>Что находится внутри контейнера:</strong>'
            . $this->check('gtm_analytics', 'Аналитические теги', (bool) ($gtm['analytics'] ?? false))
            . $this->check('gtm_marketing', 'Рекламные или конверсионные теги', (bool) ($gtm['marketing'] ?? false))
            . '<p class="admin-help">Перед загрузкой GTM создаются <code>murconsent_analytics</code>, <code>murconsent_marketing</code>, <code>murconsent_flag_counter</code> и событие <code>murconsent_ready</code>.</p></div></article>'
            . '<div class="admin-notice"><strong>JavaScript API:</strong> <code>window.MurConsent.getState()</code>, <code>hasConsent()</code>, <code>trackEvent()</code>, <code>trackConversion()</code>.</div></section>';

        $html .= '<section id="murconsent-tab-flag" class="murconsent-admin__tab" data-murconsent-tab="flag" hidden><h2>Flag Counter</h2>'
            . $this->check('flag_counter_enabled', 'Использовать Flag Counter', (bool) ($flag['enabled'] ?? false))
            . $this->textarea('flag_counter_html', 'Стандартный HTML-код Flag Counter', (string) ($flag['html'] ?? ''), 8, '<a href="https://info.flagcounter.com/..."><img src="https://s11.flagcounter.com/count2/..."></a>')
            . '<p class="admin-help">Разрешается только одна ссылка и одно изображение с адресами на <code>flagcounter.com</code>. Для места вывода добавьте <code>&lt;div data-murconsent-flag-counter&gt;&lt;/div&gt;</code>.</p>';
        if (($flag['enabled'] ?? false) && ($this->runtime['flagCounterHtml'] ?? '') === '') $html .= '<div class="admin-notice admin-notice--error">Код Flag Counter не прошёл безопасную проверку.</div>';
        elseif (($flag['enabled'] ?? false) && ($this->runtime['flagCounterHtml'] ?? '') !== '') $html .= '<div class="admin-notice">Код Flag Counter прошёл безопасную проверку.</div>';
        $html .= '</section>';

        $html .= '<section id="murconsent-tab-appearance" class="murconsent-admin__tab" data-murconsent-tab="appearance" hidden><h2>Оформление</h2><div class="admin-form-grid">'
            . $this->select('banner_width', 'Ширина первого уровня на десктопе', ['container' => 'По ширине контейнера', 'full' => 'На всю ширину окна'], (string) ($appearance['banner_width'] ?? 'container'))
            . $this->number('container_width', 'Максимальная ширина контейнера, px', (int) ($appearance['container_width'] ?? 1180), 640, 1920)
            . '</div><p class="admin-help">На мобильных устройствах первый слой ограничен 50dvh, а подробная панель занимает 100dvh. Иконка шестерёнки — локальный inline SVG, Font Awesome не используется.</p></section>';

        $html .= '<section id="murconsent-tab-diagnostics" class="murconsent-admin__tab" data-murconsent-tab="diagnostics" hidden><div id="murconsent-diagnostics"><h2>Диагностика</h2>' . $this->diagnosticCards($diagnostics) . '</div></section>';
        $html .= '</div><div class="murconsent-admin__save"><button class="admin-button" type="submit">Сохранить настройки</button></div></form></section>';
        return $html;
    }

    private function contentPanels(): string
    {
        $labels = ['ru' => 'Русский', 'ro' => 'Română', 'en' => 'English'];
        $html = '';
        foreach ($labels as $language => $label) {
            $text = Services::translations($this->pluginPath, $this->runtime, $language);
            $services = $this->raw['necessary_services'][$language] ?? [];
            if (!is_array($services) || $services === []) $services = [Services::necessary($this->runtime, $text, $language)[0]];
            $html .= '<details class="murconsent-admin__language"' . ($language === 'ru' ? ' open' : '') . '><summary><strong>' . mur_escape($label) . '</strong><span>' . strtoupper($language) . '</span></summary><div class="murconsent-admin__language-body">'
                . '<div class="murconsent-admin__content-pairs">'
                . '<div class="murconsent-admin__content-pair">'
                . $this->text('content_overrides[' . $language . '][banner_title]', 'Заголовок первого баннера', (string) ($text['banner_title'] ?? ''), '')
                . $this->textarea('content_overrides[' . $language . '][banner_text]', 'Текст первого баннера', (string) ($text['banner_text'] ?? ''), 5)
                . '</div><div class="murconsent-admin__content-pair">'
                . $this->text('content_overrides[' . $language . '][necessary_title]', 'Заголовок обязательных cookie', (string) ($text['necessary_title'] ?? ''), '')
                . $this->textarea('content_overrides[' . $language . '][necessary_desc]', 'Описание обязательных cookie', (string) ($text['necessary_desc'] ?? ''), 4)
                . '</div></div><h3>Обязательные cookie и технические сервисы</h3><div class="murconsent-admin__services" data-murconsent-services data-next-index="' . count($services) . '">';
            foreach ($services as $index => $service) $html .= $this->serviceCard($language, (int) $index, is_array($service) ? $service : []);
            $html .= '<button type="button" class="admin-button admin-button--ghost" data-murconsent-add-service>Добавить запись</button></div></div></details>';
        }
        return $html;
    }

    /** @param array<string,mixed> $service */
    private function serviceCard(string $language, int $index, array $service): string
    {
        $p = 'necessary_services[' . $language . '][' . $index . ']';
        return '<article class="murconsent-admin__service-card" data-murconsent-service-row><div class="murconsent-admin__service-head"><strong data-murconsent-service-number>Запись ' . ($index + 1) . '</strong><button type="button" class="admin-button admin-button--ghost" data-murconsent-remove-service>Удалить</button></div><div class="murconsent-admin__service-fields">'
            . $this->textRow($p . '[name]', 'Название cookie или сервиса', (string) ($service['name'] ?? ''), 'MurConsent preferences')
            . $this->textRow($p . '[provider]', 'Поставщик', (string) ($service['provider'] ?? ''), 'Владелец сайта')
            . $this->textRow($p . '[purpose]', 'Цель', (string) ($service['purpose'] ?? ''))
            . $this->textRow($p . '[domains]', 'Домены', (string) ($service['domains'] ?? ''))
            . $this->textRow($p . '[storage]', 'Cookies и хранилища', (string) ($service['storage'] ?? ''))
            . $this->textRow($p . '[retention]', 'Срок хранения', (string) ($service['retention'] ?? ''))
            . $this->textRow($p . '[policy_url]', 'URL политики / дополнительной информации', (string) ($service['policy_url'] ?? ''), '/ru/privacy')
            . '</div></article>';
    }

    /** @param list<array{level:string,code:string,message:string,details:list<string>}> $items */
    private function diagnosticCards(array $items): string
    {
        $html = '<div class="murconsent-admin__diagnostics">';
        foreach ($items as $item) {
            $details = '';
            if ($item['details'] !== []) {
                $details = '<ul>';
                foreach ($item['details'] as $detail) $details .= '<li><code>' . mur_escape($detail) . '</code></li>';
                $details .= '</ul>';
            }
            $html .= '<article class="murconsent-admin__diagnostic murconsent-admin__diagnostic--' . mur_escape($item['level']) . '"><div><strong>' . strtoupper(mur_escape($item['level'])) . '</strong> <code>' . mur_escape($item['code']) . '</code></div><p>' . mur_escape($item['message']) . '</p>' . $details . '</article>';
        }
        return $html . '</div>';
    }

    private function save(array $user): string
    {
        $csrf = (string) ($user['csrf'] ?? '');
        $submitted = (string) ($_POST['_csrf'] ?? '');
        if ($csrf === '' || $submitted === '' || !hash_equals($csrf, $submitted)) return $this->notice('Сессия устарела. Обновите страницу и повторите действие.', true);
        if (!$this->isAdmin($user)) return $this->notice('Недостаточно прав для сохранения.', true);
        $config = Config::fromPost($_POST, $this->raw, $this->defaults);
        $runtime = Config::normalize($config);
        if (($config['google']['ga4']['enabled'] ?? false) && !Config::validGoogleId('ga4', (string) ($config['google']['ga4']['id'] ?? ''))) return $this->notice('Не сохранено: укажите корректный GA4 Measurement ID.', true);
        if (($config['google']['ads']['enabled'] ?? false) && !Config::validGoogleId('ads', (string) ($config['google']['ads']['id'] ?? ''))) return $this->notice('Не сохранено: укажите корректный Google Ads Conversion ID.', true);
        if (!Config::validGoogleId('label', (string) ($config['google']['ads']['label'] ?? ''))) return $this->notice('Не сохранено: Conversion Label содержит недопустимые символы.', true);
        if (($config['google']['gtm']['enabled'] ?? false) && !Config::validGoogleId('gtm', (string) ($config['google']['gtm']['id'] ?? ''))) return $this->notice('Не сохранено: укажите корректный GTM Container ID.', true);
        if (($config['google']['gtm']['enabled'] ?? false) && !($config['google']['gtm']['analytics'] ?? false) && !($config['google']['gtm']['marketing'] ?? false)) return $this->notice('Не сохранено: укажите, какие категории находятся внутри GTM.', true);
        if (($config['flag_counter']['enabled'] ?? false) && ($runtime['flagCounterHtml'] ?? '') === '') return $this->notice('Не сохранено: HTML Flag Counter не прошёл безопасную проверку.', true);
        try {
            $this->writeConfig($config);
        } catch (\Throwable $exception) {
            return $this->notice('Не удалось сохранить настройки: ' . $exception->getMessage(), true);
        }
        return $this->notice('Настройки MurConsent сохранены.');
    }

    /** @param array<string,mixed> $config */
    private function writeConfig(array $config): void
    {
        $directory = dirname($this->configPath);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) throw new \RuntimeException('не удалось создать каталог конфигурации');
        $this->backup();
        $php = "<?php\n\ndeclare(strict_types=1);\n\n"
            . "if (!defined('MUR_CMS_BOOTSTRAPPED')) {\n"
            . "    http_response_code(403);\n"
            . "    exit('No direct script access allowed.');\n"
            . "}\n\nreturn " . var_export($config, true) . ";\n";
        $tmp = $this->configPath . '.tmp-' . bin2hex(random_bytes(5));
        if (file_put_contents($tmp, $php, LOCK_EX) === false) throw new \RuntimeException('не удалось записать временный файл');
        if (!@rename($tmp, $this->configPath)) {
            @unlink($tmp);
            throw new \RuntimeException('не удалось заменить файл конфигурации');
        }
    }

    private function backup(): void
    {
        if (!is_file($this->configPath)) return;
        $dir = $this->context->path('storage/backups/murconsent');
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) throw new \RuntimeException('не удалось создать каталог резервных копий');
        $target = $dir . '/murconsent-' . date('Ymd-His') . '-' . bin2hex(random_bytes(2)) . '.php';
        if (!copy($this->configPath, $target)) throw new \RuntimeException('не удалось создать резервную копию');
        $files = glob($dir . '/murconsent-*.php') ?: [];
        usort($files, static fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));
        foreach (array_slice($files, 5) as $file) @unlink($file);
    }

    private function isAdmin(array $user): bool
    {
        if (class_exists(\Mur\Admin\AdminUserManager::class)) {
            return \Mur\Admin\AdminUserManager::roleRank((string) ($user['role'] ?? '')) >= \Mur\Admin\AdminUserManager::roleRank(\Mur\Admin\AdminUserManager::ROLE_ADMIN);
        }
        return in_array((string) ($user['role'] ?? ''), ['admin', 'super_admin'], true);
    }

    private function text(string $name, string $label, string $value, string $placeholder = ''): string
    {
        return '<label><span>' . mur_escape($label) . '</span><input type="text" name="' . mur_escape($name) . '" value="' . mur_escape($value) . '" placeholder="' . mur_escape($placeholder) . '"></label>';
    }

    private function textRow(string $name, string $label, string $value, string $placeholder = ''): string
    {
        return '<label class="murconsent-admin__field-row"><span>' . mur_escape($label) . '</span><input type="text" name="' . mur_escape($name) . '" value="' . mur_escape($value) . '" placeholder="' . mur_escape($placeholder) . '"></label>';
    }

    private function number(string $name, string $label, int $value, int $min, int $max): string
    {
        return '<label><span>' . mur_escape($label) . '</span><input type="number" name="' . mur_escape($name) . '" value="' . $value . '" min="' . $min . '" max="' . $max . '"></label>';
    }

    private function textarea(string $name, string $label, string $value, int $rows, string $placeholder = ''): string
    {
        return '<label><span>' . mur_escape($label) . '</span><textarea name="' . mur_escape($name) . '" rows="' . $rows . '" placeholder="' . mur_escape($placeholder) . '">' . mur_escape($value) . '</textarea></label>';
    }

    private function select(string $name, string $label, array $options, string $current): string
    {
        $html = '<label><span>' . mur_escape($label) . '</span><select name="' . mur_escape($name) . '">';
        foreach ($options as $value => $text) $html .= '<option value="' . mur_escape((string) $value) . '"' . ($current === (string) $value ? ' selected' : '') . '>' . mur_escape((string) $text) . '</option>';
        return $html . '</select></label>';
    }

    private function check(string $name, string $label, bool $checked): string
    {
        return '<label class="admin-check"><input type="checkbox" name="' . mur_escape($name) . '" value="1"' . ($checked ? ' checked' : '') . '> <span>' . mur_escape($label) . '</span></label>';
    }

    private function notice(string $message, bool $error = false): string
    {
        return '<div class="admin-notice' . ($error ? ' admin-notice--error' : '') . '" role="status">' . mur_escape($message) . '</div>';
    }
}
