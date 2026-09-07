<?php
declare(strict_types=1);

namespace Mur\Core;

if (!defined('MUR_CMS_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('No direct script access allowed.');
}

final class TemplateContext
{
    public function __construct(
        public readonly string $name,
        public readonly string $path,
        public readonly array $config,
        public readonly array $site,
        public readonly array $languages,
        public readonly ContentRepository $content,
        public readonly Menu $menu,
        public readonly Hooks $hooks,
    ) {
    }
}
