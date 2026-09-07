<?php
declare(strict_types=1);

if (!defined('MUR_CMS_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('No direct script access allowed.');
}

return [
    'enabled' => [
        'snippets',
        'blocks',
        'gallery',
        'tags',
        'redirects',
        'murconsent',
        'murtext',
    ],
];
