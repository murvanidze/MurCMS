<?php
declare(strict_types=1);

namespace Mur\Core;

if (!defined('MUR_CMS_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('No direct script access allowed.');
}

final class Router
{
    public function __construct(
        private readonly array $site,
        private readonly array $languages,
        private readonly ContentRepository $content,
        private readonly Renderer $renderer,
        private readonly SeoManager $seo,
        private readonly Hooks $hooks,
    ) {
    }

    public function handle(string $requestUri): void
    {
        $path = parse_url($requestUri, PHP_URL_PATH);
        $path = is_string($path) ? '/' . trim($path, '/') : '/';

        if ($path === '/robots.txt') {
            $this->robots();
            return;
        }

        if ($path === '/sitemap.xml') {
            $this->sitemap();
            return;
        }

        if ($path === '/') {
            $default = (string) $this->languages['default'];
            http_response_code((int) ($this->site['home_redirect_status'] ?? 302));
            header('Location: /' . $default . '/');
            return;
        }

        $segments = array_values(array_filter(explode('/', trim($path, '/'))));
        $language = $segments[0] ?? '';

        if (!in_array($language, $this->languages['enabled'], true)) {
            $fallbackLanguage = (string) $this->languages['default'];
            $legacyPath = implode('/', $segments);
            $custom = $this->hooks->filter('route.public', null, [
                'language' => $fallbackLanguage,
                'segments' => $segments,
                'path' => $legacyPath,
                'renderer' => $this->renderer,
            ]);

            if (is_string($custom)) {
                echo $custom;
                return;
            }

            $notFound = $this->hooks->filter('route.not_found', null, [
                'language' => $fallbackLanguage,
                'segments' => $segments,
                'path' => $legacyPath,
                'renderer' => $this->renderer,
            ]);

            if (is_string($notFound)) {
                echo $notFound;
                return;
            }

            echo $this->renderer->renderNotFound((string) $this->languages['default']);
            return;
        }

        $contentPath = implode('/', array_slice($segments, 1));

        if ($contentPath === '') {
            echo $this->renderer->renderHome($language);
            return;
        }

        $custom = $this->hooks->filter('route.public', null, [
            'language' => $language,
            'segments' => array_slice($segments, 1),
            'path' => $contentPath,
            'renderer' => $this->renderer,
        ]);

        if (is_string($custom)) {
            echo $custom;
            return;
        }

        $document = $this->content->findByPath($language, $contentPath);

        if ($document instanceof Document && $document->isPublished()) {
            echo $this->renderer->renderDocument($document);
            return;
        }

        $notFound = $this->hooks->filter('route.not_found', null, [
            'language' => $language,
            'segments' => array_slice($segments, 1),
            'path' => $contentPath,
            'renderer' => $this->renderer,
        ]);

        if (is_string($notFound)) {
            echo $notFound;
            return;
        }

        echo $this->renderer->renderNotFound($language);
    }

    private function robots(): void
    {
        header('Content-Type: text/plain; charset=utf-8');

        echo $this->seo->robots();
    }

    private function sitemap(): void
    {
        header('Content-Type: application/xml; charset=utf-8');

        echo $this->seo->sitemapXml();
    }
}
