<?php
declare(strict_types=1);

namespace Mur\Core;

if (!defined('MUR_CMS_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('No direct script access allowed.');
}

/** Trusted, site-owned PHP. Never used by the shared content file editor. */
final class SiteFunctions
{
    public function __construct(private readonly string $dataPath, private readonly string $template)
    {
        if (!preg_match('/^[a-z0-9][a-z0-9_-]*$/D', $template)) {
            throw new \InvalidArgumentException('Invalid template.');
        }
    }

    public function path(): string
    {
        return $this->dataPath . '/templates/' . $this->template . '/functions.php';
    }

    private function backupPath(): string
    {
        return $this->dataPath . '/storage/backups/site-functions/' . $this->template . '/previous.bak';
    }

    public function read(): string
    {
        $this->guardPath($this->path());
        return is_file($this->path()) ? (string) file_get_contents($this->path()) : "<?php\n// Site-owned frontend functions.\n";
    }

    public function hasBackup(): bool
    {
        $this->guardPath($this->backupPath());
        return is_file($this->backupPath());
    }

    public function save(string $code): void
    {
        $code = ltrim($code);
        if (strlen($code) > 262144 || !str_starts_with(ltrim($code), '<?php')) {
            throw new \RuntimeException('Начните файл с <?php. Максимальный размер: 256 KB.');
        }
        $tokens = token_get_all($code, TOKEN_PARSE);
        if (!is_array($tokens[0] ?? null) || $tokens[0][0] !== T_OPEN_TAG) {
            throw new \RuntimeException('Используйте полный открывающий тег <?php с пробелом или переносом строки.');
        }
        $this->guardPath($this->path());
        if (is_file($this->path())) {
            $this->atomicWrite($this->backupPath(), (string) file_get_contents($this->path()));
        }
        $this->atomicWrite($this->path(), $code);
    }

    public function restore(): void
    {
        if (!$this->hasBackup()) {
            throw new \RuntimeException('Предыдущая редакция отсутствует.');
        }
        // Swap revisions, allowing recovery even when the backup contains a runtime error.
        $old = (string) file_get_contents($this->backupPath());
        $current = $this->read();
        $this->atomicWrite($this->path(), $old);
        $this->atomicWrite($this->backupPath(), $current);
    }

    public function load(): void
    {
        $this->guardPath($this->path());
        if (is_file($this->path())) {
            require_once $this->path();
        }
    }

    private function guardPath(string $file): void
    {
        $base = realpath($this->dataPath);
        if ($base === false) {
            throw new \RuntimeException('Data directory is missing.');
        }
        for ($path = $file; str_replace('\\', '/', $path) !== str_replace('\\', '/', $this->dataPath); $path = dirname($path)) {
            if (is_link($path)) {
                throw new \RuntimeException('Symlinks are not allowed for site PHP.');
            }
            if (file_exists($path)) {
                $resolved = realpath($path);
                if ($resolved === false || !str_starts_with(str_replace('\\', '/', $resolved), str_replace('\\', '/', $base) . '/')) {
                    throw new \RuntimeException('Site PHP path is outside data.');
                }
            }
            if (dirname($path) === $path) {
                throw new \RuntimeException('Invalid site PHP path.');
            }
        }
    }

    private function atomicWrite(string $file, string $code): void
    {
        $this->guardPath($file);
        if (!is_dir(dirname($file)) && !mkdir(dirname($file), 0775, true) && !is_dir(dirname($file))) {
            throw new \RuntimeException('Unable to create site PHP directory.');
        }
        $tmp = tempnam(dirname($file), '.functions-');
        if ($tmp === false) {
            throw new \RuntimeException('Unable to create temporary file.');
        }
        try {
            if (file_put_contents($tmp, $code, LOCK_EX) === false || !rename($tmp, $file)) {
                throw new \RuntimeException('Unable to save site PHP.');
            }
            if (function_exists('opcache_invalidate')) {
                @opcache_invalidate($file, true);
            }
        } finally {
            if (is_file($tmp)) {
                unlink($tmp);
            }
        }
    }
}
