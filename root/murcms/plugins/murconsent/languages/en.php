<?php
declare(strict_types=1);

if (!defined('MUR_CMS_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('No direct script access allowed.');
}

return array(
    'banner_title' => 'Your privacy',
    'banner_text' => 'This website uses optional analytics, advertising conversion measurement and external statistics services. They are not loaded before you give consent. You may accept all optional services, reject them or configure each category separately.',
    'accept_all' => 'Accept all',
    'reject_all' => 'Reject all',
    'customize' => 'Customize',
    'save' => 'Save settings',
    'settings_title' => 'Privacy settings',
    'settings_intro' => 'Choose the optional categories you allow. You may change or withdraw consent at any time using the “Privacy settings” link on the website.',
    'close' => 'Close settings',
    'saved' => 'Your settings have been saved.',
    'privacy_policy' => 'Privacy policy',
    'necessary_title' => 'Necessary',
    'necessary_desc' => 'Support MurConsent operation and remember the privacy settings you choose.',
    'always_on' => 'Always active',
    'analytics_title' => 'Analytics',
    'analytics_toggle' => 'Allow analytics',
    'analytics_desc' => 'Allows traffic and page usage to be measured so the website can be evaluated and improved.',
    'marketing_title' => 'Marketing and conversion measurement',
    'marketing_toggle' => 'Allow advertising conversion measurement',
    'marketing_desc' => 'Allows an advertising visit to be linked to a valuable website action, such as a phone or messenger click.',
    'flag_title' => 'External statistics — Flag Counter',
    'flag_toggle' => 'Allow Flag Counter',
    'flag_desc' => 'Loads an external visitor-country counter. Requesting the image sends ordinary technical connection data to the provider.',
    'service_details' => 'Service details',
    'provider_label' => 'Provider',
    'purpose_label' => 'Purpose',
    'domains_label' => 'Domains',
    'storage_label' => 'Cookies and storage',
    'retention_label' => 'Retention',
    'provider_policy' => 'Provider privacy policy',
    'website_operator' => 'Website operator',
    'current_site' => 'Current website',
    'retention_days' => 'Up to %d days',
    'retention_google' => 'Depends on service configuration and the website operator’s policy',
    'retention_container' => 'Determined by the services loaded through the container',
    'retention_flag' => 'Under the Flag Counter policy; returning-visitor technical filter — up to 7 days',
    'service_preferences_purpose' => 'Remembering the privacy settings selected by the visitor',
    'service_analytics_purpose' => 'Website traffic and usage statistics',
    'service_ads_purpose' => 'Advertising effectiveness and conversion measurement',
    'service_gtm_purpose' => 'Technical loading of permitted analytics or marketing tags',
    'service_gtm_storage' => 'The container normally sets no cookies itself; tags inside it may create storage',
    'service_flag_purpose' => 'Counting visits by country and displaying external statistics',
    'service_flag_storage' => 'MurConsent does not create a cookie for Flag Counter; loading the image sends the provider the IP address, referrer and User-Agent'
);
