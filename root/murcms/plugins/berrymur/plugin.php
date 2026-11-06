<?php
declare(strict_types=1);

use Mur\Core\PluginContext;

if (!defined('MUR_CMS_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('No direct script access allowed.');
}

return static function (PluginContext $context): void {
    $cssUrl = mur_asset_url('plugins/berrymur/berry.scoped.css');
    $loaderUrl = mur_asset_url('plugins/berrymur/berrymur-loader.js');

    $context->hooks->add(
        'layout.head',
        static function (string $html) use ($cssUrl): string {
            return $html
                . "\n  <noscript>"
                . '<link rel="stylesheet" href="' . mur_escape($cssUrl) . '">'
                . '</noscript>';
        },
        20,
    );

    $context->hooks->add(
        'layout.body_end',
        static function (string $html) use ($cssUrl, $loaderUrl): string {
            return $html
                . "\n  "
                . '<script'
                . ' id="murcms-berrymur-loader"'
                . ' src="' . mur_escape($loaderUrl) . '"'
                . ' data-berrymur-css="' . mur_escape($cssUrl) . '"'
                . ' data-berrymur-scope=".mur-berry"'
                . ' defer'
                . '></script>';
        },
        20,
    );
};
