<?php
declare(strict_types=1);

namespace Mur\Core;

if (!defined('MUR_CMS_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('No direct script access allowed.');
}

final class Renderer
{
    private ?array $activePagination = null;

    public function __construct(
        private readonly array $site,
        private readonly array $languages,
        private readonly ContentRepository $content,
        private readonly Menu $menu,
        private readonly Hooks $hooks,
        private readonly FrontendTemplate $template,
        private readonly array $theme = [],
    ) {
    }

    public function renderHome(string $language): string
    {
        $blocks = $this->content->homeBlocks(
            $language,
            $this->configuredBlockIds('home', $language),
            (string) ($this->languages['default'] ?? $language),
        );
        $body = '';

        foreach ($blocks as $block) {
            $body .= "\n" . mur_render_body($block->body, $this->hooks, [
                'language' => $language,
                'document' => $block,
                'collection' => $block->collection,
                'surface' => 'home',
            ]);
        }

        $body = $this->hooks->filter('content.home', $body, ['language' => $language]);

        return $this->template->renderLayout($language, null, $body, [
            'title' => $this->site['name'],
            'description' => $this->site['description'] ?? '',
        ]);
    }

    private function configuredBlockIds(string $kind, string $language): ?array
    {
        $active = (string) ($this->theme['active'] ?? 'default');
        $options = $this->theme['templates'][$active]['options'] ?? [];
        $layout = is_array($options['block_layout'][$kind] ?? null) ? $options['block_layout'][$kind] : null;

        if ($layout === null) {
            return null;
        }

        $key = ($this->languages['structure'] ?? 'flexible') === 'mirror'
            ? (string) ($this->languages['default'] ?? $language)
            : $language;

        return array_key_exists($key, $layout) && is_array($layout[$key]) ? $layout[$key] : [];
    }

    public function renderDocument(Document $document): string
    {
        $this->activePagination = null;
        $context = [
            'language' => $document->language,
            'document' => $document,
        ];
        $articleStart = $this->hooks->render('content.article_start', $context);
        $articleEnd = $this->hooks->render('content.article_end', $context);
        $documentBody = mur_render_body($document->body, $this->hooks, [
            'language' => $document->language,
            'document' => $document,
            'collection' => $document->collection,
            'surface' => 'document',
        ]);

        if ($this->boolMeta($document, 'page.header', true)) {
            $documentBody = preg_replace('/^\s*<h1>(.*?)<\/h1>\s*/s', '', $documentBody, 1) ?? $documentBody;
        }

        $listing = $this->documentListing($document);
        $position = $listing !== '' ? $this->listingOption($document, $this->listingSource($document), 'position', 'bottom') : 'bottom';

        if ($position === 'top') {
            $documentBody = $listing . $documentBody;
        } elseif ($position === 'shortcode') {
            $documentBody = str_replace('[list]', $listing, $documentBody);
        } else {
            $documentBody = str_replace('[list]', '', $documentBody) . $listing;
        }

        $body = $this->template->documentFrame(
            $document,
            $articleStart . $documentBody . $articleEnd,
        );
        $body = $this->hooks->filter('content.document', $body, [
            'language' => $document->language,
            'document' => $document,
        ]);

        return $this->template->renderLayout($document->language, $document, $body, [
            'title' => $document->meta['seo.title'] ?? $document->title(),
            'description' => $document->meta['seo.description'] ?? '',
            'og_image' => $document->meta['og.image'] ?? '',
        ], $this->activePagination);
    }

    public function renderVirtualPage(
        string $language,
        string $title,
        string $body,
        string $description = '',
        ?array $pagination = null,
    ): string
    {
        return $this->template->renderLayout($language, null, $body, [
            'title' => $title,
            'description' => $description,
        ], $pagination);
    }

    public function renderNotFound(string $language): string
    {
        http_response_code(404);

        return $this->template->renderLayout($language, null, '<h1>404</h1><p>Page not found.</p>', [
            'title' => '404',
            'description' => '',
        ]);
    }

    private function documentListing(Document $document): string
    {
        $source = $this->listingSource($document);

        if ($source === '') {
            return '';
        }

        $items = match ($source) {
            'children' => $this->content->childrenOf($document),
            'category' => $document->collection === 'categories' ? $this->content->inCategory($document) : [],
            default => [],
        };
        $items = $this->content->sort(
            $items,
            strtolower($this->listingOption($document, $source, 'sort', 'order')),
            strtolower($this->listingOption($document, $source, 'direction', 'asc')),
        );

        $limit = (int) ($document->meta['list.limit'] ?? 0);
        if ($limit > 0) {
            $items = array_slice($items, 0, $limit);
        }

        $pagination = '';
        $perPage = max(0, min(100, (int) $this->listingOption($document, $source, 'per_page', '')));

        if ($perPage > 0 && $items !== []) {
            $totalItems = count($items);
            $totalPages = max(1, (int) ceil($totalItems / $perPage));
            $requestedPage = max(1, (int) ($_GET['page'] ?? 1));
            $page = min($requestedPage, $totalPages);
            $items = array_slice($items, ($page - 1) * $perPage, $perPage);
            $this->activePagination = [
                'page' => $page,
                'total_pages' => $totalPages,
                'total_items' => $totalItems,
                'per_page' => $perPage,
            ];
            $pagination = $this->pagination($page, $totalPages);
        }

        $context = [
            'language' => $document->language,
            'document' => $document,
            'items' => $items,
            'pagination' => $this->activePagination,
        ];
        $custom = $this->hooks->filter('listing.items', null, $context);

        if (is_string($custom)) {
            return $custom . $pagination;
        }

        if ($items === []) {
            return '';
        }

        $view = strtolower($this->listingOption($document, $source, 'view', 'cards'));
        $titlesOnly = $view === 'titles';
        $showImage = !$titlesOnly && $this->boolMeta($document, 'list.show_image', $view === 'cards');
        $showDescription = !$titlesOnly && $this->boolMeta($document, 'list.show_description', true);
        $class = 'content-list content-list-' . preg_replace('/[^a-z0-9_-]+/', '-', $view);

        $html = '<section class="' . mur_escape($class) . '" aria-label="' . mur_escape($document->title()) . '">';

        foreach ($items as $item) {
            $html .= $this->listingItem($item, $view, $showImage, $showDescription);
        }

        return $html . '</section>' . $pagination;
    }

    private function pagination(int $currentPage, int $totalPages): string
    {
        if ($totalPages <= 1) {
            return '';
        }

        $visible = [1, $totalPages];
        for ($page = max(1, $currentPage - 2); $page <= min($totalPages, $currentPage + 2); $page++) {
            $visible[] = $page;
        }
        $visible = array_values(array_unique($visible));
        sort($visible);

        $items = '';
        $previous = 0;

        if ($currentPage > 1) {
            $items .= '<a class="pagination-direction" href="' . mur_escape($this->paginationUrl($currentPage - 1)) . '" rel="prev" aria-label="Previous page">‹</a>';
        }

        foreach ($visible as $page) {
            if ($previous > 0 && $page > $previous + 1) {
                $items .= '<span class="pagination-ellipsis" aria-hidden="true">…</span>';
            }

            if ($page === $currentPage) {
                $items .= '<span class="pagination-page is-current" aria-current="page">' . $page . '</span>';
            } else {
                $items .= '<a class="pagination-page" href="' . mur_escape($this->paginationUrl($page)) . '">' . $page . '</a>';
            }
            $previous = $page;
        }

        if ($currentPage < $totalPages) {
            $items .= '<a class="pagination-direction" href="' . mur_escape($this->paginationUrl($currentPage + 1)) . '" rel="next" aria-label="Next page">›</a>';
        }

        return '<nav class="pagination" aria-label="Pagination">' . $items . '</nav>';
    }

    private function paginationUrl(int $page): string
    {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

        return $page > 1 ? $path . '?page=' . $page : $path;
    }

    private function listingItem(Document $item, string $view, bool $showImage, bool $showDescription): string
    {
        $url = '/' . $item->language . '/' . $item->urlPath;
        $title = mur_escape($item->title());
        $description = trim((string) ($item->meta['summary'] ?? $item->meta['description'] ?? $item->meta['seo.description'] ?? ''));
        $image = trim((string) ($item->meta['image'] ?? $item->meta['hero.image'] ?? $item->meta['og.image'] ?? ''));
        $date = trim((string) ($item->meta['date'] ?? ''));
        $html = '<article class="content-list-item">';

        if ($showImage && $image !== '') {
            $html .= '<a class="content-list-image" href="' . mur_escape($url) . '"><img src="' . mur_escape($image) . '" alt="' . $title . '" loading="lazy" decoding="async"></a>';
        }

        $html .= '<div class="content-list-body">';

        if ($date !== '' && $view === 'feed') {
            $html .= '<time class="content-list-date" datetime="' . mur_escape($date) . '">' . mur_escape($date) . '</time>';
        }

        $headingLevel = $view === 'titles' ? 'h3' : 'h2';
        $html .= '<' . $headingLevel . ' class="content-list-title"><a href="' . mur_escape($url) . '">' . $title . '</a></' . $headingLevel . '>';

        if ($showDescription && $description !== '') {
            $html .= '<p class="content-list-description">' . mur_escape($description) . '</p>';
        }

        $html .= '</div></article>';

        return $html;
    }

    private function boolMeta(Document $document, string $key, bool $default): bool
    {
        if (!array_key_exists($key, $document->meta)) {
            return $default;
        }

        return in_array(strtolower(trim((string) $document->meta[$key])), ['1', 'true', 'yes', 'on'], true);
    }

    private function listingSource(Document $document): string
    {
        $template = strtolower(trim((string) ($document->meta['template'] ?? '')));
        $source = strtolower(trim((string) ($document->meta['list.source'] ?? '')));

        if ($template !== 'listing' && $template !== 'category' && $source === '') {
            return '';
        }

        return $source !== '' ? $source : ($document->collection === 'categories' ? 'category' : 'children');
    }

    private function listingOption(Document $document, string $source, string $key, string $default): string
    {
        $metaKey = 'list.' . $key;
        $value = trim((string) ($document->meta[$metaKey] ?? ''));

        if ($value !== '') {
            return $value;
        }

        $active = (string) ($this->theme['active'] ?? 'default');
        $defaults = $this->theme['templates'][$active]['options']['list_defaults'][$source] ?? [];
        $value = is_array($defaults) ? trim((string) ($defaults[$key] ?? '')) : '';

        return $value !== '' ? $value : $default;
    }

}
