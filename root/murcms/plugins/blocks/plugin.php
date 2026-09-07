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
        private bool $needsJs = false;

        public function __construct(private readonly PluginContext $context)
        {
            $this->parser = new DocumentParser();
        }

        public function filter(string $html, array $context): string
        {
            $language = (string) ($context['language'] ?? $this->context->languages['default'] ?? '');

            return (string) preg_replace_callback(
                '/\[(counters|carousel|logos)(?:=|:)([a-zA-Z0-9_-]+)\]/',
                fn (array $matches): string => $this->render($matches[1], $matches[2], $language),
                $html,
            );
        }

        public function head(string $html): string
        {
            if (!$this->used) {
                return $html;
            }

            return $html . "\n  " . '<link rel="stylesheet" href="' . mur_escape(mur_asset_url('plugins/blocks/blocks.css')) . '">';
        }

        public function bodyEnd(string $html): string
        {
            if (!$this->needsJs) {
                return $html;
            }

            return $html . "\n  " . '<script src="' . mur_escape(mur_asset_url('plugins/blocks/blocks.js')) . '" defer></script>';
        }

        private function render(string $type, string $name, string $language): string
        {
            $cacheKey = $language . ':' . $type . ':' . $name;

            if (array_key_exists($cacheKey, $this->cache)) {
                $this->used = $this->used || $this->cache[$cacheKey] !== '';
                return $this->cache[$cacheKey];
            }

            $file = $this->findFile($type, $name, $language);

            if ($file === '') {
                return $this->cache[$cacheKey] = '';
            }

            $document = $this->parser->parse($file, 'block', $language);

            if (!$document->isPublished()) {
                return $this->cache[$cacheKey] = '';
            }

            $items = $this->items($document->body);

            if ($items === []) {
                return $this->cache[$cacheKey] = '';
            }

            $this->used = true;

            return $this->cache[$cacheKey] = match ($type) {
                'counters' => $this->renderCounters($document->meta, $items),
                'carousel' => $this->renderCarousel($document->meta, $items, $name, $language),
                'logos' => $this->renderLogos($document->meta, $items),
                default => '',
            };
        }

        private function findFile(string $type, string $name, string $language): string
        {
            $safeType = preg_replace('/[^a-zA-Z0-9_-]+/', '', $type) ?? '';
            $safeName = preg_replace('/[^a-zA-Z0-9_-]+/', '', $name) ?? '';

            if ($safeType === '' || $safeName === '') {
                return '';
            }

            $candidates = [
                $this->context->path('blocks/' . $language . '/' . $safeType . '/' . $safeName . '.mur.md'),
                $this->context->path('blocks/' . $safeType . '/' . $safeName . '.mur.md'),
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

                $items[] = array_map('trim', explode('|', $line));
            }

            return $items;
        }

        private function renderCounters(array $meta, array $items): string
        {
            $this->needsJs = true;
            $title = mur_meta_value($meta, 'title');
            $html = '<section class="mur-counters" data-mur-counters';
            $html .= $title !== '' ? ' aria-label="' . mur_escape($title) . '"' : '';
            $html .= '>';

            if ($title !== '') {
                $html .= '<h2 class="mur-block-title">' . mur_escape($title) . '</h2>';
            }

            $html .= '<div class="mur-counters-grid">';

            foreach ($items as $item) {
                $value = $this->number((string) ($item[0] ?? '0'));
                $label = (string) ($item[1] ?? '');
                $prefix = (string) ($item[2] ?? '');
                $suffix = (string) ($item[3] ?? '');
                $html .= '<div class="mur-counter">';
                $html .= '<strong><span>' . mur_escape($prefix) . '</span><span data-count-to="' . mur_escape((string) $value) . '">0</span><span>' . mur_escape($suffix) . '</span></strong>';
                if ($label !== '') {
                    $html .= '<span class="mur-counter-label">' . mur_escape($label) . '</span>';
                }
                $html .= '</div>';
            }

            return $html . '</div></section>';
        }

        private function renderCarousel(array $meta, array $items, string $name, string $language): string
        {
            $this->needsJs = true;
            $title = mur_meta_value($meta, 'title');
            $text = mur_meta_value($meta, 'text');
            $actionLabel = mur_meta_value($meta, 'action.label');
            $actionUrl = mur_meta_value($meta, 'action.url');
            [$previousLabel, $nextLabel] = $this->carouselLabels($language);
            $html = '<section class="mur-carousel" data-mur-carousel="' . mur_escape($name) . '"';
            $html .= $title !== '' ? ' aria-label="' . mur_escape($title) . '"' : '';
            $html .= '>';

            if ($title !== '' || $text !== '' || ($actionLabel !== '' && $actionUrl !== '')) {
                $html .= '<div class="mur-carousel-header">';
                $html .= '<div>';
                if ($title !== '') {
                    $html .= '<h2 class="mur-block-title">' . mur_escape($title) . '</h2>';
                }
                if ($text !== '') {
                    $html .= '<p>' . mur_escape($text) . '</p>';
                }
                $html .= '</div>';
                if ($actionLabel !== '' && $actionUrl !== '') {
                    $html .= '<a class="button button-ghost" href="' . mur_escape($actionUrl) . '">' . mur_escape($actionLabel) . '</a>';
                }
                $html .= '</div>';
            }

            $html .= '<div class="mur-carousel-shell">';
            $html .= '<button class="mur-carousel-button mur-carousel-button-prev" type="button" aria-label="' . mur_escape($previousLabel) . '" data-carousel-prev>&lsaquo;</button>';
            $html .= '<div class="mur-carousel-track" tabindex="0">';

            foreach ($items as $item) {
                $title = (string) ($item[0] ?? '');
                $description = (string) ($item[1] ?? '');
                $url = (string) ($item[2] ?? '');
                $image = (string) ($item[3] ?? '');
                $alt = (string) ($item[4] ?? $title);
                $tag = $url !== '' ? 'a' : 'div';
                $href = $url !== '' ? ' href="' . mur_escape($url) . '"' : '';

                $html .= '<article class="mur-carousel-item"><' . $tag . ' class="mur-card"' . $href . '>';
                if ($image !== '') {
                    $html .= '<img src="' . mur_escape($image) . '" alt="' . mur_escape($alt) . '" width="480" height="300" loading="lazy" decoding="async">';
                }
                if ($title !== '') {
                    $html .= '<h3>' . mur_escape($title) . '</h3>';
                }
                if ($description !== '') {
                    $html .= '<p>' . mur_escape($description) . '</p>';
                }
                $html .= '</' . $tag . '></article>';
            }

            return $html . '</div><button class="mur-carousel-button mur-carousel-button-next" type="button" aria-label="' . mur_escape($nextLabel) . '" data-carousel-next>&rsaquo;</button></div></section>';
        }

        private function renderLogos(array $meta, array $items): string
        {
            $title = mur_meta_value($meta, 'title');
            $html = '<section class="mur-logos"';
            $html .= $title !== '' ? ' aria-label="' . mur_escape($title) . '"' : '';
            $html .= '>';

            if ($title !== '') {
                $html .= '<h2 class="mur-block-title">' . mur_escape($title) . '</h2>';
            }

            $html .= '<div class="mur-logos-grid">';

            foreach ($items as $item) {
                $image = (string) ($item[0] ?? '');
                $alt = (string) ($item[1] ?? '');
                $url = (string) ($item[2] ?? '');
                $width = max(1, (int) ($item[3] ?? 180));
                $height = max(1, (int) ($item[4] ?? 90));
                $linkTitle = (string) ($item[5] ?? '');
                $target = $this->linkTarget((string) ($item[6] ?? ''));

                if ($image === '') {
                    continue;
                }

                $imageHtml = '<img src="' . mur_escape($image) . '" alt="' . mur_escape($alt) . '" width="' . $width . '" height="' . $height . '" loading="lazy" decoding="async">';
                $html .= '<div class="mur-logo">';
                if ($url !== '') {
                    $linkAttrs = ' href="' . mur_escape($url) . '"';
                    $linkAttrs .= $linkTitle !== '' ? ' title="' . mur_escape($linkTitle) . '"' : '';
                    $linkAttrs .= $target !== '' ? ' target="' . mur_escape($target) . '"' : '';
                    $linkAttrs .= $target === '_blank' ? ' rel="noopener"' : '';
                    $html .= '<a' . $linkAttrs . '>' . $imageHtml . '</a>';
                } else {
                    $html .= $imageHtml;
                }
                $html .= '</div>';
            }

            return $html . '</div></section>';
        }

        private function linkTarget(string $target): string
        {
            $target = trim($target);

            if ($target === '') {
                return '';
            }

            $normalized = strtolower($target);
            $reserved = [
                'blank' => '_blank',
                '_blank' => '_blank',
                'self' => '_self',
                '_self' => '_self',
                'parent' => '_parent',
                '_parent' => '_parent',
                'top' => '_top',
                '_top' => '_top',
            ];

            if (isset($reserved[$normalized])) {
                return $reserved[$normalized];
            }

            return preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,63}$/', $target) === 1 ? $target : '';
        }

        private function number(string $value): int
        {
            return (int) preg_replace('/[^0-9-]+/', '', $value);
        }

        private function carouselLabels(string $language): array
        {
            return match ($language) {
                'ru' => ['Предыдущие элементы', 'Следующие элементы'],
                'ro' => ['Elementele precedente', 'Elementele urmatoare'],
                default => ['Previous items', 'Next items'],
            };
        }
    };

    foreach (['content.home', 'content.document', 'layout.footer'] as $hook) {
        $context->hooks->add($hook, [$plugin, 'filter']);
    }

    $context->hooks->add('layout.head', [$plugin, 'head']);
    $context->hooks->add('layout.body_end', [$plugin, 'bodyEnd']);

    $context->hooks->add('admin.menu', static function (array $items): array {
        $items[] = [
            'id' => 'plugin-blocks',
            'label' => 'Blocks',
            'url' => '/admin/plugin/blocks',
            'role' => 'editor',
        ];

        return $items;
    });

    $context->hooks->add('admin.file_collections', static function (array $collections): array {
        $collections['blocks'] = [
            'label' => 'Blocks',
            'menu_id' => 'plugin-blocks',
            'route' => '/admin/plugin/blocks',
            'role' => 'editor',
            'languages' => true,
            'allow_global' => true,
            'recursive' => false,
            'targets' => [
                'carousel' => ['label' => 'Carousel', 'directory' => 'blocks/{lang}/carousel', 'global_directory' => 'blocks/carousel'],
                'counters' => ['label' => 'Counters', 'directory' => 'blocks/{lang}/counters', 'global_directory' => 'blocks/counters'],
                'logos' => ['label' => 'Logos', 'directory' => 'blocks/{lang}/logos', 'global_directory' => 'blocks/logos'],
            ],
            'hint' => 'Формат зависит от типа: carousel — <code>title | description | url | image | alt</code>; counters — <code>value | label | prefix | suffix</code>; logos — <code>image | alt | url | width | height | link_title | target</code>.',
        ];

        return $collections;
    });

    $context->registerAdminRoute([
        'path' => '/admin/plugin/blocks',
        'role' => 'editor',
        'handler' => static function (mixed $html, array $adminContext): mixed {
            $render = $adminContext['render_file_collection'] ?? null;
            return is_callable($render) ? $render('blocks') : $html;
        },
    ]);
};
