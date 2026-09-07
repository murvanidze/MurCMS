<?php

declare(strict_types=1);

namespace MurCMS\MurTextPlugin;

use Mur\Core\PluginContext;

if (!defined('MUR_CMS_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('No direct script access allowed.');
}

/**
 * Loads, normalizes, migrates and saves MurText runtime configuration.
 */
final class ConfigStore
{
    private array $defaults;
    private array $config;
    private string $source = 'defaults';
    private ?string $lastError = null;
    /** @var list<string> */
    private array $warnings = [];

    public function __construct(
        private readonly PluginContext $context,
        private readonly string $pluginPath,
    ) {
        $this->defaults = $this->loadArray($this->defaultPath()) ?? [];
        $this->config = $this->loadInitialConfig();
    }

    public function config(): array
    {
        return $this->config;
    }

    public function source(): string
    {
        return $this->source;
    }

    public function lastError(): ?string
    {
        return $this->lastError;
    }

    /** @return list<string> */
    public function warnings(): array
    {
        return $this->warnings;
    }

    public function defaultPath(): string
    {
        return $this->pluginPath . '/defaults.php';
    }

    public function legacyPath(): string
    {
        return $this->pluginPath . '/config.php';
    }

    public function runtimePath(): string
    {
        return $this->context->path('config/murtext.php');
    }

    public function backupPath(): string
    {
        return $this->context->path('storage/backups/murtext');
    }

    public function save(array $config): bool
    {
        $this->lastError = null;
        $normalized = $this->normalize($config);

        if (!$this->ensureDirectory(dirname($this->runtimePath()))) {
            return false;
        }

        if (is_file($this->runtimePath())) {
            $this->backupCurrentConfig();
        }

        if (!$this->atomicWrite($this->runtimePath(), $this->exportConfig($normalized))) {
            return false;
        }

        $this->config = $normalized;
        $this->source = 'runtime';
        return true;
    }

    private function loadInitialConfig(): array
    {
        $runtime = $this->loadArray($this->runtimePath());
        if ($runtime !== null) {
            $this->source = 'runtime';
            return $this->normalize($runtime);
        }

        $legacy = $this->loadArray($this->legacyPath());
        if ($legacy !== null) {
            $normalized = $this->normalize($legacy);
            if ($this->save($normalized)) {
                $this->source = 'migrated';
                $this->warnings[] = 'Настройки MurText были перенесены из legacy config.php в data/config/murtext.php. Legacy-файл не удалён.';
            } else {
                $this->source = 'legacy';
                $this->warnings[] = 'MurText использует legacy config.php, потому что data/config/murtext.php пока не удалось создать: ' . ($this->lastError ?? 'unknown error');
                $this->config = $normalized;
            }
            return $normalized;
        }

        $this->source = 'defaults';
        return $this->normalize([]);
    }

    private function normalize(array $config): array
    {
        $merged = $this->mergeKnown($this->defaults, $config);
        $merged['config_version'] = 2;
        $merged['parser']['dialect'] = Version::DIALECT;

        return $merged;
    }

    private function mergeKnown(array $defaults, array $override): array
    {
        $result = $defaults;
        foreach ($defaults as $key => $defaultValue) {
            if (!array_key_exists($key, $override)) {
                continue;
            }

            $value = $override[$key];
            if (is_array($defaultValue) && is_array($value)) {
                $result[$key] = $this->mergeKnown($defaultValue, $value);
                continue;
            }

            if ($this->compatibleType($defaultValue, $value)) {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    private function compatibleType(mixed $default, mixed $value): bool
    {
        return match (gettype($default)) {
            'boolean' => is_bool($value),
            'integer' => is_int($value),
            'double' => is_float($value) || is_int($value),
            'string' => is_string($value),
            'array' => is_array($value),
            default => true,
        };
    }

    private function loadArray(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }

        try {
            $value = require $path;
        } catch (\Throwable $e) {
            $this->warnings[] = 'Не удалось загрузить конфигурацию MurText: ' . $path . ' — ' . $e->getMessage();
            return null;
        }

        if (!is_array($value)) {
            $this->warnings[] = 'Конфигурация MurText проигнорирована, потому что файл не возвращает массив: ' . $path;
            return null;
        }

        return $value;
    }

    private function exportConfig(array $config): string
    {
        return "<?php\n\ndeclare(strict_types=1);\n\nif (!defined('MUR_CMS_BOOTSTRAPPED')) {\n    http_response_code(403);\n    exit('No direct script access allowed.');\n}\n\nreturn " . var_export($config, true) . ";\n";
    }

    private function atomicWrite(string $path, string $content): bool
    {
        $directory = dirname($path);
        $tmp = $directory . '/.' . basename($path) . '.' . bin2hex(random_bytes(4)) . '.tmp';
        $written = @file_put_contents($tmp, $content, LOCK_EX);

        if ($written === false) {
            @unlink($tmp);
            $this->lastError = 'Не удалось записать временный файл ' . $tmp;
            return false;
        }

        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            $this->lastError = 'Не удалось заменить файл ' . $path;
            return false;
        }

        return true;
    }

    private function ensureDirectory(string $path): bool
    {
        if (is_dir($path)) {
            return true;
        }

        if (@mkdir($path, 0775, true) || is_dir($path)) {
            return true;
        }

        $this->lastError = 'Не удалось создать каталог ' . $path;
        return false;
    }

    private function backupCurrentConfig(): void
    {
        if (!$this->ensureDirectory($this->backupPath())) {
            return;
        }

        $target = $this->backupPath() . '/murtext.' . date('Ymd-His') . '.php';
        @copy($this->runtimePath(), $target);
        $this->pruneBackups(10);
    }

    private function pruneBackups(int $keep): void
    {
        $files = glob($this->backupPath() . '/murtext.*.php') ?: [];
        rsort($files, SORT_STRING);

        foreach (array_slice($files, $keep) as $file) {
            @unlink($file);
        }
    }
}
