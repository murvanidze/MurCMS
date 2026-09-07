<?php
declare(strict_types=1);

if (!defined('MUR_CMS_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('No direct script access allowed.');
}

return [
    'default' => 'ru',
    'available' => ['ru', 'ro', 'en', 'uk'],
    'enabled' => ['ru', 'ro'],
    'structure' => 'flexible',
    'labels' => [
        'ru' => 'RU',
        'ro' => 'RO',
        'en' => 'EN',
        'uk' => 'UK',
    ],
];
