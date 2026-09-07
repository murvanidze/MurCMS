<?php
declare(strict_types=1);

if (!defined('MUR_CMS_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('No direct script access allowed.');
}

use Mur\Core\DocumentParser;
use Mur\Core\PluginContext;

return static function (PluginContext $context): void {
    $plugin = new class ($context) {
        private DocumentParser $parser;
        private array $cache = [];
        private bool $used = false;

        public function __construct(private readonly PluginContext $context)
        {
            $this->parser = new DocumentParser();
        }

        public function filter(string $html, array $context): string
        {
            $language = (string) ($context['language'] ?? $this->context->languages['default'] ?? '');

            return (string) preg_replace_callback(
                '/\[gallery(?:=|:)([a-zA-Z0-9_-]+)\]/',
                fn (array $matches): string => $this->render($matches[1], $language),
                $html,
            );
        }

        public function head(string $html): string
        {
            if (!$this->used) {
                return $html;
            }

            return $html . "\n  " . '<link rel="stylesheet" href="' . mur_escape(mur_asset_url('plugins/gallery/gallery.css')) . '">';
        }

        public function bodyEnd(string $html): string
        {
            if (!$this->used) {
                return $html;
            }

            return $html . "\n  " . '<script src="' . mur_escape(mur_asset_url('plugins/gallery/gallery.js')) . '" defer></script>';
        }

        private function render(string $name, string $language): string
        {
            $cacheKey = $language . ':' . $name;

            if (array_key_exists($cacheKey, $this->cache)) {
                $this->used = $this->used || $this->cache[$cacheKey] !== '';
                return $this->cache[$cacheKey];
            }

            $file = $this->findFile($name, $language);

            if ($file === '') {
                return $this->cache[$cacheKey] = '';
            }

            $document = $this->parser->parse($file, 'gallery', $language);

            if (!$document->isPublished()) {
                return $this->cache[$cacheKey] = '';
            }

            $items = $this->items($document->body);

            if ($items === []) {
                return $this->cache[$cacheKey] = '';
            }

            $this->used = true;
            $title = trim((string) ($document->meta['title'] ?? ''));
            $columns = (int) ($document->meta['columns'] ?? 3);
            $columns = max(1, min(6, $columns));
            $captionMode = strtolower(trim((string) ($document->meta['captions'] ?? 'visible')));
            $class = 'mur-gallery mur-gallery-cols-' . $columns;

            if ($captionMode === 'hidden') {
                $class .= ' mur-gallery-captions-hidden';
            }

            $html = '<section class="' . mur_escape($class) . '" data-mur-gallery="' . mur_escape($name) . '"';

            if ($title !== '') {
                $html .= ' aria-label="' . mur_escape($title) . '"';
            }

            $html .= '>';

            foreach ($items as $index => $item) {
                $html .= $this->item($item, $index);
            }

            return $this->cache[$cacheKey] = $html . '</section>';
        }

        private function findFile(string $name, string $language): string
        {
            $safeName = preg_replace('/[^a-zA-Z0-9_-]+/', '', $name) ?? '';

            if ($safeName === '') {
                return '';
            }

            $candidates = [
                $this->context->path('galleries/' . $language . '/' . $safeName . '.mur.md'),
                $this->context->path('galleries/' . $safeName . '.mur.md'),
            ];

            foreach ($candidates as $candidate) {
                if (is_file($candidate)) {
                    return $candidate;
                }
            }

            return '';
        }

        private function items(string $body): array
        {
            $items = [];

            foreach (preg_split('/\R/u', trim($body)) ?: [] as $line) {
                $line = trim($line);

                if ($line === '' || str_starts_with($line, '#')) {
                    continue;
                }

                $parts = array_map('trim', explode('|', $line));
                $src = (string) ($parts[0] ?? '');

                if ($src === '') {
                    continue;
                }

                $items[] = [
                    'src' => $src,
                    'thumb' => (string) ($parts[1] ?? $src),
                    'alt' => (string) ($parts[2] ?? ''),
                    'caption' => (string) ($parts[3] ?? ($parts[2] ?? '')),
                    'credit' => (string) ($parts[4] ?? ''),
                    'width' => $this->positiveInt((string) ($parts[5] ?? ''), 640),
                    'height' => $this->positiveInt((string) ($parts[6] ?? ''), 480),
                ];
            }

            return $items;
        }

        private function positiveInt(string $value, int $fallback): int
        {
            $number = (int) trim($value);

            return $number > 0 ? $number : $fallback;
        }

        private function item(array $item, int $index): string
        {
            $caption = trim((string) $item['caption']);
            $credit = trim((string) $item['credit']);
            $fullCaption = trim($caption . ($credit !== '' ? ' / ' . $credit : ''));
            $alt = trim((string) $item['alt']);

            if ($alt === '') {
                $alt = $caption !== '' ? $caption : 'Gallery image';
            }

            $html = '<figure class="mur-gallery-item">';
            $html .= '<a class="mur-gallery-link" href="' . mur_escape($item['src']) . '" data-index="' . $index . '" data-caption="' . mur_escape($fullCaption) . '">';
            $html .= '<img src="' . mur_escape($item['thumb']) . '" alt="' . mur_escape($alt) . '" width="' . (int) $item['width'] . '" height="' . (int) $item['height'] . '" loading="lazy" decoding="async" fetchpriority="low">';
            $html .= '</a>';

            if ($caption !== '' || $credit !== '') {
                $html .= '<figcaption class="mur-gallery-caption">';
                if ($caption !== '') {
                    $html .= '<span>' . mur_escape($caption) . '</span>';
                }
                if ($credit !== '') {
                    $html .= '<small>' . mur_escape($credit) . '</small>';
                }
                $html .= '</figcaption>';
            }

            return $html . '</figure>';
        }
    };

    foreach (['content.home', 'content.document'] as $hook) {
        $context->hooks->add($hook, [$plugin, 'filter']);
    }

    $context->hooks->add('layout.head', [$plugin, 'head']);
    $context->hooks->add('layout.body_end', [$plugin, 'bodyEnd']);

    $context->hooks->add('admin.menu', static function (array $items): array {
        $items[] = [
            'id' => 'plugin-gallery',
            'label' => 'Gallery',
            'url' => '/admin/plugin/gallery',
            'role' => 'editor',
        ];

        return $items;
    });

    $context->hooks->add('admin.file_collections', static function (array $collections): array {
        $collections['gallery'] = [
            'label' => 'Gallery',
            'menu_id' => 'plugin-gallery',
            'route' => '/admin/plugin/gallery',
            'role' => 'editor',
            'languages' => true,
            'allow_global' => true,
            'recursive' => false,
            'targets' => [
                'default' => ['label' => 'Gallery', 'directory' => 'galleries/{lang}', 'global_directory' => 'galleries'],
            ],
            'hint' => 'Формат gallery: <code>full | thumb | alt | caption | credit | thumb_width | thumb_height</code>.',
        ];

        return $collections;
    });

    $context->registerAdminRoute([
        'path' => '/admin/plugin/gallery',
        'role' => 'editor',
        'handler' => static function (mixed $html, array $adminContext): mixed {
            $render = $adminContext['render_file_collection'] ?? null;
            return is_callable($render) ? $render('gallery') : $html;
        },
    ]);
};
