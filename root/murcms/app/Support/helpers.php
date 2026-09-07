<?php
declare(strict_types=1);

if (!defined('MUR_CMS_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('No direct script access allowed.');
}

function mur_path(string $path = ''): string
{
    return mur_data_path($path);
}

function mur_root_path(string $path = ''): string
{
    return MUR_PUBLIC_PATH . ($path !== '' ? '/' . ltrim($path, '/\\') : '');
}

function mur_engine_path(string $path = ''): string
{
    return MUR_ENGINE_PATH . ($path !== '' ? '/' . ltrim($path, '/\\') : '');
}

function mur_data_path(string $path = ''): string
{
    return MUR_DATA_PATH . ($path !== '' ? '/' . ltrim($path, '/\\') : '');
}

function mur_uploads_path(string $path = ''): string
{
    return MUR_UPLOADS_PATH . ($path !== '' ? '/' . ltrim($path, '/\\') : '');
}

function mur_asset_url(string $path): string
{
    $path = ltrim(str_replace('\\', '/', $path), '/');
    $file = mur_engine_path('assets/' . $path);
    $version = is_file($file) ? (string) (filemtime($file) ?: 1) : '1';

    return '/murcms/assets/' . $path . '?v=' . rawurlencode($version);
}

function mur_favicon_config_path(string $path): string
{
    $path = str_replace('\\', '/', trim($path));
    $path = preg_replace('~^/?uploads/~i', '', $path) ?? '';

    if (
        $path === ''
        || str_contains($path, '..')
        || str_starts_with($path, '/')
        || !preg_match('~^[a-zA-Z0-9_./-]+\.(?:png|svg|webp)$~i', $path)
    ) {
        return '';
    }

    return ltrim($path, '/');
}

function mur_favicon(array $site, string $surface = 'frontend'): array
{
    $surface = $surface === 'admin' ? 'admin' : 'frontend';
    $configured = mur_favicon_config_path((string) ($site['favicon_' . $surface] ?? ''));
    $resolved = $configured !== '' ? mur_upload_favicon($configured) : null;

    if ($resolved !== null) {
        return $resolved;
    }

    if ($surface === 'frontend') {
        foreach (mur_auto_favicon_candidates() as $candidate) {
            $resolved = mur_upload_favicon($candidate);
            if ($resolved !== null) {
                return $resolved;
            }
        }
    }

    return [
        'url' => mur_asset_url('site-icon.svg'),
        'type' => 'image/svg+xml',
        'sizes' => 'any',
        'source' => 'default',
        'path' => '',
    ];
}

function mur_favicon_link(array $site, string $surface = 'frontend'): string
{
    $favicon = mur_favicon($site, $surface);
    $sizes = ($favicon['sizes'] ?? '') !== ''
        ? ' sizes="' . mur_escape((string) $favicon['sizes']) . '"'
        : '';

    return '<link rel="icon" href="' . mur_escape((string) $favicon['url']) . '" type="'
        . mur_escape((string) $favicon['type']) . '"' . $sizes . '>';
}

function mur_auto_favicon_candidates(): array
{
    $candidates = [];

    foreach (glob(mur_uploads_path('*'), GLOB_NOSORT) ?: [] as $file) {
        $name = basename($file);
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        if (
            is_file($file)
            && !str_starts_with($name, '.')
            && str_starts_with($name, 'favicon')
            && in_array($extension, ['png', 'svg', 'webp'], true)
        ) {
            $candidates[] = $name;
        }
    }

    sort($candidates, SORT_NATURAL);

    return $candidates;
}

function mur_upload_favicon(string $relative): ?array
{
    $relative = mur_favicon_config_path($relative);

    if ($relative === '') {
        return null;
    }

    $base = realpath(mur_uploads_path());
    $file = realpath(mur_uploads_path($relative));

    if ($base === false || $file === false || !is_file($file)) {
        return null;
    }

    $basePrefix = rtrim(str_replace('\\', '/', $base), '/') . '/';
    $normalizedFile = str_replace('\\', '/', $file);
    if (strncasecmp($normalizedFile, $basePrefix, strlen($basePrefix)) !== 0) {
        return null;
    }

    $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    $types = [
        'png' => 'image/png',
        'svg' => 'image/svg+xml',
        'webp' => 'image/webp',
    ];

    if (!isset($types[$extension])) {
        return null;
    }

    $urlPath = implode('/', array_map('rawurlencode', explode('/', $relative)));
    $version = (string) (filemtime($file) ?: 1);

    return [
        'url' => '/uploads/' . $urlPath . '?v=' . rawurlencode($version),
        'type' => $types[$extension],
        'sizes' => $extension === 'svg' ? 'any' : '',
        'source' => 'uploads',
        'path' => $relative,
    ];
}

function mur_default_brand_html(): string
{
    return '<span class="site-identity"><img class="site-icon" src="/murcms/assets/site-icon.svg" width="50" height="50" alt="" aria-hidden="true"><span>MurCMS</span></span>';
}

function mur_template_path(string $template, string $path = ''): string
{
    $template = preg_replace('/[^a-z0-9_-]+/', '', strtolower($template)) ?: 'default';
    return MUR_ENGINE_PATH . '/templates/' . $template . ($path !== '' ? '/' . ltrim($path, '/\\') : '');
}

function mur_template_asset_url(string $template, string $path): string
{
    $template = preg_replace('/[^a-z0-9_-]+/', '', strtolower($template)) ?: 'default';
    $path = ltrim(str_replace('\\', '/', $path), '/');
    $file = mur_template_path($template, 'assets/' . $path);
    $version = is_file($file) ? (string) (filemtime($file) ?: 1) : '1';

    return '/murcms/templates/' . $template . '/assets/' . $path . '?v=' . rawurlencode($version);
}

function mur_template_custom_css_path(string $template, string $file): string
{
    $template = strtolower(trim($template));
    if (preg_match('/^[a-z0-9][a-z0-9_-]*$/', $template) !== 1) {
        throw new RuntimeException('Invalid frontend template name.');
    }
    if (!in_array($file, ['critical.css', 'custom.css'], true)) {
        throw new RuntimeException('Invalid custom CSS filename.');
    }

    return mur_uploads_path('murcms-custom/' . $template . '/' . $file);
}

function mur_template_custom_css_url(string $template): string
{
    $file = mur_template_custom_css_path($template, 'custom.css');
    if (!is_file($file) || !is_readable($file) || trim((string) file_get_contents($file)) === '') {
        return '';
    }

    return '/uploads/murcms-custom/' . rawurlencode(strtolower(trim($template))) . '/custom.css?v='
        . rawurlencode((string) (filemtime($file) ?: 1));
}

function mur_safe_inline_css(string $css): string
{
    return (string) preg_replace('~</style~i', '<\\/style', $css);
}

function mur_color_scheme_names(string $template): array
{
    $items = [];
    $themesPath = mur_template_path($template, 'themes');

    foreach (glob($themesPath . '/*/critical.css', GLOB_NOSORT) ?: [] as $criticalFile) {
        $directory = dirname($criticalFile);
        $name = basename($directory);

        if (
            preg_match('/^[a-z0-9][a-z0-9_-]*$/', $name)
            && is_file($directory . '/theme.css')
        ) {
            $items[] = $name;
        }
    }

    sort($items, SORT_NATURAL);

    return array_values(array_unique($items));
}

function mur_color_scheme_name(string $template, string $name): string
{
    $name = strtolower(trim($name));
    $available = mur_color_scheme_names($template);

    if (in_array($name, $available, true)) {
        return $name;
    }

    return in_array('light', $available, true) ? 'light' : ($available[0] ?? 'light');
}

function mur_color_scheme_path(string $template, string $name, string $file): string
{
    $name = mur_color_scheme_name($template, $name);
    $file = in_array($file, ['critical.css', 'theme.css'], true) ? $file : 'theme.css';

    return mur_template_path($template, 'themes/' . $name . '/' . $file);
}

function mur_color_scheme_url(string $template, string $name): string
{
    $template = preg_replace('/[^a-z0-9_-]+/', '', strtolower($template)) ?: 'default';
    $name = mur_color_scheme_name($template, $name);
    $file = mur_color_scheme_path($template, $name, 'theme.css');
    $version = is_file($file) ? (string) (filemtime($file) ?: 1) : '1';

    return '/murcms/templates/' . $template . '/themes/' . $name . '/theme.css?v=' . rawurlencode($version);
}

function mur_escape(string|int|float|null $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function mur_meta_value(array $meta, string $key, ?string $fallback = null): string
{
    $value = $meta[$key] ?? $fallback ?? '';
    return is_scalar($value) ? trim((string) $value) : '';
}

function mur_is_published(array $meta): bool
{
    return strtolower(mur_meta_value($meta, 'status', 'draft')) === 'published';
}

function mur_render_body(string $body, ?\Mur\Core\Hooks $hooks = null, array $context = []): string
{
    if ($hooks !== null && $hooks->has('content.render_body')) {
        $rendered = $hooks->filter('content.render_body', null, array_replace($context, [
            'source' => $body,
        ]));

        if (is_string($rendered)) {
            return $rendered;
        }
    }

    $trimmed = trim($body);

    if ($trimmed === '') {
        return '';
    }

    if (str_contains($trimmed, '<')) {
        return $trimmed;
    }

    $html = [];
    $paragraph = [];

    $flushParagraph = static function () use (&$html, &$paragraph): void {
        if ($paragraph === []) {
            return;
        }

        $html[] = '<p>' . implode('<br>', array_map('mur_escape', $paragraph)) . '</p>';
        $paragraph = [];
    };

    foreach (preg_split('/\R/u', $trimmed) ?: [] as $line) {
        $line = rtrim($line);

        if ($line === '') {
            $flushParagraph();
            continue;
        }

        if (preg_match('/^(#{1,3})\s+(.+)$/u', $line, $match)) {
            $flushParagraph();
            $level = strlen($match[1]);
            $html[] = '<h' . $level . '>' . mur_escape($match[2]) . '</h' . $level . '>';
            continue;
        }

        if (preg_match('/^\[(?:(?:snippet|ushka|gallery|counters|carousel|logos)(?:=|:)[a-zA-Z0-9_-]+|tagcloud(?:\s+[^\]]*)?|list)\]$/', trim($line))) {
            $flushParagraph();
            $html[] = trim($line);
            continue;
        }

        $paragraph[] = $line;
    }

    $flushParagraph();

    return implode("\n", $html);
}
