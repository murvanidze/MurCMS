<?php

declare(strict_types=1);

namespace MurCMS\MurConsent;

use Mur\Core\PluginContext;

if (!defined('MUR_CMS_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('No direct script access allowed.');
}

final class Diagnostics
{
    public function __construct(
        private readonly PluginContext $context,
        private readonly string $pluginPath,
        private readonly string $configPath,
    ) {}

    /** @return list<array{level:string,code:string,message:string,details:list<string>}> */
    public function run(array $raw, array $runtime): array
    {
        $items = [];
        $google = is_array($raw['google'] ?? null) ? $raw['google'] : [];
        $ga4Raw = (bool) ($google['ga4']['enabled'] ?? false);
        $adsRaw = (bool) ($google['ads']['enabled'] ?? false);
        $gtmRaw = (bool) ($google['gtm']['enabled'] ?? false);
        $flagRaw = (bool) ($raw['flag_counter']['enabled'] ?? false);
        $enabledPlugins = $this->enabledPlugins();
        if (in_array('privacy-consent', $enabledPlugins, true)) {
            $items[] = $this->item('error', 'legacy_plugin_enabled', 'Одновременно включён старый плагин privacy-consent. Отключите его после проверки миграции, иначе на сайте появятся два механизма согласия.');
        }

        if ($ga4Raw && !Config::validGoogleId('ga4', (string) ($google['ga4']['id'] ?? ''))) {
            $items[] = $this->item('error', 'invalid_ga4_id', 'GA4 включён, но Measurement ID имеет неверный формат.');
        }
        if ($adsRaw && !Config::validGoogleId('ads', (string) ($google['ads']['id'] ?? ''))) {
            $items[] = $this->item('error', 'invalid_ads_id', 'Google Ads включён, но Conversion ID имеет неверный формат.');
        }
        if (!Config::validGoogleId('label', (string) ($google['ads']['label'] ?? ''))) {
            $items[] = $this->item('error', 'invalid_ads_label', 'Google Ads Conversion Label содержит недопустимые символы.');
        }
        if ($gtmRaw && !Config::validGoogleId('gtm', (string) ($google['gtm']['id'] ?? ''))) {
            $items[] = $this->item('error', 'invalid_gtm_id', 'GTM включён, но Container ID имеет неверный формат.');
        }
        if ($gtmRaw && !($google['gtm']['analytics'] ?? false) && !($google['gtm']['marketing'] ?? false)) {
            $items[] = $this->item('error', 'gtm_without_category', 'Для GTM не выбрана ни аналитическая, ни маркетинговая категория. Контейнер не будет загружен.');
        }
        if ($ga4Raw && $gtmRaw && ($google['gtm']['analytics'] ?? false)) {
            $items[] = $this->item('warning', 'possible_ga4_duplicate', 'Одновременно включены прямой GA4 и аналитические теги внутри GTM. Проверьте, не отправляется ли page_view дважды.');
        }
        if ($adsRaw && $gtmRaw && ($google['gtm']['marketing'] ?? false)) {
            $items[] = $this->item('warning', 'possible_ads_duplicate', 'Одновременно включены прямой Google Ads и маркетинговые теги внутри GTM. Проверьте дублирование конверсий.');
        }
        if ($flagRaw && ($runtime['flagCounterHtml'] ?? '') === '') {
            $items[] = $this->item('error', 'invalid_flag_counter', 'Flag Counter включён, но HTML отсутствует или не прошёл безопасную проверку.');
        }
        foreach (Config::LANGUAGES as $language) {
            if (($runtime['policyUrls'][$language] ?? '') === '') {
                $items[] = $this->item('warning', 'missing_policy_' . $language, 'Не указан URL политики для ' . strtoupper($language) . '.');
            }
        }
        if (!Config::isReady($runtime) && ($runtime['testMode'] ?? false) !== true) {
            $items[] = $this->item('info', 'no_active_services', 'Не настроен ни один действующий необязательный сервис. MurConsent не выводится на публичной части.');
        }
        if (($runtime['testMode'] ?? false) === true) {
            $items[] = $this->item('warning', 'test_mode', 'Включён тестовый режим: интерфейс показывается, но выбор не сохраняется и сервисы не загружаются.');
        }
        if (!is_file($this->configPath)) {
            $items[] = $this->item('info', 'using_defaults', 'Файл data/config/murconsent.php ещё не создан; используются заводские настройки или миграция старого Privacy Consent.');
        } elseif (!is_writable($this->configPath)) {
            $items[] = $this->item('warning', 'config_not_writable', 'Файл data/config/murconsent.php недоступен для записи веб-серверу.');
        }
        foreach (['murconsent.css', 'murconsent.js', 'admin.css', 'admin.js'] as $asset) {
            $path = mur_engine_path('assets/plugins/murconsent/' . $asset);
            if (!is_file($path)) $items[] = $this->item('error', 'missing_asset_' . $asset, 'Не найден asset plugins/murconsent/' . $asset . '.');
        }
        $https = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') || (string) ($_SERVER['SERVER_PORT'] ?? '') === '443';
        if (!$https) $items[] = $this->item('warning', 'http_cookie', 'Текущий запрос работает по HTTP. На рабочем сайте cookie выбора должна получать атрибут Secure через HTTPS.');

        $matches = $this->scanTrackers();
        if ($matches !== []) {
            $items[] = $this->item('error', 'hardcoded_trackers', 'Найдены признаки Google/Flag Counter, подключённых вне MurConsent. Такие вставки могут обходить согласие.', $matches);
        } else {
            $items[] = $this->item('info', 'scan_clear', 'В доступных локальных файлах не найдено очевидных внешних трекеров вне MurConsent. Это не гарантирует отсутствия динамических вставок.');
        }
        if ($gtmRaw) $items[] = $this->item('info', 'gtm_external', 'MurConsent не может прочитать опубликованный GTM-контейнер. Его триггеры и consent checks проверяются отдельно в GTM и Tag Assistant.');
        return $items;
    }

    /** @return list<string> */
    private function scanTrackers(): array
    {
        $roots = [mur_engine_path('templates'), mur_engine_path('plugins'), $this->context->path('content'), $this->context->path('snippets'), $this->context->path('blocks')];
        $patterns = [
            '~googletagmanager\.com/(?:gtag/js|gtm\.js)~i',
            '~google-analytics\.com/(?:g/collect|collect|analytics\.js)~i',
            '~googleadservices\.com|doubleclick\.net~i',
            '~flagcounter\.com/count~i',
            '~\bgtag\s*\(~i',
        ];
        $matches = [];
        $seen = 0;
        $own = str_replace('\\', '/', $this->pluginPath);
        $ownAssets = str_replace('\\', '/', mur_engine_path('assets/plugins/murconsent'));
        $pluginsRoot = rtrim(str_replace('\\', '/', mur_engine_path('plugins')), '/') . '/';
        $enabledPlugins = $this->enabledPlugins();
        foreach ($roots as $root) {
            if (!is_dir($root)) continue;
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if (!$file instanceof \SplFileInfo || !$file->isFile()) continue;
                if (++$seen > 2000 || count($matches) >= 30) break 2;
                $path = str_replace('\\', '/', $file->getPathname());
                if (str_starts_with($path, rtrim($own, '/') . '/') || str_starts_with($path, rtrim($ownAssets, '/') . '/')) continue;
                if (str_starts_with($path, $pluginsRoot)) {
                    $relativePlugin = substr($path, strlen($pluginsRoot));
                    $pluginId = explode('/', $relativePlugin, 2)[0] ?? '';
                    if ($pluginId === '' || !in_array($pluginId, $enabledPlugins, true)) continue;
                }
                if ($file->getSize() > 2_000_000 || preg_match('/\.(?:php|js|html?|md|mur|txt)$/i', $file->getFilename()) !== 1) continue;
                $content = @file_get_contents($file->getPathname());
                if (!is_string($content)) continue;
                foreach ($patterns as $pattern) {
                    if (preg_match($pattern, $content, $match, PREG_OFFSET_CAPTURE) !== 1) continue;
                    $offset = (int) ($match[0][1] ?? 0);
                    $line = substr_count(substr($content, 0, $offset), "\n") + 1;
                    $matches[] = $this->relative($file->getPathname()) . ':' . $line;
                    break;
                }
            }
        }
        return array_values(array_unique($matches));
    }

    /** @return list<string> */
    private function enabledPlugins(): array
    {
        $path = $this->context->path('config/plugins.php');
        if (!is_file($path)) $path = $this->context->path('config/plugins.dist.php');
        if (!is_file($path)) return [];
        $config = require $path;
        if (!is_array($config)) return [];
        $enabled = is_array($config['enabled'] ?? null) ? $config['enabled'] : [];
        return array_values(array_filter(array_map('strval', $enabled), static fn (string $id): bool => preg_match('/^[a-z0-9][a-z0-9_-]*$/', $id) === 1));
    }

    private function relative(string $path): string
    {
        $root = rtrim(str_replace('\\', '/', mur_root_path()), '/') . '/';
        $path = str_replace('\\', '/', $path);
        return str_starts_with($path, $root) ? substr($path, strlen($root)) : $path;
    }

    /** @return array{level:string,code:string,message:string,details:list<string>} */
    private function item(string $level, string $code, string $message, array $details = []): array
    {
        return ['level' => $level, 'code' => $code, 'message' => $message, 'details' => array_values(array_map('strval', $details))];
    }
}
