<?php
declare(strict_types=1);

namespace MurCMS\BanerinaPlugin;

if (!defined('MUR_CMS_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('No direct script access allowed.');
}

use Mur\Core\PluginContext;

final class ConfigStore
{
    private string $configPath;
    private array $defaults;
    private array $config;
    private string $loadProblem = '';

    public function __construct(
        private readonly PluginContext $context,
        string $pluginPath,
    ) {
        $defaults = require $pluginPath . '/defaults.php';
        $this->defaults = is_array($defaults) ? $defaults : [];
        $this->configPath = $context->path('config/banerina.php');
        $this->config = $this->normalize(array_replace_recursive($this->defaults, $this->readRuntime()));
    }

    public function config(): array
    {
        return $this->config;
    }

    public function settings(): array
    {
        return is_array($this->config['settings'] ?? null) ? $this->config['settings'] : [];
    }

    public function groups(): array
    {
        return is_array($this->config['groups'] ?? null) ? $this->config['groups'] : [];
    }

    public function loadProblem(): string
    {
        return $this->loadProblem;
    }

    public function replace(array $config): void
    {
        $normalized = $this->normalize(array_replace_recursive($this->defaults, $config));
        $this->write($normalized);
        $this->config = $normalized;
        $this->loadProblem = '';
    }

    private function readRuntime(): array
    {
        if (!is_file($this->configPath)) {
            return [];
        }

        if (!is_readable($this->configPath)) {
            $this->loadProblem = 'unreadable';
            return [];
        }

        try {
            $config = @require $this->configPath;
        } catch (\Throwable) {
            $this->loadProblem = 'invalid';
            return [];
        }

        if (!is_array($config)) {
            $this->loadProblem = 'invalid';
            return [];
        }

        return $config;
    }

    private function normalize(array $config): array
    {
        $settings = is_array($config['settings'] ?? null) ? $config['settings'] : [];
        $groups = is_array($config['groups'] ?? null) ? $config['groups'] : [];
        $normalizedGroups = [];

        foreach ($groups as $key => $group) {
            if (!is_array($group)) {
                continue;
            }

            $id = trim((string) ($group['id'] ?? $key));
            if ($id === '') {
                $id = trim((string) $key);
            }

            $snippets = array_values(array_filter(
                array_map(static fn (mixed $value): string => trim((string) $value), (array) ($group['snippets'] ?? [])),
                static fn (string $value): bool => $value !== '',
            ));

            $normalizedGroups[$id] = [
                'id' => $id,
                'name' => trim((string) ($group['name'] ?? $id)),
                'description' => trim((string) ($group['description'] ?? '')),
                'active' => ($group['active'] ?? false) === true,
                'snippets' => $snippets,
            ];
        }

        ksort($normalizedGroups, SORT_NATURAL | SORT_FLAG_CASE);

        return [
            'settings' => [
                'avoid_duplicates' => ($settings['avoid_duplicates'] ?? true) === true,
                'selection_mode' => 'random',
                'diagnostics_logging' => ($settings['diagnostics_logging'] ?? false) === true,
            ],
            'groups' => $normalizedGroups,
        ];
    }

    private function write(array $config): void
    {
        $directory = dirname($this->configPath);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException('Unable to create Banerina config directory.');
        }

        $this->backup();
        $php = "<?php\ndeclare(strict_types=1);\n\n"
            . "if (!defined('MUR_CMS_BOOTSTRAPPED')) {\n"
            . "    http_response_code(403);\n"
            . "    exit('No direct script access allowed.');\n"
            . "}\n\nreturn " . var_export($config, true) . ";\n";
        $tmp = $this->configPath . '.tmp-' . bin2hex(random_bytes(6));

        if (file_put_contents($tmp, $php, LOCK_EX) === false) {
            throw new \RuntimeException('Unable to write temporary Banerina config.');
        }

        if (!@rename($tmp, $this->configPath)) {
            @unlink($tmp);
            throw new \RuntimeException('Unable to atomically replace Banerina config.');
        }

        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($this->configPath, true);
        }
    }

    private function backup(): void
    {
        if (!is_file($this->configPath)) {
            return;
        }

        $directory = $this->context->path('storage/backups/banerina');
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException('Unable to create Banerina backup directory.');
        }

        $target = $directory . '/banerina-' . date('Ymd-His') . '-' . bin2hex(random_bytes(2)) . '.php';
        if (!copy($this->configPath, $target)) {
            throw new \RuntimeException('Unable to create Banerina config backup.');
        }

        $files = glob($directory . '/banerina-*.php') ?: [];
        usort($files, static fn (string $a, string $b): int => (filemtime($b) ?: 0) <=> (filemtime($a) ?: 0));
        foreach (array_slice($files, 5) as $file) {
            @unlink($file);
        }
    }
}
