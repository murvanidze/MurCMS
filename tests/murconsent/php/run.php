<?php

declare(strict_types=1);

define('MUR_CMS_BOOTSTRAPPED', true);

$base = dirname(__DIR__, 3) . '/root/murcms/plugins/murconsent';
require $base . '/src/LanguageResolver.php';
require $base . '/src/Config.php';
require $base . '/src/Services.php';
require $base . '/src/FrontendRenderer.php';

use MurCMS\MurConsent\Config;
use MurCMS\MurConsent\FrontendRenderer;
use MurCMS\MurConsent\LanguageResolver;
use MurCMS\MurConsent\Services;

$passed = 0;
$assert = static function (bool $condition, string $message) use (&$passed): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    $passed++;
};

$assert(LanguageResolver::normalize('RO-RO') === 'ro', 'RO regional language');
$assert(LanguageResolver::normalize('ru_MD') === 'ru', 'RU underscore language');
$assert(LanguageResolver::normalize('EN-us') === 'en', 'EN regional language');
$assert(LanguageResolver::normalize('de-DE') === 'en', 'unknown language fallback');
$assert(Config::validGoogleId('ga4', 'G-ABCD1234'), 'valid GA4 ID');
$assert(!Config::validGoogleId('ga4', 'UA-123'), 'invalid legacy Analytics ID');
$assert(Config::validGoogleId('ads', 'AW-123456789'), 'valid Ads ID');
$assert(Config::validGoogleId('gtm', 'GTM-ABC1234'), 'valid GTM ID');
$assert(Config::safeUrl('javascript:alert(1)') === '', 'javascript URL rejected');
$assert(Config::safeUrl('privacy') === '/privacy', 'relative policy URL normalized');

$defaults = require $base . '/defaults.php';
$runtime = Config::normalize($defaults);
$assert(!Config::isReady($runtime), 'defaults have no optional service');
$assert($runtime['cookieName'] === 'murconsent_preferences', 'default cookie name');
$assert($runtime['days'] === 365, 'default decision period');

$config = $defaults;
$config['google']['ga4'] = ['enabled' => true, 'id' => 'G-ABCD1234'];
$config['google']['ads'] = ['enabled' => true, 'id' => 'AW-123456789', 'label' => 'LABEL_1'];
$config['google']['gtm'] = ['enabled' => true, 'id' => 'GTM-ABC1234', 'analytics' => false, 'marketing' => true];
$runtime = Config::normalize($config);
$assert(Config::isReady($runtime), 'configured runtime is ready');
$assert($runtime['capabilities']['analytics'] === true, 'analytics capability');
$assert($runtime['capabilities']['marketing'] === true, 'marketing capability');
$assert($runtime['gtmAnalyticsEnabled'] === false, 'GTM analytics classification');
$assert($runtime['gtmMarketingEnabled'] === true, 'GTM marketing classification');

$text = Services::translations($base, $runtime, 'ru-MD');
$assert(($text['banner_title'] ?? '') !== '', 'RU translations loaded');
$necessary = Services::necessary($runtime, $text, 'ru');
$assert(($necessary[0]['storage'] ?? '') === 'murconsent_preferences', 'necessary service discloses cookie');
$active = Services::active($runtime, $text, 'ru');
$assert(count($active['analytics']) === 1, 'direct GA4 service shown');
$assert(count($active['marketing']) === 2, 'GTM and Ads services deduplicated in marketing');

$html = FrontendRenderer::render($base, $runtime, 'ru');
$assert(str_contains($html, 'data-murconsent-accept-all'), 'accept action rendered');
$assert(str_contains($html, 'data-murconsent-reject-all'), 'reject action rendered');
$assert(str_contains($html, 'data-murconsent-customize'), 'customize action rendered');
$assert(str_contains($html, 'murconsent-settings-icon'), 'local gear SVG rendered');
$assert(!str_contains($html, 'Font Awesome'), 'no Font Awesome dependency');
$assert(str_contains($html, 'data-murconsent-choice="analytics"'), 'analytics category rendered');
$assert(str_contains($html, 'data-murconsent-choice="marketing"'), 'marketing category rendered');
$assert(!str_contains($html, 'data-murconsent-choice="flag-counter"'), 'inactive Flag Counter hidden');
$assert(!str_contains($html, 'murconsent__'), 'MurCMS public class naming does not use BEM double underscore');

$safeFlag = Config::sanitizeFlagCounterHtml('<a href="https://info.flagcounter.com/abc"><img src="https://s11.flagcounter.com/count2/abc/bg_FFFFFF/txt_000000/border_CCCCCC/columns_2/maxflags_12/viewers_0/labels_0/pageviews_0/flags_0/percent_0/"></a>');
$assert($safeFlag !== '', 'valid Flag Counter accepted');
$assert(str_contains($safeFlag, 'rel="noopener noreferrer nofollow"'), 'Flag Counter safe rel enforced');
$assert(Config::sanitizeFlagCounterHtml('<script>alert(1)</script>') === '', 'unsafe Flag Counter rejected');
$assert(Config::sanitizeFlagCounterHtml('<a href="https://evil.example"><img src="https://s11.flagcounter.com/count2/x"></a>') === '', 'non-Flag Counter link rejected');

$post = [
    'schema_version' => '2.0', 'cookie_name' => 'custom_cookie', 'decision_days' => '180',
    'operator_name' => 'Operator', 'policy_urls' => ['ro' => '/ro/p', 'ru' => '/ru/p', 'en' => '/en/p'],
    'ga4_enabled' => '1', 'ga4_id' => 'G-ABCD1234', 'gtm_enabled' => '1', 'gtm_id' => 'GTM-ABC1234', 'gtm_marketing' => '1',
    'banner_width' => 'full', 'container_width' => '1400', 'auto_footer_link' => '1', 'reload_on_withdraw' => '1',
    'content_overrides' => ['ru' => ['banner_title' => '<b>Заголовок</b>']],
    'necessary_services' => ['ru' => [['name' => 'Cookie', 'storage' => 'custom_cookie', 'policy_url' => 'javascript:bad']]],
];
$built = Config::fromPost($post, $defaults, $defaults);
$assert($built['cookie']['name'] === 'custom_cookie', 'POST cookie saved');
$assert($built['google']['ga4']['enabled'] === true, 'POST GA4 toggle saved');
$assert($built['google']['gtm']['marketing'] === true, 'POST GTM category saved');
$assert($built['content_overrides']['ru']['banner_title'] === 'Заголовок', 'HTML stripped from editable text');
$assert($built['necessary_services']['ru'][0]['policy_url'] === '', 'unsafe service URL removed');

fwrite(STDOUT, "PHP tests passed: {$passed}\n");
