<?php
declare(strict_types=1);

if (!defined('MUR_CMS_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('No direct script access allowed.');
}

return [
    'settings' => [
        'avoid_duplicates' => true,
        'selection_mode' => 'random',
        'diagnostics_logging' => false,
    ],
    'groups' => [],
];
