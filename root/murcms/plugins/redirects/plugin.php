<?php
declare(strict_types=1);

if (!defined('MUR_CMS_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('No direct script access allowed.');
}

use Mur\Core\PluginContext;
use MurCMS\RedirectsPlugin\RedirectsPlugin;

require_once __DIR__ . '/src/RedirectsPlugin.php';

return static function (PluginContext $context): void {
    (new RedirectsPlugin($context))->register();
};
