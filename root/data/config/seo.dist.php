<?php
declare(strict_types=1);

if (!defined('MUR_CMS_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('No direct script access allowed.');
}

return [
    'sitemap' => [
        'enabled' => true,
        'include_home' => true,
        'include_documents' => true,
        'include_lastmod' => false,
    ],
    'robots' => [
        'content' => "User-agent: *\nDisallow: /admin/\nDisallow: /data/\nDisallow: /murcms/app/\nDisallow: /murcms/admin/\nDisallow: /murcms/plugins/\n\nSitemap: {SITEMAP_URL}\n",
    ],
];
