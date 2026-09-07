<?php
declare(strict_types=1);

namespace Mur\Core;

if (!defined('MUR_CMS_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('No direct script access allowed.');
}

final class SeoManager
{
    public function __construct(
        private readonly array $site,
        private readonly array $languages,
        private array $config,
        private readonly ContentRepository $content,
        private readonly ?Hooks $hooks = null,
    ) {
    }

    public function config(): array
    {
        return $this->config;
    }

    public function sitemapSettings(): array
    {
        return is_array($this->config['sitemap'] ?? null) ? $this->config['sitemap'] : [];
    }

    public function robotsContent(): string
    {
        $robots = is_array($this->config['robots'] ?? null) ? $this->config['robots'] : [];
        $content = (string) ($robots['content'] ?? '');

        if (trim($content) === '') {
            $content = $this->defaultRobots();
        }

        return str_replace('{SITEMAP_URL}', $this->absoluteUrl('/sitemap.xml'), $this->withoutDeprecatedRobotsHost($content));
    }

    public function robotsTemplate(): string
    {
        $robots = is_array($this->config['robots'] ?? null) ? $this->config['robots'] : [];

        return $this->withoutDeprecatedRobotsHost((string) ($robots['content'] ?? $this->defaultRobots()));
    }

    public function robots(): string
    {
        return rtrim($this->robotsContent()) . "\n";
    }

    public function sitemapXml(): string
    {
        $settings = $this->sitemapSettings();

        if (($settings['enabled'] ?? true) !== true) {
            return "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\" />\n";
        }

        $urls = $this->sitemapUrls();
        $includeLastmod = ($settings['include_lastmod'] ?? false) === true;
        $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
        $xml .= "<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n";

        foreach ($urls as $url) {
            $xml .= "  <url><loc>" . mur_escape($this->absoluteUrl($url['path'])) . "</loc>";
            if ($includeLastmod && $url['lastmod'] !== '') {
                $xml .= "<lastmod>" . mur_escape($url['lastmod']) . "</lastmod>";
            }
            $xml .= "</url>\n";
        }

        return $xml . "</urlset>\n";
    }

    public function sitemapUrls(): array
    {
        $settings = $this->sitemapSettings();
        $urls = [];

        if (($settings['include_home'] ?? true) === true) {
            foreach (($this->languages['enabled'] ?? []) as $language) {
                $urls[] = [
                    'path' => '/' . (string) $language . '/',
                    'lastmod' => '',
                ];
            }
        }

        if (($settings['include_documents'] ?? true) === true) {
            foreach (($this->languages['enabled'] ?? []) as $language) {
                foreach ($this->content->published((string) $language) as $document) {
                    $urls[] = [
                        'path' => '/' . (string) $language . '/' . $document->urlPath,
                        'lastmod' => $this->documentLastmod($document),
                    ];
                }
            }
        }

        $seen = [];

        $urls = $this->hooks?->filter('seo.sitemap_urls', $urls, [
            'site' => $this->site,
            'languages' => $this->languages,
        ]) ?? $urls;

        return array_values(array_filter($urls, static function (array $url) use (&$seen): bool {
            $path = (string) ($url['path'] ?? '');
            if ($path === '' || isset($seen[$path])) {
                return false;
            }
            $seen[$path] = true;
            return true;
        }));
    }

    public function saveSitemapSettings(array $settings): void
    {
        $this->config['sitemap'] = [
            'enabled' => !empty($settings['enabled']),
            'include_home' => !empty($settings['include_home']),
            'include_documents' => !empty($settings['include_documents']),
            'include_lastmod' => !empty($settings['include_lastmod']),
        ];
        $this->writeConfig();
    }

    public function saveRobots(string $content): void
    {
        $content = $this->withoutDeprecatedRobotsHost($content);
        $this->config['robots'] = ['content' => rtrim($content) . "\n"];
        $this->writeConfig();
    }

    public function saveSitemapSnapshot(): string
    {
        $file = mur_path('storage/generated/sitemap.xml');
        $this->writeFile($file, $this->sitemapXml());

        return $file;
    }

    public function htaccessPath(): string
    {
        return mur_root_path('.htaccess');
    }

    public function htaccessContent(): string
    {
        $file = $this->htaccessPath();

        if (is_file($file)) {
            $content = file_get_contents($file);
            return is_string($content) ? $content : '';
        }

        return $this->defaultHtaccess();
    }

    public function saveHtaccess(string $content): void
    {
        $content = str_replace(["\r\n", "\r"], "\n", $content);
        $file = $this->htaccessPath();

        if (is_file($file) && !copy($file, $file . '.bak')) {
            throw new \RuntimeException('Unable to create .htaccess backup.');
        }

        $this->writeFile($this->htaccessPath(), rtrim($content) . "\n");
    }

    public function htaccessBackupPath(): string
    {
        return $this->htaccessPath() . '.bak';
    }

    public function defaultRobots(): string
    {
        return "User-agent: *\nDisallow: /admin/\nDisallow: /data/\nDisallow: /murcms/app/\nDisallow: /murcms/admin/\nDisallow: /murcms/plugins/\n\nSitemap: {SITEMAP_URL}\n";
    }

    public function defaultHtaccess(): string
    {
        return <<<'HTACCESS'
Options -Indexes
DirectoryIndex index.php

<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteRule ^admin(?:/.*)?$ index.php [L,QSA]
    RewriteRule ^data(?:/|$) - [F,L,NC]
    RewriteRule ^murcms/(?:app|admin|plugins)(?:/|$) - [F,L,NC]
    RewriteRule ^murcms/templates/.+\.php$ - [F,L,NC]
    RewriteRule ^(?:docs|scripts|sources|tests)(?:/|$) - [F,L,NC]

    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteRule ^ index.php [L,QSA]
</IfModule>

<FilesMatch "(^\.|\.mur\.md$|\.md$|\.bak(?:\.[0-9]+)?$|\.log$|\.dist\.php$|composer\.(?:json|lock)$)">
    Require all denied
</FilesMatch>

<IfModule mod_headers.c>
    Header always set X-Content-Type-Options "nosniff"
    Header always set Referrer-Policy "strict-origin-when-cross-origin"
    Header always set X-Frame-Options "SAMEORIGIN"

    <FilesMatch "\.(css|js|mjs|svg|jpg|jpeg|png|gif|webp|avif|ico|woff2?)$">
        Header set Cache-Control "public, max-age=31536000, immutable"
    </FilesMatch>
</IfModule>

<IfModule mod_expires.c>
    ExpiresActive On
    ExpiresByType text/css "access plus 1 year"
    ExpiresByType application/javascript "access plus 1 year"
    ExpiresByType image/svg+xml "access plus 1 year"
    ExpiresByType image/jpeg "access plus 1 year"
    ExpiresByType image/png "access plus 1 year"
    ExpiresByType image/webp "access plus 1 year"
    ExpiresByType image/avif "access plus 1 year"
    ExpiresByType font/woff2 "access plus 1 year"
</IfModule>

<IfModule mod_deflate.c>
    AddOutputFilterByType DEFLATE text/html text/plain text/css application/javascript application/json image/svg+xml
</IfModule>
HTACCESS;
    }

    private function writeConfig(): void
    {
        $export = var_export($this->config, true);
        $contents = "<?php\ndeclare(strict_types=1);\n\nif (!defined('MUR_CMS_BOOTSTRAPPED')) {\n    http_response_code(403);\n    exit('No direct script access allowed.');\n}\n\nreturn " . $export . ";\n";
        $this->writeFile(mur_path('config/seo.php'), $contents);
    }

    private function writeFile(string $file, string $content): void
    {
        $directory = dirname($file);

        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException('Unable to create directory: ' . $directory);
        }

        $tmp = $file . '.tmp';

        if (file_put_contents($tmp, $content, LOCK_EX) === false) {
            throw new \RuntimeException('Unable to write temporary file: ' . $tmp);
        }

        if (!rename($tmp, $file)) {
            @unlink($tmp);
            throw new \RuntimeException('Unable to replace file: ' . $file);
        }
    }

    private function absoluteUrl(string $path): string
    {
        return rtrim((string) ($this->site['base_url'] ?? ''), '/') . '/' . ltrim($path, '/');
    }

    private function withoutDeprecatedRobotsHost(string $content): string
    {
        $content = str_replace(["\r\n", "\r"], "\n", $content);
        $content = preg_replace('/^[ \t]*Host[ \t]*:[^\n]*(?:\n|$)/mi', '', $content) ?? $content;

        return $content;
    }

    private function documentLastmod(Document $document): string
    {
        $value = trim((string) ($document->meta['updated_at'] ?? $document->meta['date'] ?? ''));

        if ($value !== '') {
            return substr($value, 0, 10);
        }

        $mtime = is_file($document->filePath) ? filemtime($document->filePath) : false;

        return $mtime !== false ? gmdate('Y-m-d', $mtime) : '';
    }
}
