<?php
declare(strict_types=1);

namespace Mur\Core;

if (!defined('MUR_CMS_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('No direct script access allowed.');
}

final class Menu
{
    public function __construct(
        private readonly string $menuPath,
        private readonly ContentRepository $content,
    ) {
    }

    public function render(string $language, string $name, string $currentPath): string
    {
        $file = $this->menuPath . '/' . $language . '/' . $name . '.mur.md';

        if (!is_file($file)) {
            return '';
        }

        $document = (new DocumentParser())->parse($file, 'menu', $language);
        if (!$document->isPublished()) {
            return '';
        }

        $items = $this->parseItems($document->body, $language);

        return $items !== []
            ? '<ul class="site-menu">' . $this->renderItems($items, $currentPath, 'site-submenu', 0, $name === 'main') . '</ul>'
            : '';
    }

    private function parseItems(string $body, string $language): array
    {
        $root = [];
        $stack = [&$root];
        $groupNext = false;

        foreach (preg_split('/\R/u', trim($body)) ?: [] as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            if ($line === '[') {
                $groupNext = true;
                continue;
            }

            if ($line === ']') {
                if (count($stack) > 1) {
                    array_pop($stack);
                }
                continue;
            }

            if ($line === '---') {
                $stack[array_key_last($stack)][] = ['divider' => true];
                continue;
            }

            $fields = array_map('trim', explode('|', $line));
            $hasLongLabel = count($fields) >= 7;
            $offset = $hasLongLabel ? 1 : 0;
            $item = [
                'href' => $this->resolveHref($fields[0] ?? '#', $language),
                'label' => $fields[1] ?? '',
                'label_long' => $hasLongLabel ? ($fields[2] ?? '') : '',
                'title' => $fields[2 + $offset] ?? '',
                'class' => $fields[3 + $offset] ?? '',
                'span_class' => $fields[4 + $offset] ?? '',
                'attrs' => $fields[5 + $offset] ?? '',
                'children' => [],
            ];

            $stack[array_key_last($stack)][] = $item;
            $index = array_key_last($stack[array_key_last($stack)]);

            if ($groupNext) {
                $stack[] = &$stack[array_key_last($stack)][$index]['children'];
                $groupNext = false;
            }
        }

        return $root;
    }

    private function resolveHref(string $value, string $language): string
    {
        $value = trim($value);

        if ($value === '') {
            return '/' . $language . '/';
        }

        if ($value === '#' || str_starts_with($value, 'http://') || str_starts_with($value, 'https://')) {
            return $value;
        }

        if (str_contains($value, ':')) {
            [$kind, $id] = explode(':', $value, 2);
            $document = $this->content->findById($language, trim($id));

            if ($document instanceof Document) {
                return '/' . $language . '/' . $document->urlPath;
            }
        }

        return str_starts_with($value, '/') ? $value : '/' . $language . '/' . ltrim($value, '/');
    }

    private function renderItems(
        array $items,
        string $currentPath,
        string $prefix,
        int $depth,
        bool $useResponsiveLabels,
    ): string
    {
        $html = '';

        foreach ($items as $index => $item) {
            if (!empty($item['divider'])) {
                $html .= '<li class="menu-divider" aria-hidden="true"></li>';
                continue;
            }

            $href = (string) $item['href'];
            $children = $item['children'] ?? [];
            if ($href === '#' && $children !== []) {
                $href = $this->firstNavigableHref($children) ?? $href;
            }
            $active = rtrim($href, '/') === rtrim($currentPath, '/');
            $class = trim(($item['class'] ?? '') . ($active ? ' is-active' : '') . ($children !== [] ? ' has-children' : ''));
            $title = $item['title'] !== '' ? ' title="' . mur_escape($item['title']) . '"' : '';
            $attrs = $item['attrs'] !== '' ? ' ' . $item['attrs'] : '';
            $spanClass = $item['span_class'] !== '' ? ' class="' . mur_escape($item['span_class']) . '"' : '';
            $submenuId = $prefix . '-' . $index;

            $html .= '<li' . ($class !== '' ? ' class="' . mur_escape($class) . '"' : '') . '>';
            $html .= '<div class="site-menu-row">';
            $html .= '<a href="' . mur_escape($href) . '"' . $title . $attrs . '>';
            $html .= $this->renderLabel($item, $spanClass, $useResponsiveLabels && $depth === 0);
            $html .= '</a>';

            if ($children !== []) {
                $html .= '<button class="submenu-toggle" type="button" aria-expanded="false" aria-controls="' . mur_escape($submenuId) . '">';
                $html .= '<span class="visually-hidden">Toggle submenu</span>';
                $html .= '</button>';
            }

            $html .= '</div>';

            if ($children !== []) {
                $html .= '<ul id="' . mur_escape($submenuId) . '">'
                    . $this->renderItems($children, $currentPath, $submenuId, $depth + 1, $useResponsiveLabels)
                    . '</ul>';
            }

            $html .= '</li>';
        }

        return $html;
    }

    private function renderLabel(array $item, string $spanClass, bool $responsive): string
    {
        $short = mur_escape((string) ($item['label'] ?? ''));
        $long = trim((string) ($item['label_long'] ?? ''));

        if (!$responsive || $long === '') {
            return '<span' . $spanClass . '>' . $short . '</span>';
        }

        $customClass = trim((string) ($item['span_class'] ?? ''));
        $shortClass = trim($customClass . ' menu-label-short');
        $longClass = trim($customClass . ' menu-label-long');

        return '<span class="' . mur_escape($shortClass) . '">' . $short . '</span>'
            . '<span class="' . mur_escape($longClass) . '">' . mur_escape($long) . '</span>';
    }

    private function firstNavigableHref(array $items): ?string
    {
        foreach ($items as $item) {
            if (!empty($item['divider'])) {
                continue;
            }

            $href = trim((string) ($item['href'] ?? ''));
            if ($href !== '' && $href !== '#') {
                return $href;
            }
        }

        return null;
    }
}
