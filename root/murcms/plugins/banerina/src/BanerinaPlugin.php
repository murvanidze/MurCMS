<?php
declare(strict_types=1);

namespace MurCMS\BanerinaPlugin;

if (!defined('MUR_CMS_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('No direct script access allowed.');
}

use Mur\Core\PluginContext;

final class BanerinaPlugin
{
    public const VERSION = '0.1.1';

    /** @var array<string,true> */
    private array $used = [];

    public function __construct(
        private readonly PluginContext $context,
        private readonly ConfigStore $store,
        private readonly SnippetGateway $snippets,
    ) {
    }

    public function filter(string $html, array $hookContext): string
    {
        if (stripos($html, '[promo') === false) {
            return $html;
        }

        $language = (string) ($hookContext['language'] ?? $this->context->languages['default'] ?? '');
        $pattern = '/<p>\s*\[promo(?:=|:)([^\]]*)\]\s*<\/p>|\[promo(?:=|:)([^\]]*)\]/i';

        return (string) preg_replace_callback($pattern, function (array $matches) use ($language): string {
            $value = (string) (($matches[1] ?? '') !== '' ? $matches[1] : ($matches[2] ?? ''));
            return $this->renderValue($value, $language);
        }, $html);
    }

    public function renderValue(string $rawValue, string $language): string
    {
        $rawValue = trim($rawValue);
        if ($rawValue === '') {
            $this->log('empty_shortcode', 'Banerina shortcode [promo] has an empty value.');
            return '';
        }

        if (str_contains($rawValue, ',')) {
            $ids = $this->normalizeSnippetIds(explode(',', $rawValue));
            return $this->renderCandidates($ids, $language, 'list', '');
        }

        $value = trim($rawValue);
        $groups = $this->store->groups();
        if ($this->validGroupId($value) && isset($groups[$value]) && is_array($groups[$value])) {
            $group = $groups[$value];
            if (($group['active'] ?? false) !== true) {
                return '';
            }

            $ids = $this->normalizeSnippetIds((array) ($group['snippets'] ?? []));
            return $this->renderCandidates($ids, $language, 'group', $value);
        }

        if (!$this->validSnippetId($value)) {
            $this->log('invalid_snippet_id', 'Invalid snippet ID requested by Banerina: ' . $value);
            return '';
        }

        $rendered = $this->snippets->render($value, $language);
        if ($rendered === '') {
            $this->log('snippet_unrenderable', 'Snippet could not be rendered: ' . $value);
            return '';
        }

        $this->used[$value] = true;
        return $this->wrap($rendered, $value, 'direct', '');
    }

    public function previewGroup(string $groupId, string $language): array
    {
        $group = $this->store->groups()[$groupId] ?? null;
        if (!is_array($group)) {
            return ['id' => '', 'html' => ''];
        }

        $ids = $this->normalizeSnippetIds((array) ($group['snippets'] ?? []));
        foreach ($this->shuffled($ids) as $id) {
            $html = $this->snippets->render($id, $language);
            if ($html !== '') {
                return ['id' => $id, 'html' => $this->wrap($html, $id, 'group', $groupId)];
            }
        }

        return ['id' => '', 'html' => ''];
    }

    private function renderCandidates(array $ids, string $language, string $mode, string $groupId): string
    {
        if ($ids === []) {
            $this->log('empty_candidates', 'Banerina has no valid snippet candidates' . ($groupId !== '' ? ' in group ' . $groupId : '') . '.');
            return '';
        }

        $avoid = ($this->store->settings()['avoid_duplicates'] ?? true) === true;
        $preferred = $ids;
        if ($avoid) {
            $unused = array_values(array_filter($ids, fn (string $id): bool => !isset($this->used[$id])));
            if ($unused !== []) {
                $preferred = $unused;
            }
        }

        $attemptSets = [$preferred];
        if ($preferred !== $ids) {
            $attemptSets[] = $ids;
        }

        $tried = [];
        foreach ($attemptSets as $set) {
            foreach ($this->shuffled($set) as $id) {
                if (isset($tried[$id])) {
                    continue;
                }
                $tried[$id] = true;
                $html = $this->snippets->render($id, $language);
                if ($html === '') {
                    continue;
                }

                $this->used[$id] = true;
                return $this->wrap($html, $id, $mode, $groupId);
            }
        }

        $this->log('no_renderable_candidate', 'No Banerina candidate could be rendered' . ($groupId !== '' ? ' for group ' . $groupId : '') . '.');
        return '';
    }

    private function normalizeSnippetIds(array $values): array
    {
        $ids = [];
        foreach ($values as $value) {
            $id = trim((string) $value);
            if ($this->validSnippetId($id)) {
                $ids[$id] = true;
            }
        }
        return array_keys($ids);
    }

    private function validSnippetId(string $id): bool
    {
        return preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]*$/', $id) === 1;
    }

    private function validGroupId(string $id): bool
    {
        return preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]*$/', $id) === 1;
    }

    private function shuffled(array $values): array
    {
        $values = array_values($values);
        for ($i = count($values) - 1; $i > 0; $i--) {
            try {
                $j = random_int(0, $i);
            } catch (\Throwable) {
                $j = mt_rand(0, $i);
            }
            [$values[$i], $values[$j]] = [$values[$j], $values[$i]];
        }
        return $values;
    }

    private function wrap(string $html, string $id, string $mode, string $groupId): string
    {
        $attrs = ' class="mur-promo" data-promo-id="' . mur_escape($id) . '" data-promo-mode="' . mur_escape($mode) . '"';
        if ($groupId !== '') {
            $attrs .= ' data-promo-group="' . mur_escape($groupId) . '"';
        }
        return '<div' . $attrs . '>' . $html . '</div>';
    }

    private function log(string $code, string $message): void
    {
        if (($this->store->settings()['diagnostics_logging'] ?? false) !== true) {
            return;
        }

        $path = $this->context->path('storage/logs/banerina.log');
        $directory = dirname($path);
        if (!is_dir($directory)) {
            @mkdir($directory, 0775, true);
        }
        $safeCode = preg_replace('/[^a-z0-9_.-]+/i', '_', $code) ?: 'banerina';
        $safeMessage = str_replace(["\r", "\n", "\0"], ' ', $message);
        if (strlen($safeMessage) > 2000) {
            $safeMessage = substr($safeMessage, 0, 2000) . '…';
        }

        @file_put_contents($path, sprintf("[%s] %s %s\n", date('c'), $safeCode, $safeMessage), FILE_APPEND | LOCK_EX);
    }
}
