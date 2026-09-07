<?php
declare(strict_types=1);

namespace Mur\Core;

if (!defined('MUR_CMS_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('No direct script access allowed.');
}

final class PluginContext
{
    public function __construct(
        public readonly Hooks $hooks,
        public readonly array $site,
        public readonly array $languages,
        public readonly string $basePath,
        public readonly string $pluginsPath,
        public readonly ContentRepository $content,
    ) {
    }

    public function path(string $relativePath = ''): string
    {
        return $this->basePath . ($relativePath !== '' ? '/' . ltrim($relativePath, '/\\') : '');
    }

    public function registerAdminRoute(array $route): void
    {
        $this->hooks->register('admin.route', $route);
    }
}
