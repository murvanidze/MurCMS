<?php

declare(strict_types=1);

if (!defined('MUR_CMS_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('No direct script access allowed.');
}

return [
    'config_version' => 2,
    'default_enabled' => false,
    'scopes' => [
        'pages' => true,
        'categories' => true,
        'home' => true,
        'footer' => true,
        'snippet' => true,
    ],
    'parser' => [
        'dialect' => 'murtext-1',
        'paragraph_mode' => 'blank_lines',
        'pretty' => false,
        'strict' => false,
        'hard_break_spaces' => true,
        'hard_break_backslash' => true,
        'hard_break_command' => true,
        'blockquote_join' => 'br',
        'image_loading' => 'lazy',
        'image_decoding' => 'async',
        'site_url' => '',
        'uploads_url' => '',
        'pages_url' => '',
    ],
    'errors' => [
        'policy' => 'render',
        'log' => 'off',
    ],
    'editor' => [
        'mode' => 'help',
        'document_overrides' => true,
        'surfaces' => [
            'document' => true,
            'snippet' => true,
            'content_blocks' => true,
        ],
    ],
];
