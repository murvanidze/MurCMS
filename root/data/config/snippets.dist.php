<?php
declare(strict_types=1);

if (!defined('MUR_CMS_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('No direct script access allowed.');
}

return [
    // PHP snippets are admin-only but still execute with the web server privileges.
    // Keep the distribution safe by default; enable this explicitly per site.
    'allow_php' => false,
];
