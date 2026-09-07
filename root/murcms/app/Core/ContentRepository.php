<?php
declare(strict_types=1);

namespace Mur\Core;

if (!defined('MUR_CMS_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('No direct script access allowed.');
}

final class ContentRepository
{
    private const COLLECTIONS = ['pages', 'categories', 'services', 'info', 'news'];

    private DocumentParser $parser;
    private array $documents = [];
    private array $byId = [];
    private array $byPath = [];
    private array $byTranslation = [];
    private bool $loaded = false;

    public function __construct(
        private readonly string $contentPath,
        private readonly array $languages,
    ) {
        $this->parser = new DocumentParser();
    }

    public function all(string $language): array
    {
        $this->load();
        return $this->documents[$language] ?? [];
    }

    public function published(string $language): array
    {
        return array_values(array_filter(
            $this->all($language),
            static fn (Document $document): bool => $document->isPublished(),
        ));
    }

    public function homeBlocks(string $language, ?array $ids = null, ?string $fallbackLanguage = null): array
    {
        return $this->blocks($language, 'home', $ids, $fallbackLanguage);
    }

    public function footerBlocks(string $language, ?array $ids = null, ?string $fallbackLanguage = null): array
    {
        return $this->blocks($language, 'footer', $ids, $fallbackLanguage);
    }

    private function blocks(string $language, string $name, ?array $ids, ?string $fallbackLanguage): array
    {
        if ($ids !== null) {
            $primary = $this->blockIndex($language, $name);
            $fallback = $fallbackLanguage !== null && $fallbackLanguage !== $language
                ? $this->blockIndex($fallbackLanguage, $name)
                : [];
            $blocks = [];

            foreach ($ids as $id) {
                $id = trim((string) $id);
                if ($id === '') {
                    continue;
                }

                $block = $primary[$id] ?? $fallback[$id] ?? null;
                if ($block instanceof Document) {
                    $blocks[] = $block;
                }
            }

            return $blocks;
        }

        return [];
    }

    private function blockIndex(string $language, string $name): array
    {
        $dir = $this->contentPath . '/' . $language . '/' . $name;

        if (!is_dir($dir)) {
            return [];
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile() && str_ends_with($file->getFilename(), '.mur.md')) {
                $files[] = $file->getPathname();
            }
        }

        sort($files, SORT_NATURAL);
        $blocks = [];

        foreach ($files as $file) {
            $block = $this->parser->parse($file, $name, $language);
            $blockName = str_ends_with($block->fileName, '.mur.md')
                ? substr($block->fileName, 0, -7)
                : pathinfo($block->fileName, PATHINFO_FILENAME);
            if (!array_key_exists($blockName, $blocks)) {
                $blocks[$blockName] = $block;
            }
        }

        return $blocks;
    }

    public function findByPath(string $language, string $path): ?Document
    {
        $this->load();
        $key = trim($path, '/');
        return $this->byPath[$language][$key] ?? null;
    }

    public function findById(string $language, string $id): ?Document
    {
        $this->load();
        return $this->byId[$language][$id] ?? null;
    }

    public function findTranslation(Document $document, string $language): ?Document
    {
        $key = $document->translationKey();

        if ($key === '') {
            return null;
        }

        $this->load();

        return $this->byTranslation[$language][$key] ?? null;
    }

    public function childrenOf(Document $document, bool $publishedOnly = true): array
    {
        $children = array_values(array_filter(
            $this->all($document->language),
            static fn (Document $candidate): bool => $candidate->parentId() === $document->id(),
        ));

        if ($publishedOnly) {
            $children = array_values(array_filter(
                $children,
                static fn (Document $candidate): bool => $candidate->isPublished(),
            ));
        }

        return $children;
    }

    public function inCategory(Document $category, bool $publishedOnly = true): array
    {
        $documents = array_values(array_filter(
            $this->all($category->language),
            static fn (Document $candidate): bool => $candidate->collection === 'pages'
                && in_array($category->id(), $candidate->categoryIds(), true),
        ));

        if ($publishedOnly) {
            $documents = array_values(array_filter(
                $documents,
                static fn (Document $candidate): bool => $candidate->isPublished(),
            ));
        }

        return $documents;
    }

    public function sort(array $documents, string $field = 'order', string $direction = 'asc'): array
    {
        $direction = strtolower($direction) === 'desc' ? 'desc' : 'asc';

        usort($documents, static function (Document $a, Document $b) use ($field, $direction): int {
            $result = match ($field) {
                'date' => strcmp((string) ($a->meta['date'] ?? ''), (string) ($b->meta['date'] ?? '')),
                'updated_at' => strcmp((string) ($a->meta['updated_at'] ?? ''), (string) ($b->meta['updated_at'] ?? '')),
                'title' => strnatcasecmp($a->title(), $b->title()),
                'id' => strnatcasecmp($a->id(), $b->id()),
                'filename' => strnatcasecmp($a->fileName, $b->fileName),
                default => [(int) ($a->meta['order'] ?? $a->meta['menu.order'] ?? 1000), $a->title()]
                    <=> [(int) ($b->meta['order'] ?? $b->meta['menu.order'] ?? 1000), $b->title()],
            };

            return $direction === 'desc' ? -$result : $result;
        });

        return $documents;
    }

    public function refresh(): void
    {
        $this->documents = [];
        $this->byId = [];
        $this->byPath = [];
        $this->byTranslation = [];
        $this->loaded = false;
    }

    private function load(): void
    {
        if ($this->loaded) {
            return;
        }

        foreach ($this->languages['enabled'] as $language) {
            foreach (self::COLLECTIONS as $collection) {
                $this->loadCollection((string) $language, $collection);
            }
        }

        $this->buildUrls();
        $this->loaded = true;
    }

    private function loadCollection(string $language, string $collection): void
    {
        $dir = $this->contentPath . '/' . $language . '/' . $collection;

        if (!is_dir($dir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );
        $files = [];

        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile()) {
                continue;
            }

            if (!str_ends_with($file->getFilename(), '.mur.md')) {
                continue;
            }

            $files[] = $file->getPathname();
        }

        sort($files, SORT_NATURAL);

        foreach ($files as $file) {
            $document = $this->parser->parse($file, $collection, $language);
            $this->documents[$language][] = $document;
            $this->byId[$language][$document->id()] ??= $document;

            $translationKey = $document->translationKey();
            if ($document->isPublished() && $translationKey !== '') {
                $this->byTranslation[$language][$translationKey] ??= $document;
            }
        }
    }

    private function buildUrls(): void
    {
        foreach ($this->documents as $language => $documents) {
            foreach ($documents as $document) {
                $path = $this->buildPath($document);
                $document->urlPath = $path;
                $this->byPath[$language][$path] ??= $document;
            }
        }
    }

    private function buildPath(Document $document): string
    {
        $parts = [$document->slug()];
        $parentId = $document->parentId();
        $guard = 0;

        while ($parentId !== '' && $guard < 10) {
            $parent = $this->byId[$document->language][$parentId] ?? null;

            if (!$parent instanceof Document) {
                break;
            }

            array_unshift($parts, $parent->slug());
            $parentId = $parent->parentId();
            $guard++;
        }

        $prefix = match ($document->collection) {
            'pages' => '',
            'services' => 'services',
            'info' => 'info',
            'news' => 'news',
            'categories' => 'category',
            default => $document->collection,
        };

        if ($prefix !== '') {
            array_unshift($parts, $prefix);
        }

        return implode('/', array_filter($parts));
    }
}
