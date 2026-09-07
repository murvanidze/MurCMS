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
