<?php
declare(strict_types=1);

if (!defined('MUR_CMS_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('No direct script access allowed.');
}

return [
    'active' => 'default',
    'templates' => [
        'default' => [
            'options' => [
                'content_width' => 1040,
                'color_scheme' => 'light',
                'sticky_header' => true,
                'show_breadcrumbs' => true,
                'show_page_summary' => true,
                'load_menu_js' => true,
                'footer_blocks' => true,
                'footer_menu_enabled' => false,
                'footer_menu_name' => 'footer',
                'block_layout' => [
                    'home' => [
                        'ru' => ['hero', 'summary', 'home-showcase'],
                        'ro' => ['hero', 'summary', 'home-showcase'],
                    ],
                    'footer' => [
                        'ru' => ['footer-main'],
                        'ro' => ['footer-main'],
                    ],
                ],
                'brand_mode' => 'html',
                'brand_html' => '<span class="site-identity"><img class="site-icon" src="/murcms/assets/site-icon.svg" width="50" height="50" alt="" aria-hidden="true"><span>MurCMS</span></span>',
                'brand_image' => 'site-icon.svg',
                'brand_image_height' => 50,
                'list_defaults' => [
                    'children' => [
                        'view' => 'cards',
                        'sort' => 'order',
                        'direction' => 'asc',
                        'per_page' => '',
                        'position' => 'bottom',
                    ],
                    'category' => [
                        'view' => 'cards',
                        'sort' => 'order',
                        'direction' => 'asc',
                        'per_page' => '',
                        'position' => 'bottom',
                    ],
                ],
            ],
        ],
    ],
];
