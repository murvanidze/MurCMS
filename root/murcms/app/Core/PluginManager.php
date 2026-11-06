<?php
declare(strict_types=1);

namespace Mur\Core;

if (!defined('MUR_CMS_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('No direct script access allowed.');
}

final class PluginManager
{
    private array $loaded = [];

    public function __construct(
        private readonly string $pluginsPath,
        private readonly array $config,
        private readonly PluginContext $context,
    ) {
    }

    public function loadEnabled(): void
    {
        foreach (($this->config['enabled'] ?? []) as $plugin) {
            $this->load((string) $plugin);
        }
    }

    public function loaded(): array
    {
        return $this->loaded;
    }

    public function metadata(string $name): array
    {
        if (!preg_match('/^[a-z0-9][a-z0-9_-]*$/D', $name)) {
            return [];
        }
        $file = $this->pluginsPath . '/' . $name . '/info.json';
        if (!is_file($file) || is_link($file) || filesize($file) > 65536) {
            return [];
        }
        try {
            $info = json_decode((string) @file_get_contents($file), true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($info)) {
                return [];
            }
            return array_filter(array_intersect_key($info, array_flip(['name', 'version', 'description', 'author', 'website', 'license'])), 'is_string');
        } catch (\Throwable) {
            return [];
        }
    }

    private function load(string $name): void
    {
        if (!preg_match('/^[a-z0-9][a-z0-9_-]*$/', $name)) {
            return;
        }

        $file = $this->pluginsPath . '/' . $name . '/plugin.php';

        if (!is_file($file)) {
            return;
        }

        $plugin = require $file;

        if (is_callable($plugin)) {
            $plugin($this->context);
            $this->loaded[] = $name;
            return;
        }

        if (is_array($plugin) && is_callable($plugin['register'] ?? null)) {
            $plugin['register']($this->context);
            $this->loaded[] = $name;
        }
    }
}
