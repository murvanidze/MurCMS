<?php
declare(strict_types=1);

namespace Mur\Templates;

use Mur\Core\Document;
use Mur\Core\FrontendTemplate;
use Mur\Core\TemplateContext;

if (!defined('MUR_CMS_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('No direct script access allowed.');
}

final class DefaultTemplate implements FrontendTemplate
{
    private array $options;

    public function __construct(private readonly TemplateContext $context)
    {
        $this->options = array_replace([
            'content_width' => 1040,
            'color_scheme' => 'light',
            'sticky_header' => true,
            'show_breadcrumbs' => true,
            'show_page_summary' => true,
            'load_menu_js' => true,
            'footer_blocks' => true,
            'footer_menu_enabled' => false,
            'footer_menu_name' => 'footer',
            'brand_mode' => 'html',
            'brand_html' => mur_default_brand_html(),
            'brand_image' => 'site-icon.svg',
            'brand_image_height' => 50,
            'list_defaults' => [
                'children' => ['view' => 'cards', 'sort' => 'order', 'direction' => 'asc', 'per_page' => '', 'position' => 'bottom'],
                'category' => ['view' => 'cards', 'sort' => 'order', 'direction' => 'asc', 'per_page' => '', 'position' => 'bottom'],
            ],
        ], is_array($context->config['options'] ?? null) ? $context->config['options'] : []);
        $this->options['color_scheme'] = mur_color_scheme_name(
            $context->name,
            (string) ($this->options['color_scheme'] ?? 'light'),
        );
    }

    public function name(): string
    {
        return $this->context->name;
    }

    public function assetUrl(string $path): string
    {
        return mur_template_asset_url($this->context->name, $path);
    }

    public function documentFrame(Document $document, string $body): string
    {
        $summary = trim((string) ($document->meta['summary'] ?? $document->meta['description'] ?? $document->meta['seo.description'] ?? ''));
        $html = $this->optionBool('show_breadcrumbs') ? $this->breadcrumbs($document) : '';
        $html .= '<article class="page-article">';

        if ($this->boolMeta($document, 'page.header', true)) {
            $html .= '<header class="page-header">';
            $html .= '<h1>' . mur_escape($document->title()) . '</h1>';

            if ($summary !== '' && $this->optionBool('show_page_summary')) {
                $html .= '<p>' . mur_escape($summary) . '</p>';
            }

            $html .= '</header>';
        }

        return $html . '<div class="page-content">' . $body . '</div></article>';
    }

    public function renderLayout(
        string $language,
        ?Document $document,
        string $body,
        array $meta,
        ?array $pagination = null,
    ): string {
        $currentPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $title = trim((string) $meta['title']);
        $description = trim((string) $meta['description']);
        $canonicalPath = $currentPath;
        if (($pagination['page'] ?? 1) > 1) {
            $canonicalPath .= '?page=' . (int) $pagination['page'];
        }
        $canonical = rtrim((string) $this->context->site['base_url'], '/') . $canonicalPath;

        $alternateLinks = $this->alternateLinks($language, $document);
        $languageSwitcher = $this->languageSwitcher($language, $document);
        $menu = $this->context->menu->render($language, 'main', $currentPath);
        $context = [
            'language' => $language,
            'document' => $document,
            'meta' => $meta,
            'current_path' => $currentPath,
            'template' => $this->context->name,
            'template_options' => $this->options,
            'canonical' => $canonical,
        ];
        $footer = $this->footer($language, $context);
        $head = $this->context->hooks->render('layout.head', $context);
        $bodyStart = $this->context->hooks->render('layout.body_start', $context);
        $headerBefore = $this->context->hooks->render('layout.header_before', $context);
        $headerAfter = $this->context->hooks->render('layout.header_after', $context);
        $mainBefore = $this->context->hooks->render('layout.main_before', $context);
        $mainAfter = $this->context->hooks->render('layout.main_after', $context);
        $contentStart = $this->context->hooks->render('layout.content_start', $context);
        $contentEnd = $this->context->hooks->render('layout.content_end', $context);
        $bodyEnd = $this->context->hooks->render('layout.body_end', $context);
        $bodyClass = trim('template-' . $this->context->name
            . ' ts-' . $this->safeClass((string) $this->options['color_scheme'])
            . ($this->optionBool('sticky_header') ? ' theme-header-sticky' : ' theme-header-static'));
        $bodyStyle = '--site-width:' . max(760, min(1440, (int) $this->options['content_width'])) . 'px'
            . ';--brand-image-height:' . max(24, min(120, (int) ($this->options['brand_image_height'] ?? 50))) . 'px';
        $menuScript = $this->optionBool('load_menu_js')
            ? '<script src="' . mur_escape($this->assetUrl('menu.js')) . '" defer></script>'
            : '';
        $customCss = $this->customCssHead();

        return '<!doctype html>
<html lang="' . mur_escape($language) . '">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  ' . mur_favicon_link($this->context->site, 'frontend') . '
  <title>' . mur_escape($title) . '</title>
  <meta name="description" content="' . mur_escape($description) . '">
  <link rel="canonical" href="' . mur_escape($canonical) . '">
  ' . $alternateLinks . '
  <meta property="og:title" content="' . mur_escape($title) . '">
  <meta property="og:description" content="' . mur_escape($description) . '">
  <meta property="og:url" content="' . mur_escape($canonical) . '">
  <meta property="og:type" content="website">
  ' . $this->criticalCss() . '
  <link rel="stylesheet" href="' . mur_escape($this->assetUrl('app.css')) . '">
  <link rel="stylesheet" href="' . mur_escape(mur_color_scheme_url($this->context->name, (string) $this->options['color_scheme'])) . '">
  ' . $head . '
  ' . $customCss . '
</head>
<body class="' . mur_escape($bodyClass) . '" style="' . mur_escape($bodyStyle) . '">
  ' . $bodyStart . '
  <a class="skip-link" href="#main">Skip to content</a>
  ' . $headerBefore . '
  <header class="site-header">
    ' . $this->brand($language) . '
    <button class="site-menu-toggle" type="button" aria-controls="site-nav" aria-expanded="false" aria-label="Open menu">
      <svg class="icon" aria-hidden="true" focusable="false"><use href="' . mur_escape($this->assetUrl('icons.svg')) . '#menu"></use></svg>
    </button>
    <nav id="site-nav" class="site-nav" aria-label="Main menu">' . $menu . '</nav>
    <nav class="language-nav" aria-label="Languages">' . $languageSwitcher . '</nav>
  </header>
  ' . $headerAfter . '
  ' . $mainBefore . '
  <main id="main" class="site-main">
    ' . $contentStart . '
    ' . $body . '
    ' . $contentEnd . '
  </main>
  ' . $mainAfter . '
  <footer class="site-footer">' . $footer . '</footer>
  ' . $menuScript . '
  ' . $bodyEnd . '
</body>
</html>';
    }

    private function footer(string $language, array $context): string
    {
        $html = '';
        $renderedMenu = false;

        $footerBlockIds = $this->configuredBlockIds('footer', $language);
        if ($footerBlockIds !== null || $this->optionBool('footer_blocks')) {
            $entries = $footerBlockIds ?? array_map(
                static fn (\Mur\Core\Document $block): string => str_ends_with($block->fileName, '.mur.md')
                    ? substr($block->fileName, 0, -7)
                    : pathinfo($block->fileName, PATHINFO_FILENAME),
                $this->context->content->footerBlocks($language),
            );

            foreach ($entries as $entry) {
                if (preg_match('/^menu:([A-Za-z0-9_-]+)$/', (string) $entry, $match)) {
                    $menu = $this->footerMenu($language, $match[1], $context);
                    if ($menu !== '') {
                        $html .= "\n" . $menu;
                        $renderedMenu = true;
                    }
                    continue;
                }

                foreach ($this->context->content->footerBlocks(
                    $language,
                    [(string) $entry],
                    (string) ($this->context->languages['default'] ?? $language),
                ) as $block) {
                    $html .= "\n" . mur_render_body($block->body, $this->context->hooks, [
                        'language' => $language,
                        'document' => $block,
                        'collection' => $block->collection,
                        'surface' => 'footer',
                    ]);
                }
            }
        }

        if (!$renderedMenu && $this->optionBool('footer_menu_enabled')) {
            $name = preg_replace('/[^a-zA-Z0-9_-]+/', '', (string) ($this->options['footer_menu_name'] ?? 'footer')) ?: 'footer';
            $html .= "\n" . $this->footerMenu($language, $name, $context);
        }

        if (trim($html) === '') {
            $html = '<p>MurCMS</p>';
        }

        return $this->context->hooks->filter('layout.footer', $html, $context);
    }

    private function footerMenu(string $language, string $name, array $context): string
    {
        $menu = $this->context->menu->render($language, $name, (string) ($context['current_path'] ?? '/'));
        return $menu !== '' ? '<nav class="footer-menu" aria-label="Footer menu">' . $menu . '</nav>' : '';
    }

    private function configuredBlockIds(string $kind, string $language): ?array
    {
        $layout = is_array($this->options['block_layout'][$kind] ?? null)
            ? $this->options['block_layout'][$kind]
            : null;

        if ($layout === null) {
            return null;
        }

        $key = ($this->context->languages['structure'] ?? 'flexible') === 'mirror'
            ? (string) ($this->context->languages['default'] ?? $language)
            : $language;

        return array_key_exists($key, $layout) && is_array($layout[$key]) ? $layout[$key] : [];
    }

    private function brand(string $language): string
    {
        $mode = (string) ($this->options['brand_mode'] ?? 'html');
        $content = mur_escape((string) $this->context->site['name']);

        if ($mode === 'html') {
            $html = trim((string) ($this->options['brand_html'] ?? ''));
            if ($html !== '') {
                $content = $html;
            }
        } elseif ($mode === 'image') {
            $relative = $this->brandImagePath((string) ($this->options['brand_image'] ?? 'site-icon.svg'));
            $file = $relative !== '' ? mur_uploads_path($relative) : '';
            $size = $file !== '' && is_file($file) ? $this->brandImageSize($file) : false;

            if (is_array($size) && ($size[0] ?? 0) > 0 && ($size[1] ?? 0) > 0) {
                $content = '<img src="/uploads/' . mur_escape(str_replace('\\', '/', $relative)) . '" width="' . (int) $size[0] . '" height="' . (int) $size[1] . '" alt="' . mur_escape((string) $this->context->site['name']) . '" loading="eager" decoding="async">';
            }
        }

        return '<div class="site-brand"><a href="/' . mur_escape($language) . '/">' . $content . '</a></div>';
    }

    private function brandImagePath(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));
        $path = preg_replace('~^/?uploads/~i', '', $path) ?? '';

        if ($path === '' || str_contains($path, '..') || !preg_match('~^[a-zA-Z0-9_./-]+\.(?:avif|gif|jpe?g|png|svg|webp)$~i', $path)) {
            return '';
        }

        return ltrim($path, '/');
    }

    private function brandImageSize(string $file): array|false
    {
        if (strtolower(pathinfo($file, PATHINFO_EXTENSION)) !== 'svg') {
            return @getimagesize($file);
        }

        $svg = file_get_contents($file, false, null, 0, 65536);
        if (!is_string($svg) || !preg_match('~\bviewBox\s*=\s*["\']\s*[-+0-9.e]+[ ,]+[-+0-9.e]+[ ,]+([-+0-9.e]+)[ ,]+([-+0-9.e]+)\s*["\']~i', $svg, $match)) {
            return false;
        }

        $width = (float) $match[1];
        $height = (float) $match[2];
        if ($width <= 0 || $height <= 0) {
            return false;
        }

        return [
            max(1, min(10000, (int) round($width))),
            max(1, min(10000, (int) round($height))),
        ];
    }

    private function criticalCss(): string
    {
        $file = __DIR__ . '/assets/critical.css';
        $css = is_file($file) ? file_get_contents($file) : false;
        $base = is_string($css) && trim($css) !== ''
            ? '<style data-template-critical>' . $css . '</style>'
            : '';

        return $base . $this->colorSchemeCriticalCss();
    }

    private function colorSchemeCriticalCss(): string
    {
        $file = mur_color_scheme_path(
            $this->context->name,
            (string) $this->options['color_scheme'],
            'critical.css',
        );
        $css = is_file($file) ? file_get_contents($file) : false;

        if (!is_string($css) || trim($css) === '') {
            return '';
        }

        return '<style data-color-scheme="' . mur_escape((string) $this->options['color_scheme']) . '">' . $css . '</style>';
    }

    private function customCssHead(): string
    {
        $criticalFile = mur_template_custom_css_path($this->context->name, 'critical.css');
        $critical = is_file($criticalFile) && is_readable($criticalFile) ? file_get_contents($criticalFile) : false;
        $html = is_string($critical) && trim($critical) !== ''
            ? '<style data-murcms-custom-critical>' . mur_safe_inline_css($critical) . '</style>'
            : '';
        $url = mur_template_custom_css_url($this->context->name);

        if ($url !== '') {
            $html .= ($html !== '' ? "\n  " : '') . '<link rel="stylesheet" data-murcms-custom-css href="' . mur_escape($url) . '">';
        }

        return $html;
    }

    private function breadcrumbs(Document $document): string
    {
        $items = [
            ['url' => '/' . $document->language . '/', 'label' => 'Home'],
        ];
        $parents = [];
        $parentId = $document->parentId();
        $guard = 0;

        while ($parentId !== '' && $guard < 10) {
            $parent = $this->context->content->findById($document->language, $parentId);

            if (!$parent instanceof Document) {
                break;
            }

            array_unshift($parents, $parent);
            $parentId = $parent->parentId();
            $guard++;
        }

        foreach ($parents as $parent) {
            $items[] = ['url' => '/' . $parent->language . '/' . $parent->urlPath, 'label' => $parent->title()];
        }

        $items[] = ['url' => '', 'label' => $document->title()];
        $html = '<nav class="breadcrumbs" aria-label="Breadcrumbs"><ol>';

        foreach ($items as $index => $item) {
            $isLast = $index === array_key_last($items);
            $html .= '<li>';

            if ($isLast || $item['url'] === '') {
                $html .= '<span aria-current="page">' . mur_escape($item['label']) . '</span>';
            } else {
                $html .= '<a href="' . mur_escape($item['url']) . '">' . mur_escape($item['label']) . '</a>';
            }

            $html .= '</li>';
        }

        return $html . '</ol></nav>';
    }

    private function alternateLinks(string $currentLanguage, ?Document $document): string
    {
        $links = [];

        foreach ($this->context->languages['enabled'] as $language) {
            $language = (string) $language;

            if (!$document instanceof Document) {
                $url = '/' . $language . '/';
            } elseif ($language === $currentLanguage) {
                $url = '/' . $language . '/' . $document->urlPath;
            } else {
                $translation = $this->context->content->findTranslation($document, $language);

                if (!$translation instanceof Document) {
                    continue;
                }

                $url = '/' . $language . '/' . $translation->urlPath;
            }

            $links[] = '<link rel="alternate" hreflang="' . mur_escape($language) . '" href="' . mur_escape(rtrim((string) $this->context->site['base_url'], '/') . $url) . '">';
        }

        return implode("\n  ", $links);
    }

    private function languageSwitcher(string $currentLanguage, ?Document $document): string
    {
        $links = [];
        $currentLabel = $this->context->languages['labels'][$currentLanguage] ?? strtoupper($currentLanguage);

        foreach ($this->context->languages['enabled'] as $language) {
            $language = (string) $language;
            $label = $this->context->languages['labels'][$language] ?? strtoupper($language);
            $url = $this->languageUrl($language, $document);
            $class = $language === $currentLanguage ? ' class="is-active"' : '';
            $current = $language === $currentLanguage ? ' aria-current="true"' : '';
            $links[] = '<a' . $class . $current . ' href="' . mur_escape($url) . '">' . mur_escape($label) . '</a>';
        }

        return '<div class="language-menu">'
            . '<button class="language-toggle" type="button" aria-expanded="false" aria-controls="language-menu-list">'
            . '<svg class="icon" aria-hidden="true" focusable="false"><use href="' . mur_escape($this->assetUrl('icons.svg')) . '#globe"></use></svg>'
            . '<span class="language-current">' . mur_escape((string) $currentLabel) . '</span>'
            . '<span class="visually-hidden">Choose language</span>'
            . '</button>'
            . '<div id="language-menu-list" class="language-menu-list">' . implode('', $links) . '</div>'
            . '</div>';
    }

    private function languageUrl(string $language, ?Document $document): string
    {
        if (!$document instanceof Document) {
            return '/' . $language . '/';
        }

        $translation = $this->context->content->findTranslation($document, $language);

        if ($translation instanceof Document) {
            return '/' . $language . '/' . $translation->urlPath;
        }

        return '/' . $language . '/';
    }

    private function boolMeta(Document $document, string $key, bool $default): bool
    {
        if (!array_key_exists($key, $document->meta)) {
            return $default;
        }

        return in_array(strtolower(trim((string) $document->meta[$key])), ['1', 'true', 'yes', 'on'], true);
    }

    private function optionBool(string $key): bool
    {
        return in_array(strtolower(trim((string) ($this->options[$key] ?? '0'))), ['1', 'true', 'yes', 'on'], true);
    }

    private function safeClass(string $value): string
    {
        return preg_replace('/[^a-z0-9_-]+/', '-', strtolower($value)) ?: 'default';
    }
}
