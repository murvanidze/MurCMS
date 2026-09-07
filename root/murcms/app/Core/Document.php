<?php
declare(strict_types=1);

namespace Mur\Core;

if (!defined('MUR_CMS_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('No direct script access allowed.');
}

final class Document
{
    public function __construct(
        public readonly string $collection,
        public readonly string $language,
        public readonly string $filePath,
        public readonly string $fileName,
        public readonly array $meta,
        public readonly string $body,
        public string $urlPath = '',
    ) {
    }

    public function id(): string
    {
        return (string) ($this->meta['id'] ?? pathinfo($this->fileName, PATHINFO_FILENAME));
    }

    public function slug(): string
    {
        return (string) ($this->meta['slug'] ?? $this->id());
    }

    public function title(): string
    {
        return (string) ($this->meta['title'] ?? $this->id());
    }

    public function status(): string
    {
        return (string) ($this->meta['status'] ?? 'draft');
    }

    public function isPublished(): bool
    {
        return strtolower($this->status()) === 'published';
    }

    public function translationKey(): string
    {
        return trim((string) ($this->meta['translation_key'] ?? ''));
    }

    public function parentId(): string
    {
        return trim((string) ($this->meta['parent'] ?? ''));
    }

    public function categoryIds(): array
    {
        $value = trim((string) ($this->meta['categories'] ?? ''));

        if ($value === '') {
            return [];
        }

        return array_values(array_unique(array_filter(
            array_map('trim', explode(',', $value)),
            static fn (string $id): bool => $id !== '',
        )));
    }
}
