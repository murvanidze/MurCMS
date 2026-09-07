<?php

declare(strict_types=1);

if (!defined('MUR_CMS_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('No direct script access allowed.');
}

/**
 * Site-specific MurConsent configuration.
 * Copy this file to murconsent.php or save the settings in the MurCMS admin panel.
 */
return [
    'config_version' => 2,
    'test_mode' => false,
    'schema_version' => '1.0',
    'cookie' => [
        'name' => 'murconsent_preferences',
        'days' => 365,
    ],
    'operator_name' => '',
    'policy_urls' => [
        'ro' => '/ro/privacy',
        'ru' => '/ru/privacy',
        'en' => '/en/privacy',
    ],
    'google' => [
        'ga4' => ['enabled' => false, 'id' => ''],
        'ads' => ['enabled' => false, 'id' => '', 'label' => ''],
        'gtm' => ['enabled' => false, 'id' => '', 'analytics' => false, 'marketing' => false],
    ],
    'flag_counter' => ['enabled' => false, 'html' => ''],
    'appearance' => ['banner_width' => 'container', 'container_width' => 1180],
    'auto_footer_link' => true,
    'reload_on_withdraw' => true,
    'content_overrides' => [],
    'necessary_services' => [],
];
