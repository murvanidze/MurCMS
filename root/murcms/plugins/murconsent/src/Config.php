<?php

declare(strict_types=1);

namespace MurCMS\MurConsent;

if (!defined('MUR_CMS_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('No direct script access allowed.');
}

final class Config
{
    public const LANGUAGES = ['ro', 'ru', 'en'];
    public const OVERRIDE_KEYS = ['banner_title', 'banner_text', 'necessary_title', 'necessary_desc'];
    public const NECESSARY_KEYS = ['name', 'provider', 'purpose', 'domains', 'storage', 'retention', 'policy_url'];

    /** @return array{raw:array<string,mixed>,runtime:array<string,mixed>,exists:bool,migrated:bool} */
    public static function load(string $configPath, string $defaultsPath, ?string $legacyPath = null): array
    {
        $defaults = self::requireArray($defaultsPath);
        $exists = is_file($configPath);
        $migrated = false;
        $user = $exists ? self::requireArray($configPath) : [];
        if (!$exists && $legacyPath !== null && is_file($legacyPath)) {
            $legacy = self::requireArray($legacyPath);
            $user = self::migrateLegacy($legacy);
            $migrated = $user !== [];
        }
        $raw = self::merge($defaults, $user);
        return ['raw' => $raw, 'runtime' => self::normalize($raw), 'exists' => $exists, 'migrated' => $migrated];
    }

    /** @return array<string,mixed> */
    public static function defaults(string $path): array
    {
        return self::requireArray($path);
    }

    /** @param array<string,mixed> $config @return array<string,mixed> */
    public static function normalize(array $config): array
    {
        $ga4Id = strtoupper(trim((string) ($config['google']['ga4']['id'] ?? '')));
        $adsId = strtoupper(trim((string) ($config['google']['ads']['id'] ?? '')));
        $adsLabel = trim((string) ($config['google']['ads']['label'] ?? ''));
        $gtmId = strtoupper(trim((string) ($config['google']['gtm']['id'] ?? '')));
        $flagHtmlRaw = trim((string) ($config['flag_counter']['html'] ?? ''));
        $flagHtmlSafe = self::sanitizeFlagCounterHtml($flagHtmlRaw);

        $ga4Enabled = (bool) ($config['google']['ga4']['enabled'] ?? false) && self::validGoogleId('ga4', $ga4Id);
        $adsEnabled = (bool) ($config['google']['ads']['enabled'] ?? false) && self::validGoogleId('ads', $adsId);
        $gtmEnabled = (bool) ($config['google']['gtm']['enabled'] ?? false) && self::validGoogleId('gtm', $gtmId);
        $gtmAnalytics = $gtmEnabled && (bool) ($config['google']['gtm']['analytics'] ?? false);
        $gtmMarketing = $gtmEnabled && (bool) ($config['google']['gtm']['marketing'] ?? false);
        $flagEnabled = (bool) ($config['flag_counter']['enabled'] ?? false) && $flagHtmlSafe !== '';

        $policyUrls = [];
        foreach (self::LANGUAGES as $language) {
            $policyUrls[$language] = self::safeUrl((string) ($config['policy_urls'][$language] ?? ''));
        }

        $width = strtolower(trim((string) ($config['appearance']['banner_width'] ?? 'container')));
        if (!in_array($width, ['container', 'full'], true)) {
            $width = 'container';
        }

        $schema = preg_replace('/[^A-Za-z0-9._-]/', '', (string) ($config['schema_version'] ?? '1.0')) ?: '1.0';
        $cookieName = trim((string) ($config['cookie']['name'] ?? 'murconsent_preferences'));
        if (preg_match('/^[A-Za-z0-9_-]{1,80}$/', $cookieName) !== 1) {
            $cookieName = 'murconsent_preferences';
        }

        return [
            'testMode' => (bool) ($config['test_mode'] ?? false),
            'schemaVersion' => substr($schema, 0, 64),
            'cookieName' => $cookieName,
            'days' => min(3650, max(1, (int) ($config['cookie']['days'] ?? 365))),
            'operatorName' => self::plain((string) ($config['operator_name'] ?? ''), 200),
            'policyUrls' => $policyUrls,
            'ga4Enabled' => $ga4Enabled,
            'ga4Id' => $ga4Id,
            'adsEnabled' => $adsEnabled,
            'adsId' => $adsId,
            'adsLabel' => self::validGoogleId('label', $adsLabel) ? $adsLabel : '',
            'gtmEnabled' => $gtmEnabled,
            'gtmId' => $gtmId,
            'gtmAnalyticsEnabled' => $gtmAnalytics,
            'gtmMarketingEnabled' => $gtmMarketing,
            'flagCounterEnabled' => $flagEnabled,
            'flagCounterHtml' => $flagHtmlSafe,
            'flagCounterHtmlRaw' => $flagHtmlRaw,
            'bannerWidth' => $width,
            'containerWidth' => min(1920, max(640, (int) ($config['appearance']['container_width'] ?? 1180))),
            'autoFooterLink' => (bool) ($config['auto_footer_link'] ?? true),
            'reloadOnWithdraw' => (bool) ($config['reload_on_withdraw'] ?? true),
            'contentOverrides' => self::sanitizeOverrides($config['content_overrides'] ?? []),
            'necessaryServices' => self::sanitizeNecessaryServices($config['necessary_services'] ?? []),
            'capabilities' => [
                'analytics' => $ga4Enabled || $gtmAnalytics,
                'marketing' => $adsEnabled || $gtmMarketing,
                'flagCounter' => $flagEnabled,
            ],
        ];
    }

    /** @param array<string,mixed> $post @param array<string,mixed> $current @param array<string,mixed> $defaults @return array<string,mixed> */
    public static function fromPost(array $post, array $current, array $defaults): array
    {
        $base = self::merge($defaults, $current);
        $base['config_version'] = 2;
        $base['test_mode'] = isset($post['test_mode']);
        $base['schema_version'] = preg_replace('/[^A-Za-z0-9._-]/', '', (string) ($post['schema_version'] ?? '1.0')) ?: '1.0';
        $base['cookie'] = [
            'name' => preg_match('/^[A-Za-z0-9_-]{1,80}$/', (string) ($post['cookie_name'] ?? '')) === 1
                ? (string) $post['cookie_name'] : 'murconsent_preferences',
            'days' => min(3650, max(1, (int) ($post['decision_days'] ?? 365))),
        ];
        $base['operator_name'] = self::plain((string) ($post['operator_name'] ?? ''), 200);
        foreach (self::LANGUAGES as $language) {
            $base['policy_urls'][$language] = self::safeUrl((string) ($post['policy_urls'][$language] ?? ''));
        }
        $base['google'] = [
            'ga4' => ['enabled' => isset($post['ga4_enabled']), 'id' => strtoupper(trim((string) ($post['ga4_id'] ?? '')))],
            'ads' => [
                'enabled' => isset($post['ads_enabled']),
                'id' => strtoupper(trim((string) ($post['ads_id'] ?? ''))),
                'label' => trim((string) ($post['ads_label'] ?? '')),
            ],
            'gtm' => [
                'enabled' => isset($post['gtm_enabled']),
                'id' => strtoupper(trim((string) ($post['gtm_id'] ?? ''))),
                'analytics' => isset($post['gtm_analytics']),
                'marketing' => isset($post['gtm_marketing']),
            ],
        ];
        $base['flag_counter'] = [
            'enabled' => isset($post['flag_counter_enabled']),
            'html' => substr(trim((string) ($post['flag_counter_html'] ?? '')), 0, 20000),
        ];
        $width = (string) ($post['banner_width'] ?? 'container');
        $base['appearance'] = [
            'banner_width' => in_array($width, ['container', 'full'], true) ? $width : 'container',
            'container_width' => min(1920, max(640, (int) ($post['container_width'] ?? 1180))),
        ];
        $base['auto_footer_link'] = isset($post['auto_footer_link']);
        $base['reload_on_withdraw'] = isset($post['reload_on_withdraw']);
        $base['content_overrides'] = self::sanitizeOverrides($post['content_overrides'] ?? []);
        $base['necessary_services'] = self::sanitizeNecessaryServices($post['necessary_services'] ?? []);
        return $base;
    }

    public static function validGoogleId(string $type, string $value): bool
    {
        $value = trim($value);
        return match ($type) {
            'ga4' => preg_match('/^G-[A-Z0-9]{4,20}$/', $value) === 1,
            'ads' => preg_match('/^AW-[0-9]{5,20}$/', $value) === 1,
            'gtm' => preg_match('/^GTM-[A-Z0-9]{4,20}$/', $value) === 1,
            'label' => $value === '' || preg_match('/^[A-Za-z0-9_-]{1,128}$/', $value) === 1,
            default => false,
        };
    }

    public static function isReady(array $runtime): bool
    {
        $capabilities = is_array($runtime['capabilities'] ?? null) ? $runtime['capabilities'] : [];
        return ($capabilities['analytics'] ?? false) || ($capabilities['marketing'] ?? false) || ($capabilities['flagCounter'] ?? false);
    }

    public static function safeUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') return '';
        if (filter_var($url, FILTER_VALIDATE_URL)) {
            $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
            return in_array($scheme, ['http', 'https'], true) ? $url : '';
        }
        if (preg_match('~^[a-z][a-z0-9+.-]*:~i', $url) === 1 || str_starts_with($url, '//')) return '';
        if ($url[0] === '/' || $url[0] === '#') return $url;
        return '/' . ltrim($url, '/');
    }

    public static function sanitizeFlagCounterHtml(string $html): string
    {
        $html = trim($html);
        if ($html === '') return '';
        if (class_exists(\DOMDocument::class)) {
            $previous = libxml_use_internal_errors(true);
            $document = new \DOMDocument('1.0', 'UTF-8');
            $loaded = $document->loadHTML('<?xml encoding="UTF-8"><!DOCTYPE html><html><body><div id="mc-flag-root">' . $html . '</div></body></html>');
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
            if (!$loaded) return '';
            $root = $document->getElementById('mc-flag-root');
            if (!$root) return '';
            $elements = [];
            foreach ($root->childNodes as $node) {
                if ($node->nodeType === XML_TEXT_NODE && trim((string) $node->nodeValue) === '') continue;
                if ($node->nodeType !== XML_ELEMENT_NODE) return '';
                $elements[] = $node;
            }
            if (count($elements) !== 1 || strtolower($elements[0]->nodeName) !== 'a') return '';
            $anchor = $elements[0];
            $children = [];
            foreach ($anchor->childNodes as $node) {
                if ($node->nodeType === XML_TEXT_NODE && trim((string) $node->nodeValue) === '') continue;
                if ($node->nodeType !== XML_ELEMENT_NODE) return '';
                $children[] = $node;
            }
            if (count($children) !== 1 || strtolower($children[0]->nodeName) !== 'img') return '';
            $image = $children[0];
            $href = trim($anchor->getAttribute('href'));
            $src = trim($image->getAttribute('src'));
            if (!self::isFlagCounterUrl($href) || !self::isFlagCounterUrl($src)) return '';
            $out = '<a href="' . self::escape($href) . '" rel="noopener noreferrer nofollow"';
            if ($anchor->getAttribute('target') === '_blank') $out .= ' target="_blank"';
            $title = self::plain($anchor->getAttribute('title'), 250);
            if ($title !== '') $out .= ' title="' . self::escape($title) . '"';
            $out .= '><img src="' . self::escape($src) . '" alt="' . self::escape(self::plain($image->getAttribute('alt'), 250) ?: 'Flag Counter') . '"';
            foreach (['width', 'height'] as $attr) {
                $value = trim($image->getAttribute($attr));
                if (preg_match('/^[0-9]{1,4}$/', $value) === 1 && (int) $value > 0 && (int) $value <= 2000) $out .= ' ' . $attr . '="' . $value . '"';
            }
            $out .= ' loading="lazy"></a>';
            return $out;
        }
        if (!preg_match('~^\s*<a\b[^>]*href\s*=\s*(["\'])(.*?)\1[^>]*>\s*<img\b[^>]*src\s*=\s*(["\'])(.*?)\3[^>]*/?>\s*</a>\s*$~is', $html, $match)) return '';
        $href = html_entity_decode($match[2], ENT_QUOTES, 'UTF-8');
        $src = html_entity_decode($match[4], ENT_QUOTES, 'UTF-8');
        if (!self::isFlagCounterUrl($href) || !self::isFlagCounterUrl($src)) return '';
        return '<a href="' . self::escape($href) . '" rel="noopener noreferrer nofollow"><img src="' . self::escape($src) . '" alt="Flag Counter" loading="lazy"></a>';
    }

    /** @param mixed $value @return array<string,array<string,string>> */
    private static function sanitizeOverrides(mixed $value): array
    {
        $result = [];
        if (!is_array($value)) return $result;
        foreach (self::LANGUAGES as $language) {
            $row = is_array($value[$language] ?? null) ? $value[$language] : [];
            foreach (self::OVERRIDE_KEYS as $key) {
                $multiline = in_array($key, ['banner_text', 'necessary_desc'], true);
                $max = $multiline ? 4000 : 300;
                $result[$language][$key] = self::plain((string) ($row[$key] ?? ''), $max, $multiline);
            }
        }
        return $result;
    }

    /** @param mixed $value @return array<string,list<array<string,string>>> */
    private static function sanitizeNecessaryServices(mixed $value): array
    {
        $result = [];
        if (!is_array($value)) return $result;
        foreach (self::LANGUAGES as $language) {
            $rows = is_array($value[$language] ?? null) ? array_slice($value[$language], 0, 10) : [];
            $result[$language] = [];
            foreach ($rows as $row) {
                if (!is_array($row)) continue;
                $service = [];
                foreach (self::NECESSARY_KEYS as $key) {
                    $max = $key === 'policy_url' ? 1000 : 1500;
                    $service[$key] = $key === 'policy_url'
                        ? self::safeUrl((string) ($row[$key] ?? ''))
                        : self::plain((string) ($row[$key] ?? ''), $max, false);
                }
                if (implode('', $service) !== '') $result[$language][] = $service;
            }
        }
        return $result;
    }

    /** @param array<string,mixed> $legacy @return array<string,mixed> */
    private static function migrateLegacy(array $legacy): array
    {
        if (!isset($legacy['integration']) && !isset($legacy['cookie'])) return [];
        $mode = (string) ($legacy['integration']['mode'] ?? 'none');
        $categories = is_array($legacy['categories'] ?? null) ? $legacy['categories'] : [];
        return [
            'test_mode' => (bool) ($legacy['test_mode'] ?? false),
            'schema_version' => (string) ($legacy['policy_revision'] ?? '1.0'),
            'cookie' => ['name' => 'murconsent_preferences', 'days' => (int) ($legacy['cookie']['days'] ?? 365)],
            'operator_name' => (string) ($legacy['operator']['name'] ?? ''),
            'policy_urls' => (array) ($legacy['policy_urls'] ?? []),
            'google' => [
                'ga4' => ['enabled' => $mode === 'ga4', 'id' => (string) ($legacy['integration']['ga4_measurement_id'] ?? '')],
                'ads' => ['enabled' => false, 'id' => '', 'label' => ''],
                'gtm' => [
                    'enabled' => $mode === 'gtm',
                    'id' => (string) ($legacy['integration']['gtm_container_id'] ?? ''),
                    'analytics' => in_array('analytics', (array) ($legacy['integration']['gtm_categories'] ?? []), true),
                    'marketing' => in_array('marketing', (array) ($legacy['integration']['gtm_categories'] ?? []), true),
                ],
            ],
            'appearance' => [
                'banner_width' => (($legacy['appearance']['desktop_width'] ?? '') === 'container') ? 'container' : 'full',
                'container_width' => (int) ($legacy['appearance']['container_max_width'] ?? 1180),
            ],
            'reload_on_withdraw' => (bool) ($legacy['integration']['reload_on_withdraw'] ?? true),
            'content_overrides' => [],
            'necessary_services' => [],
        ];
    }

    private static function isFlagCounterUrl(string $url): bool
    {
        if (!filter_var($url, FILTER_VALIDATE_URL)) return false;
        $parts = parse_url($url);
        if (!isset($parts['scheme'], $parts['host']) || !in_array(strtolower((string) $parts['scheme']), ['http', 'https'], true)) return false;
        $host = strtolower((string) $parts['host']);
        return $host === 'flagcounter.com' || str_ends_with($host, '.flagcounter.com');
    }

    private static function plain(string $value, int $max, bool $multiline = false): string
    {
        $value = strip_tags($value);
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        if (!$multiline) $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
        $value = trim($value);
        return function_exists('mb_substr') ? mb_substr($value, 0, $max, 'UTF-8') : substr($value, 0, $max);
    }

    /** @return array<string,mixed> */
    private static function requireArray(string $path): array
    {
        if (!is_file($path)) return [];
        $value = require $path;
        return is_array($value) ? $value : [];
    }

    /** @param array<string,mixed> $base @param array<string,mixed> $override @return array<string,mixed> */
    private static function merge(array $base, array $override): array
    {
        foreach ($override as $key => $value) {
            if (is_array($value) && is_array($base[$key] ?? null) && !array_is_list($value) && !array_is_list($base[$key])) {
                $base[$key] = self::merge($base[$key], $value);
            } else {
                $base[$key] = $value;
            }
        }
        return $base;
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
