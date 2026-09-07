<?php

declare(strict_types=1);

namespace MurCMS\MurTextPlugin;

use Mur\Core\Document;
use Mur\Core\PluginContext;
use MurCMS\MurText\MurText;
use MurCMS\MurText\ParseResult;

if (!defined('MUR_CMS_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('No direct script access allowed.');
}

/**
 * Connects the standalone MurText core to MurCMS rendering hooks.
 */
final class PluginAdapter
{
    private array $config;
    private array $renderCache = [];
    private bool $coreLoaded = false;

    public function __construct(
        private readonly PluginContext $context,
        private readonly string $pluginPath,
        private readonly ConfigStore $configStore,
    ) {
        $this->config = $this->configStore->config();
    }

    public function renderBody(mixed $rendered, array $hookContext): mixed
    {
        if (is_string($rendered)) {
            return $rendered;
        }

        $source = (string) ($hookContext['source'] ?? '');
        $document = $hookContext['document'] ?? null;
        $meta = $document instanceof Document ? $document->meta : [];
        $collection = $this->collection($hookContext, $document);

        if (!$this->shouldParse($meta, $collection)) {
            return null;
        }

        if ($collection === 'snippet' && strtolower((string) ($meta['type'] ?? 'html')) === 'php') {
            return null;
        }

        $options = $this->parserOptions($meta);
        $cacheKey = hash('xxh128', $source . "\0" . serialize($options));

        if (array_key_exists($cacheKey, $this->renderCache)) {
            return $this->renderCache[$cacheKey];
        }

        $this->loadCore();
        $result = MurText::parse($source, $options);
        $this->recordDiagnostics($result, $document, $collection);

        if ($result->errors !== [] && ($this->config['errors']['policy'] ?? 'render') === 'fallback') {
            return $this->renderCache[$cacheKey] = null;
        }

        return $this->renderCache[$cacheKey] = $result->html;
    }

    private function shouldParse(array $meta, string $collection): bool
    {
        $parser = strtolower(trim((string) ($meta['parser'] ?? '')));

        if (in_array($parser, ['native', 'off', 'none', '-'], true)) {
            return false;
        }

        if (in_array($parser, ['murtext', 'on', '+'], true)) {
            return true;
        }

        return ($this->config['default_enabled'] ?? false) === true
            && ($this->config['scopes'][$collection] ?? false) === true;
    }

    private function collection(array $hookContext, mixed $document): string
    {
        $collection = strtolower(trim((string) ($hookContext['collection'] ?? '')));
        if ($collection !== '') {
            return $collection;
        }
        return $document instanceof Document ? strtolower($document->collection) : 'other';
    }

    private function parserOptions(array $meta): array
    {
        $settings = is_array($this->config['parser'] ?? null) ? $this->config['parser'] : [];
        $settings['site_url'] = $this->effectiveUrl('site_url', rtrim((string) ($this->context->site['base_url'] ?? ''), '/'));
        $settings['uploads_url'] = $this->effectiveUrl('uploads_url', rtrim((string) ($this->context->site['base_url'] ?? ''), '/') . '/uploads');
        $settings['pages_url'] = $this->effectiveUrl('pages_url', rtrim((string) ($this->context->site['base_url'] ?? ''), '/'));
        unset($settings['dialect']);

        $paragraph = strtolower(trim((string) ($meta['murtext.paragraph_mode'] ?? '')));
        if (in_array($paragraph, ['blank_lines', 'line'], true)) {
            $settings['paragraph_mode'] = $paragraph;
        }

        foreach (['pretty', 'strict'] as $key) {
            $value = trim((string) ($meta['murtext.' . $key] ?? ''));
            if ($value === '0' || $value === '1') {
                $settings[$key] = $value === '1';
            }
        }

        return $settings;
    }

    private function effectiveUrl(string $key, string $automatic): string
    {
        $configured = trim((string) ($this->config['parser'][$key] ?? ''));
        return $configured !== '' ? rtrim($configured, '/') : $automatic;
    }

    private function recordDiagnostics(ParseResult $result, mixed $document, string $collection): void
    {
        $mode = (string) ($this->config['errors']['log'] ?? 'off');
        if ($mode === 'off' || ($mode === 'errors' && $result->errors === [])) {
            return;
        }

        $items = $mode === 'errors' ? $result->errors : array_merge($result->warnings, $result->errors);
        if ($items === []) {
            return;
        }

        $id = $document instanceof Document ? $document->id() : '-';
        $lines = [];
        foreach ($items as $item) {
            $lines[] = sprintf(
                '[%s] %s/%s line=%s code=%s %s',
                date('c'),
                $collection,
                $id,
                $item->line ?? '-',
                $item->code ?? '-',
                $item->message,
            );
        }

        $path = $this->context->path('storage/logs/murtext.log');
        @file_put_contents($path, implode("\n", $lines) . "\n", FILE_APPEND | LOCK_EX);
    }

    private function loadCore(): void
    {
        if ($this->coreLoaded) {
            return;
        }
        require_once $this->pluginPath . '/core/murtext.php';
        $this->coreLoaded = true;
    }

}
