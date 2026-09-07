<?php
declare(strict_types=1);

namespace Mur\Admin;

if (!defined('MUR_CMS_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('No direct script access allowed.');
}

final class AdminFileManager
{
    public const MAX_UPLOAD_BYTES = 2 * 1024 * 1024;

    public function __construct(private readonly string $dataPath)
    {
    }

    /** @return array{filename:string,path:string} */
    public function upload(array $upload, string $relativeDirectory): array
    {
        [$name, $contents] = $this->validatedUpload($upload);
        $directory = $this->safeDirectory($relativeDirectory, true);
        $target = $this->uniqueTarget($directory, $name);
        $this->writeAtomically($target, $contents, false);

        return ['filename' => basename($target), 'path' => $target];
    }

    /** @return array{filename:string,path:string,backup:string} */
    public function replace(array $upload, string $relativeTarget): array
    {
        [, $contents] = $this->validatedUpload($upload);
        $target = $this->safeExistingFile($relativeTarget);
        $backup = $this->backup($target, $relativeTarget);
        $this->writeAtomically($target, $contents, true);

        return ['filename' => basename($target), 'path' => $target, 'backup' => $backup];
    }

    private function validatedUpload(array $upload): array
    {
        $error = (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            throw new \RuntimeException($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE
                ? 'Файл превышает допустимый размер 2 MB.'
                : 'Файл не был успешно загружен.');
        }

        if (is_array($upload['name'] ?? null) || is_array($upload['tmp_name'] ?? null)) {
            throw new \RuntimeException('За одну операцию можно загрузить только один файл.');
        }
        $name = (string) ($upload['name'] ?? '');
        if (
            $name === ''
            || $name !== basename($name)
            || str_contains($name, '/')
            || str_contains($name, '\\')
            || str_contains($name, '..')
            || str_contains($name, "\0")
            || preg_match('/[\x00-\x1F\x7F]/', $name)
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*\.mur\.md$/', $name) !== 1
        ) {
            throw new \RuntimeException('Допустим только безопасный filename с окончанием .mur.md.');
        }

        $tmp = (string) ($upload['tmp_name'] ?? '');
        if ($tmp === '' || !is_file($tmp) || !is_readable($tmp)) {
            throw new \RuntimeException('Временный файл загрузки недоступен для чтения.');
        }
        if (PHP_SAPI !== 'cli' && !is_uploaded_file($tmp)) {
            throw new \RuntimeException('Полученный файл не является HTTP upload.');
        }

        $size = filesize($tmp);
        if ($size === false || $size > self::MAX_UPLOAD_BYTES || (int) ($upload['size'] ?? $size) > self::MAX_UPLOAD_BYTES) {
            throw new \RuntimeException('Файл превышает допустимый размер 2 MB.');
        }

        $contents = file_get_contents($tmp);
        if ($contents === false) {
            throw new \RuntimeException('Не удалось прочитать временный файл загрузки.');
        }

        return [$name, $contents];
    }

    private function safeDirectory(string $relative, bool $create): string
    {
        $relative = str_replace('\\', '/', trim($relative, '/'));
        if ($relative === '' || str_contains($relative, '..') || preg_match('~^[A-Za-z0-9_-]+(?:/[A-Za-z0-9_-]+)*$~', $relative) !== 1) {
            throw new \RuntimeException('Недопустимая целевая папка.');
        }

        $base = realpath($this->dataPath);
        if ($base === false) {
            throw new \RuntimeException('Корневая папка данных недоступна.');
        }

        $directory = $base . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        if ($create && !is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException('Не удалось создать целевую папку.');
        }

        $resolved = realpath($directory);
        if ($resolved === false || !$this->inside($resolved, $base)) {
            throw new \RuntimeException('Целевая папка находится вне data directory.');
        }

        return $resolved;
    }

    private function safeExistingFile(string $relative): string
    {
        $relative = str_replace('\\', '/', trim($relative, '/'));
        if ($relative === '' || str_contains($relative, '..') || !str_ends_with($relative, '.mur.md')) {
            throw new \RuntimeException('Недопустимый целевой файл.');
        }

        $base = realpath($this->dataPath);
        $target = realpath($this->dataPath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative));
        if ($base === false || $target === false || !is_file($target) || !$this->inside($target, $base)) {
            throw new \RuntimeException('Целевой файл не найден или находится вне data directory.');
        }

        return $target;
    }

    private function uniqueTarget(string $directory, string $name): string
    {
        $stem = substr($name, 0, -7);
        for ($suffix = 0; $suffix < 10000; $suffix++) {
            $candidate = $directory . DIRECTORY_SEPARATOR . $stem . ($suffix > 0 ? '-' . $suffix : '') . '.mur.md';
            if (!file_exists($candidate)) {
                return $candidate;
            }
        }

        throw new \RuntimeException('Не удалось подобрать свободное имя файла.');
    }

    private function backup(string $target, string $relative): string
    {
        $contents = file_get_contents($target);
        if ($contents === false) {
            throw new \RuntimeException('Не удалось прочитать исходный файл для резервной копии.');
        }

        $relative = str_replace('\\', '/', trim($relative, '/'));
        $directory = $this->safeDirectory('storage/backups/file-replacements/' . dirname($relative), true);
        $stamp = date('Ymd-His');
        $base = basename($relative) . '.' . $stamp;
        $backup = $directory . DIRECTORY_SEPARATOR . $base . '.bak';
        for ($index = 1; file_exists($backup); $index++) {
            $backup = $directory . DIRECTORY_SEPARATOR . $base . '-' . $index . '.bak';
        }
        $this->writeAtomically($backup, $contents, false);

        return $backup;
    }

    private function writeAtomically(string $target, string $contents, bool $replace): void
    {
        $directory = dirname($target);
        $tmp = tempnam($directory, '.mur-upload-');
        if ($tmp === false) {
            throw new \RuntimeException('Не удалось создать временный файл записи.');
        }

        try {
            if (file_put_contents($tmp, $contents, LOCK_EX) === false) {
                throw new \RuntimeException('Не удалось записать временный файл.');
            }
            if (!$replace && file_exists($target)) {
                throw new \RuntimeException('Файл с таким именем уже существует.');
            }
            if (!rename($tmp, $target)) {
                throw new \RuntimeException('Не удалось атомарно завершить запись файла.');
            }
        } finally {
            if (is_file($tmp)) {
                @unlink($tmp);
            }
        }
    }

    private function inside(string $path, string $base): bool
    {
        $normalize = static fn (string $value): string => str_replace('\\', '/', rtrim($value, '/\\'));
        $path = $normalize($path);
        $base = $normalize($base);
        if (DIRECTORY_SEPARATOR === '\\') {
            $path = strtolower($path);
            $base = strtolower($base);
        }

        return $path === $base || str_starts_with($path, $base . '/');
    }
}
