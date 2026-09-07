<?php
declare(strict_types=1);

if (!defined('MUR_CMS_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('No direct script access allowed.');
}

use Mur\Core\FrontendTemplate;
use Mur\Core\TemplateContext;

require_once __DIR__ . '/DefaultTemplate.php';

return static fn (TemplateContext $context): FrontendTemplate => new Mur\Templates\DefaultTemplate($context);
