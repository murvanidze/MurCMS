<?php
declare(strict_types=1);

namespace Mur\Admin;

if (!defined('MUR_CMS_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('No direct script access allowed.');
}

final class AdminMediaManager
{
    private const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
    private const PASSTHROUGH_EXTENSIONS = ['csv', 'txt', 'pdf'];

    public function __construct(private readonly string $basePath)
    {
    }

    public function ensureBase(): void
    {
        if (!is_dir($this->basePath) && !mkdir($this->basePath, 0775, true) && !is_dir($this->basePath)) {
            throw new \RuntimeException('Unable to create uploads directory.');
        }
    }

    public function converterStatus(): array
    {
        return [
            'gd' => extension_loaded('gd') && function_exists('imagewebp'),
            'imagick' => extension_loaded('imagick') && class_exists(\Imagick::class),
        ];
    }

    public function canConvertImages(): bool
    {
        $status = $this->converterStatus();

        return $status['gd'] || $status['imagick'];
    }

    public function normalizeDir(string $dir): string
    {
        $dir = str_replace('\\', '/', trim($dir, "/ \t\n\r\0\x0B"));

        if ($dir === '') {
            return '';
        }

        $parts = [];

        foreach (explode('/', $dir) as $part) {
            $part = trim($part);
            if ($part === '' || $part === '.' || $part === '..') {
                continue;
            }
            $safe = $this->safeSegment($part);
            if ($safe !== '') {
                $parts[] = $safe;
            }
        }

        return implode('/', $parts);
    }

    public function directoryPath(string $dir): string
    {
        $dir = $this->normalizeDir($dir);

        return $dir === '' ? $this->basePath : $this->basePath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $dir);
    }

    public function makeDirectory(string $dir, string $name): string
    {
        $this->ensureBase();
        $name = $this->safeSegment($name);

        if ($name === '') {
            throw new \RuntimeException('Имя папки может содержать только латиницу, цифры, дефис и underscore.');
        }

        $parent = $this->directoryPath($dir);
        if (!$this->isSafePath($parent) || !is_dir($parent)) {
            throw new \RuntimeException('Недопустимая папка.');
        }

        $path = $parent . DIRECTORY_SEPARATOR . $name;
        if (!is_dir($path) && !mkdir($path, 0775, true) && !is_dir($path)) {
            throw new \RuntimeException('Не удалось создать папку.');
        }

        return $this->relativePath($path);
    }

    public function uploadMany(array $files, string $dir, int $quality, bool $convertToWebp = true): array
    {
        $this->ensureBase();
        $dir = $this->normalizeDir($dir);
        $targetDir = $this->directoryPath($dir);

        if (!$this->isSafePath($targetDir) || !is_dir($targetDir)) {
            throw new \RuntimeException('Недопустимая папка загрузки.');
        }

        $quality = max(1, min(100, $quality));
        $normalized = $this->normalizeFilesArray($files);
        $results = [];

        foreach ($normalized as $file) {
            $results[] = $this->uploadOne($file, $targetDir, $quality, $convertToWebp);
        }

        return $results;
    }

    public function entries(string $dir): array
    {
        $this->ensureBase();
        $directory = $this->directoryPath($dir);

        if (!$this->isSafePath($directory) || !is_dir($directory)) {
            return [];
        }

        $entries = [];

        foreach (scandir($directory) ?: [] as $name) {
            if ($name === '.' || $name === '..' || str_starts_with($name, '.')) {
                continue;
            }

            $path = $directory . DIRECTORY_SEPARATOR . $name;
            $relative = $this->relativePath($path);
            $isDir = is_dir($path);

            $entries[] = [
                'name' => $name,
                'relative' => $relative,
                'path' => $path,
                'is_dir' => $isDir,
                'extension' => $isDir ? '' : strtolower(pathinfo($name, PATHINFO_EXTENSION)),
                'size' => $isDir ? 0 : (filesize($path) ?: 0),
                'mtime' => filemtime($path) ?: 0,
                'image' => !$isDir && $this->isImageFile($path),
                'dimensions' => !$isDir ? $this->dimensions($path) : null,
            ];
        }

        usort($entries, static function (array $a, array $b): int {
            if ($a['is_dir'] !== $b['is_dir']) {
                return $a['is_dir'] ? -1 : 1;
            }

            return strnatcasecmp((string) $a['name'], (string) $b['name']);
        });

        return $entries;
    }

    public function filePath(string $relative): string
    {
        $relative = $this->normalizeFileRelative($relative);

        return $relative === '' ? $this->basePath : $this->basePath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    }

    public function canDeleteFile(string $relative): bool
    {
        $path = $this->filePath($relative);

        return $this->isSafePath($path) && is_file($path);
    }

    public function deleteFile(string $relative): void
    {
        $path = $this->filePath($relative);

        if (!$this->canDeleteFile($relative) || !unlink($path)) {
            throw new \RuntimeException('Не удалось удалить файл.');
        }
    }

    public function moveFiles(array $files, string $targetDir): array
    {
        $targetDir = $this->normalizeDir($targetDir);
        $targetDirectory = $this->directoryPath($targetDir);

        if (!$this->isSafePath($targetDirectory) || !is_dir($targetDirectory)) {
            throw new \RuntimeException('Недопустимая папка назначения.');
        }

        $moves = [];
        $reserved = [];
        foreach (array_values(array_unique(array_map('strval', $files))) as $relative) {
            if (!$this->canDeleteFile($relative)) {
                throw new \RuntimeException('Файл не найден или недоступен: ' . $relative);
            }

            $source = $this->filePath($relative);
            if ($this->samePath(dirname($source), $targetDirectory)) {
                throw new \RuntimeException('Один или несколько файлов уже находятся в выбранной папке.');
            }

            $extension = strtolower(pathinfo($source, PATHINFO_EXTENSION));
            $baseName = pathinfo($source, PATHINFO_FILENAME);
            $target = $this->uniqueReservedPath($targetDirectory, $baseName, $extension, $reserved);
            $reserved[$this->pathKey($target)] = true;
            $moves[] = ['source' => $source, 'target' => $target];
        }

        $completed = [];
        foreach ($moves as $move) {
            if (!rename($move['source'], $move['target'])) {
                foreach (array_reverse($completed) as $rollback) {
                    @rename($rollback['target'], $rollback['source']);
                }
                throw new \RuntimeException('Не удалось переместить выбранные файлы.');
            }
            $completed[] = $move;
        }

        return array_map(fn (array $move): string => $this->relativePath($move['target']), $completed);
    }

    public function directories(): array
    {
        $this->ensureBase();
        $directories = [];
        $pending = [$this->basePath];

        while ($pending !== []) {
            $directory = array_pop($pending);
            foreach (scandir($directory) ?: [] as $name) {
                if ($name === '.' || $name === '..' || str_starts_with($name, '.')) {
                    continue;
                }
                $path = $directory . DIRECTORY_SEPARATOR . $name;
                if (is_dir($path) && !is_link($path) && $this->isSafePath($path)) {
                    $directories[] = $this->relativePath($path);
                    $pending[] = $path;
                }
            }
        }

        natcasesort($directories);

        return array_values($directories);
    }

    public function url(string $relative): string
    {
        $relative = $this->normalizeFileRelative($relative);
        $segments = array_map('rawurlencode', array_filter(explode('/', $relative), static fn (string $part): bool => $part !== ''));

        return '/uploads/' . implode('/', $segments);
    }

    public function parentDir(string $dir): string
    {
        $dir = $this->normalizeDir($dir);

        if ($dir === '') {
            return '';
        }

        $parts = explode('/', $dir);
        array_pop($parts);

        return implode('/', $parts);
    }

    public function breadcrumbs(string $dir): array
    {
        $dir = $this->normalizeDir($dir);
        $crumbs = [['label' => 'uploads', 'dir' => '']];
        $current = '';

        foreach (array_filter(explode('/', $dir), static fn (string $part): bool => $part !== '') as $part) {
            $current = $current === '' ? $part : $current . '/' . $part;
            $crumbs[] = ['label' => $part, 'dir' => $current];
        }

        return $crumbs;
    }

    public function isImageExtension(string $extension): bool
    {
        return in_array(strtolower($extension), self::IMAGE_EXTENSIONS, true);
    }

    public function isAllowedExtension(string $extension): bool
    {
        $extension = strtolower($extension);

        return in_array($extension, self::IMAGE_EXTENSIONS, true) || in_array($extension, self::PASSTHROUGH_EXTENSIONS, true);
    }

    private function uploadOne(array $file, string $targetDir, int $quality, bool $convertToWebp): array
    {
        $original = (string) ($file['name'] ?? '');
        $tmp = (string) ($file['tmp_name'] ?? '');
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);

        if ($error !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'name' => $original, 'message' => 'Ошибка загрузки: ' . $this->uploadError($error)];
        }

        if (!is_uploaded_file($tmp)) {
            return ['ok' => false, 'name' => $original, 'message' => 'Файл не прошел upload-проверку PHP.'];
        }

        $extension = strtolower(pathinfo($original, PATHINFO_EXTENSION));

        if (!$this->isAllowedExtension($extension)) {
            return ['ok' => false, 'name' => $original, 'message' => 'Недопустимое расширение.'];
        }

        $baseName = $this->safeBaseName(pathinfo($original, PATHINFO_FILENAME));

        if ($this->isImageExtension($extension)) {
            if (!$this->isValidImageUpload($tmp, $extension)) {
                return ['ok' => false, 'name' => $original, 'message' => 'Содержимое файла не соответствует поддерживаемому формату изображения.'];
            }

            return $this->storeImage($tmp, $targetDir, $baseName, $extension, $quality, $original, $convertToWebp);
        }

        $target = $this->uniquePath($targetDir, $baseName, $extension);

        if (!move_uploaded_file($tmp, $target)) {
            return ['ok' => false, 'name' => $original, 'message' => 'Не удалось сохранить файл.'];
        }

        return ['ok' => true, 'name' => $original, 'stored' => $this->relativePath($target), 'message' => 'Файл сохранен.'];
    }

    private function storeImage(
        string $tmp,
        string $targetDir,
        string $baseName,
        string $extension,
        int $quality,
        string $original,
        bool $convertToWebp,
    ): array
    {
        if ($extension === 'webp' || !$convertToWebp) {
            $target = $this->uniquePath($targetDir, $baseName, $extension);

            if (!move_uploaded_file($tmp, $target)) {
                return ['ok' => false, 'name' => $original, 'message' => 'Не удалось сохранить изображение.'];
            }

            $message = $extension === 'webp'
                ? 'WebP сохранен без перекодирования.'
                : 'Изображение сохранено в исходном формате.';

            return ['ok' => true, 'name' => $original, 'stored' => $this->relativePath($target), 'message' => $message];
        }

        if (!$this->canConvertImages()) {
            return ['ok' => false, 'name' => $original, 'message' => 'Для конвертации изображений нужен GD imagewebp или Imagick.'];
        }

        $target = $this->uniquePath($targetDir, $baseName, 'webp');
        $converted = $this->convertToWebp($tmp, $target, $quality);

        if (!$converted) {
            return ['ok' => false, 'name' => $original, 'message' => 'Не удалось конвертировать изображение в WebP.'];
        }

        return ['ok' => true, 'name' => $original, 'stored' => $this->relativePath($target), 'message' => 'Изображение конвертировано в WebP.'];
    }

    private function convertToWebp(string $source, string $target, int $quality): bool
    {
        if (extension_loaded('imagick') && class_exists(\Imagick::class)) {
            try {
                $image = new \Imagick($source);
                $image->autoOrient();
                $image->stripImage();
                $image->setImageFormat('webp');
                $image->setImageCompressionQuality($quality);
                $result = $image->writeImage($target);
                $image->clear();
                $image->destroy();

                return $result;
            } catch (\Throwable) {
                return false;
            }
        }

        if (!extension_loaded('gd') || !function_exists('imagewebp')) {
            return false;
        }

        $contents = file_get_contents($source);
        if ($contents === false) {
            return false;
        }

        $image = @imagecreatefromstring($contents);
        if (!$image) {
            return false;
        }

        if (function_exists('imagepalettetotruecolor')) {
            imagepalettetotruecolor($image);
        }

        imagealphablending($image, true);
        imagesavealpha($image, true);
        $result = imagewebp($image, $target, $quality);
        imagedestroy($image);

        return $result && is_file($target);
    }

    private function normalizeFilesArray(array $files): array
    {
        $names = $files['name'] ?? [];

        if (!is_array($names)) {
            return [$files];
        }

        $normalized = [];
        $count = count($names);

        for ($i = 0; $i < $count; $i++) {
            $normalized[] = [
                'name' => $files['name'][$i] ?? '',
                'type' => $files['type'][$i] ?? '',
                'tmp_name' => $files['tmp_name'][$i] ?? '',
                'error' => $files['error'][$i] ?? UPLOAD_ERR_NO_FILE,
                'size' => $files['size'][$i] ?? 0,
            ];
        }

        return $normalized;
    }

    private function uniquePath(string $directory, string $baseName, string $extension): string
    {
        $baseName = $baseName !== '' ? $baseName : 'file';
        $path = $directory . DIRECTORY_SEPARATOR . $baseName . '.' . $extension;

        if (!file_exists($path)) {
            return $path;
        }

        for ($i = 2; $i < 1000; $i++) {
            $candidate = $directory . DIRECTORY_SEPARATOR . $baseName . '-' . $i . '.' . $extension;
            if (!file_exists($candidate)) {
                return $candidate;
            }
        }

        return $directory . DIRECTORY_SEPARATOR . $baseName . '-' . time() . '.' . $extension;
    }

    private function uniqueReservedPath(string $directory, string $baseName, string $extension, array $reserved): string
    {
        $baseName = $baseName !== '' ? $baseName : 'file';
        for ($i = 1; $i < 1000; $i++) {
            $suffix = $i > 1 ? '-' . $i : '';
            $candidate = $directory . DIRECTORY_SEPARATOR . $baseName . $suffix . '.' . $extension;
            if (!file_exists($candidate) && !isset($reserved[$this->pathKey($candidate)])) {
                return $candidate;
            }
        }

        return $directory . DIRECTORY_SEPARATOR . $baseName . '-' . time() . '-' . bin2hex(random_bytes(3)) . '.' . $extension;
    }

    private function safeBaseName(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9_-]+/i', '-', $value) ?? '';
        $value = trim($value, '-_');

        return $value !== '' ? $value : 'file';
    }

    private function isValidImageUpload(string $path, string $extension): bool
    {
        $size = @getimagesize($path);

        if (!is_array($size)) {
            return false;
        }

        $expected = match (strtolower($extension)) {
            'jpg', 'jpeg' => IMAGETYPE_JPEG,
            'png' => IMAGETYPE_PNG,
            'webp' => IMAGETYPE_WEBP,
            'gif' => IMAGETYPE_GIF,
            default => 0,
        };

        return $expected !== 0 && (int) ($size[2] ?? 0) === $expected;
    }

    private function safeSegment(string $value): string
    {
        $value = trim($value);

        return preg_match('/^[A-Za-z0-9_-]+$/', $value) ? $value : '';
    }

    private function normalizeFileRelative(string $relative): string
    {
        $relative = str_replace('\\', '/', trim($relative, "/ \t\n\r\0\x0B"));

        if ($relative === '') {
            return '';
        }

        $parts = explode('/', $relative);
        $file = array_pop($parts);
        $safeDirs = [];

        foreach ($parts as $part) {
            $safe = $this->safeSegment($part);
            if ($safe !== '') {
                $safeDirs[] = $safe;
            }
        }

        $file = trim((string) $file);

        if (!preg_match('/^[A-Za-z0-9_-]+\.[A-Za-z0-9]+$/', $file)) {
            return '';
        }

        $safeDirs[] = $file;

        return implode('/', $safeDirs);
    }

    private function relativePath(string $path): string
    {
        $base = str_replace('\\', '/', realpath($this->basePath) ?: $this->basePath);
        $path = str_replace('\\', '/', realpath($path) ?: $path);

        return trim(str_replace($base, '', $path), '/');
    }

    private function isSafePath(string $path): bool
    {
        $base = realpath($this->basePath);
        $target = realpath($path);

        if ($base === false) {
            return false;
        }

        if ($target === false) {
            $target = realpath(dirname($path));
        }

        return $target !== false && ($target === $base || str_starts_with($target, $base . DIRECTORY_SEPARATOR));
    }

    private function samePath(string $left, string $right): bool
    {
        return $this->pathKey($left) === $this->pathKey($right);
    }

    private function pathKey(string $path): string
    {
        $resolved = realpath($path);
        $key = str_replace('\\', '/', $resolved !== false ? $resolved : $path);

        return DIRECTORY_SEPARATOR === '\\' ? strtolower($key) : $key;
    }

    private function isImageFile(string $path): bool
    {
        return $this->isImageExtension(pathinfo($path, PATHINFO_EXTENSION)) && ($this->dimensions($path) !== null);
    }

    private function dimensions(string $path): ?array
    {
        $size = @getimagesize($path);

        if (!is_array($size) || empty($size[0]) || empty($size[1])) {
            return null;
        }

        return ['width' => (int) $size[0], 'height' => (int) $size[1]];
    }

    private function uploadError(int $error): string
    {
        return match ($error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'файл слишком большой',
            UPLOAD_ERR_PARTIAL => 'файл загружен частично',
            UPLOAD_ERR_NO_FILE => 'файл не выбран',
            UPLOAD_ERR_NO_TMP_DIR => 'нет временной папки',
            UPLOAD_ERR_CANT_WRITE => 'ошибка записи на диск',
            UPLOAD_ERR_EXTENSION => 'загрузка остановлена расширением PHP',
            default => 'неизвестная ошибка',
        };
    }
}
