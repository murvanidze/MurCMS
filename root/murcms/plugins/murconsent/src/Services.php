<?php

declare(strict_types=1);

namespace MurCMS\MurConsent;

if (!defined('MUR_CMS_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('No direct script access allowed.');
}

final class Services
{
    /** @return array<string,string> */
    public static function translations(string $pluginPath, array $runtime, string $language): array
    {
        $language = LanguageResolver::normalize($language);
        $file = $pluginPath . '/languages/' . $language . '.php';
        if (!is_file($file)) $file = $pluginPath . '/languages/en.php';
        $text = require $file;
        if (!is_array($text)) $text = [];
        $overrides = is_array($runtime['contentOverrides'][$language] ?? null) ? $runtime['contentOverrides'][$language] : [];
        foreach (Config::OVERRIDE_KEYS as $key) {
            if (isset($overrides[$key]) && trim((string) $overrides[$key]) !== '') {
                $text[$key] = (string) $overrides[$key];
            }
        }
        return array_map(static fn ($value): string => is_scalar($value) ? (string) $value : '', $text);
    }

    /** @return list<array<string,string>> */
    public static function necessary(array $runtime, array $text, string $language): array
    {
        $custom = is_array($runtime['necessaryServices'][$language] ?? null) ? $runtime['necessaryServices'][$language] : [];
        if ($custom !== []) return array_values($custom);
        $operator = trim((string) ($runtime['operatorName'] ?? ''));
        if ($operator === '') $operator = (string) ($text['website_operator'] ?? 'Website operator');
        return [[
            'name' => 'MurConsent preferences',
            'provider' => $operator,
            'purpose' => (string) ($text['service_preferences_purpose'] ?? ''),
            'domains' => (string) ($text['current_site'] ?? ''),
            'storage' => (string) ($runtime['cookieName'] ?? 'murconsent_preferences'),
            'retention' => sprintf((string) ($text['retention_days'] ?? 'Up to %d days'), (int) ($runtime['days'] ?? 365)),
            'policy_url' => (string) ($runtime['policyUrls'][$language] ?? ''),
        ]];
    }

    /** @return array{necessary:list<array<string,string>>,analytics:list<array<string,string>>,marketing:list<array<string,string>>,flag_counter:list<array<string,string>>} */
    public static function active(array $runtime, array $text, string $language): array
    {
        $registry = self::registry($runtime, $text, $language);
        $active = [
            'necessary' => self::necessary($runtime, $text, $language),
            'analytics' => [],
            'marketing' => [],
            'flag_counter' => [],
        ];
        if (($runtime['ga4Enabled'] ?? false) === true) self::addUnique($active['analytics'], $registry['google_analytics']);
        if (($runtime['adsEnabled'] ?? false) === true) self::addUnique($active['marketing'], $registry['google_ads']);
        if (($runtime['gtmAnalyticsEnabled'] ?? false) === true) {
            self::addUnique($active['analytics'], $registry['google_tag_manager']);
            self::addUnique($active['analytics'], $registry['google_analytics']);
        }
        if (($runtime['gtmMarketingEnabled'] ?? false) === true) {
            self::addUnique($active['marketing'], $registry['google_tag_manager']);
            self::addUnique($active['marketing'], $registry['google_ads']);
        }
        if (($runtime['flagCounterEnabled'] ?? false) === true) $active['flag_counter'][] = $registry['flag_counter'];
        return $active;
    }

    /** @return array<string,array<string,string>> */
    private static function registry(array $runtime, array $text, string $language): array
    {
        $policy = (string) ($runtime['policyUrls'][$language] ?? '');
        return [
            'google_analytics' => [
                'id' => 'google_analytics', 'name' => 'Google Analytics 4', 'provider' => 'Google',
                'purpose' => (string) ($text['service_analytics_purpose'] ?? ''),
                'domains' => 'googletagmanager.com, google-analytics.com', 'storage' => '_ga, _ga_*',
                'retention' => (string) ($text['retention_google'] ?? ''), 'policy_url' => 'https://policies.google.com/privacy',
            ],
            'google_ads' => [
                'id' => 'google_ads', 'name' => 'Google Ads conversion tracking', 'provider' => 'Google',
                'purpose' => (string) ($text['service_ads_purpose'] ?? ''),
                'domains' => 'googletagmanager.com, googleadservices.com, doubleclick.net', 'storage' => '_gcl_*, _gac_*',
                'retention' => (string) ($text['retention_google'] ?? ''), 'policy_url' => 'https://policies.google.com/privacy',
            ],
            'google_tag_manager' => [
                'id' => 'google_tag_manager', 'name' => 'Google Tag Manager', 'provider' => 'Google',
                'purpose' => (string) ($text['service_gtm_purpose'] ?? ''), 'domains' => 'googletagmanager.com',
                'storage' => (string) ($text['service_gtm_storage'] ?? ''),
                'retention' => (string) ($text['retention_container'] ?? ''), 'policy_url' => 'https://policies.google.com/privacy',
            ],
            'flag_counter' => [
                'id' => 'flag_counter', 'name' => 'Flag Counter', 'provider' => 'Boardhost.com, Inc.',
                'purpose' => (string) ($text['service_flag_purpose'] ?? ''), 'domains' => '*.flagcounter.com',
                'storage' => (string) ($text['service_flag_storage'] ?? ''),
                'retention' => (string) ($text['retention_flag'] ?? ''), 'policy_url' => 'https://www.flagcounter.com/privacy.html',
            ],
            'murconsent' => [
                'id' => 'murconsent', 'name' => 'MurConsent', 'provider' => (string) ($runtime['operatorName'] ?? ''),
                'purpose' => (string) ($text['service_preferences_purpose'] ?? ''), 'domains' => (string) ($text['current_site'] ?? ''),
                'storage' => (string) ($runtime['cookieName'] ?? ''), 'retention' => sprintf((string) ($text['retention_days'] ?? '%d'), (int) ($runtime['days'] ?? 365)), 'policy_url' => $policy,
            ],
        ];
    }

    /** @param list<array<string,string>> $list @param array<string,string> $service */
    private static function addUnique(array &$list, array $service): void
    {
        foreach ($list as $existing) {
            if (($existing['id'] ?? '') === ($service['id'] ?? '')) return;
        }
        $list[] = $service;
    }
}
