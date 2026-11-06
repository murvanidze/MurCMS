<?php
declare(strict_types=1);

namespace Mur\Core;

if (!defined('MUR_CMS_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('No direct script access allowed.');
}

final class TemplateManager
{
    public function __construct(
        private readonly string $templatesPath,
        private readonly array $config,
        private readonly array $site,
        private readonly array $languages,
        private readonly ContentRepository $content,
        private readonly Menu $menu,
        private readonly Hooks $hooks,
    ) {
    }

    public function load(): FrontendTemplate
    {
        $name = self::resolvedName($this->templatesPath, $this->config);
        $path = $this->templatesPath . '/' . $name;
        $file = $path . '/template.php';

        if (!is_file($file)) {
            $name = 'default';
            $path = $this->templatesPath . '/default';
            $file = $path . '/template.php';
        }

        if (!is_file($file)) {
            throw new \RuntimeException('Frontend template is missing: ' . $name);
        }

        $templateConfig = (array) (($this->config['templates'][$name] ?? null) ?: []);
        $context = new TemplateContext(
            $name,
            $path,
            $templateConfig,
            $this->site,
            $this->languages,
            $this->content,
            $this->menu,
            $this->hooks,
        );
        $factory = require $file;
        $template = is_callable($factory) ? $factory($context) : null;

        if (!$template instanceof FrontendTemplate) {
            throw new \RuntimeException('Invalid frontend template factory: ' . $name);
        }

        return $template;
    }

    public function available(): array
    {
        $items = [];

        foreach (glob($this->templatesPath . '/*/template.php', GLOB_NOSORT) ?: [] as $file) {
            $name = basename(dirname($file));
            if ($this->safeName($name) === $name) {
                $items[] = $name;
            }
        }

        sort($items, SORT_NATURAL);

        return $items;
    }

    public static function resolvedName(string $templatesPath, array $config): string
    {
        $name = strtolower(trim((string) ($config['active'] ?? 'default')));
        return preg_match('/^[a-z0-9][a-z0-9_-]*$/D', $name) && is_file($templatesPath . '/' . $name . '/template.php')
            ? $name : 'default';
    }

    private function safeName(string $name): string
    {
        $name = strtolower(trim($name));

        return preg_match('/^[a-z0-9][a-z0-9_-]*$/', $name) ? $name : 'default';
    }
}
