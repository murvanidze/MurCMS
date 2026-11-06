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
        private ?array $registryCache = null;
        private array $config = ['allow_php' => false];

        public function __construct(private readonly PluginContext $context)
        {
            $this->parser = new DocumentParser();
            $configPath = $this->context->path('config/snippets.php');

            if (is_file($configPath)) {
                $config = require $configPath;

                if (is_array($config)) {
                    $this->config = array_replace($this->config, $config);
                }
            }
        }

        public function filter(string $html, array $context): string
        {
            $language = (string) ($context['language'] ?? $this->context->languages['default'] ?? '');

            return (string) preg_replace_callback(
                '/\[(?:snippet|ushka)(?:=|:)([a-zA-Z0-9_-]+)\]/',
                fn (array $matches): string => $this->render($matches[1], $language),
                $html,
            );
        }

        public function system(string $html, array $context, string $name): string
        {
            $language = (string) ($context['language'] ?? $this->context->languages['default'] ?? '');

            return $html . $this->render($name, $language);
        }

        public function apiRender(mixed $value, array $apiContext): mixed
        {
            $rawId = $apiContext['id'] ?? '';
            $id = is_string($rawId) || is_int($rawId) ? trim((string) $rawId) : '';
            if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]*$/', $id) !== 1) {
                return '';
            }

            $rawLanguage = $apiContext['language'] ?? $this->context->languages['default'] ?? '';
            $language = is_string($rawLanguage) ? trim($rawLanguage) : '';
            if (preg_match('/^[a-z]{2}$/D', $language) !== 1) {
                $language = (string) ($this->context->languages['default'] ?? '');
            }
            return $this->render($id, $language);
        }

        public function apiRegistry(mixed $value, array $apiContext): array
        {
            if ($this->registryCache !== null) {
                return $this->registryCache;
            }

            $root = $this->context->path('snippets');
            if (!is_dir($root)) {
                return $this->registryCache = [];
            }

            $registry = [];
            $scan = function (string $directory, string $scope) use (&$registry): void {
                if (!is_dir($directory)) {
                    return;
                }

                $files = glob($directory . '/*') ?: [];
                foreach ($files as $file) {
                    if (!is_file($file)) {
                        continue;
                    }

                    $basename = basename($file);
                    $id = '';
                    $format = '';
                    if (str_ends_with($basename, '.mur.md')) {
                        $id = substr($basename, 0, -7);
                        $format = 'mur.md';
                    } elseif (str_ends_with($basename, '.html')) {
                        $id = substr($basename, 0, -5);
                        $format = 'html';
                    }

                    if ($id === '' || preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]*$/', $id) !== 1) {
                        continue;
                    }

                    if (!isset($registry[$id])) {
                        $registry[$id] = [
                            'id' => $id,
                            'scopes' => [],
                            'formats' => [],
                        ];
                    }
                    if (!in_array($scope, $registry[$id]['scopes'], true)) {
                        $registry[$id]['scopes'][] = $scope;
                    }
                    if (!in_array($format, $registry[$id]['formats'], true)) {
                        $registry[$id]['formats'][] = $format;
                    }
                }
            };

            $scan($root, 'global');
            foreach (glob($root . '/*', GLOB_ONLYDIR) ?: [] as $directory) {
                $scope = basename($directory);
                if (preg_match('/^[a-z]{2}$/', $scope) === 1) {
                    $scan($directory, $scope);
                }
            }

            ksort($registry, SORT_NATURAL | SORT_FLAG_CASE);
            return $this->registryCache = array_values($registry);
        }

        private function render(string $name, string $language): string
        {
            $cacheKey = $language . ':' . $name;

            if (array_key_exists($cacheKey, $this->cache)) {
                return $this->cache[$cacheKey];
            }

            $file = $this->findFile($name, $language);

            if ($file === '') {
                return $this->cache[$cacheKey] = '';
            }

            $document = $this->parser->parse($file, 'snippet', $language);

            if (!$document->isPublished()) {
                return $this->cache[$cacheKey] = '';
            }

            $type = strtolower(trim((string) ($document->meta['type'] ?? 'html')));

            if ($type === 'php') {
                return $this->cache[$cacheKey] = $this->renderPhp($document->body, $language);
            }

            return $this->cache[$cacheKey] = mur_render_body($document->body, $this->context->hooks, [
                'language' => $language,
                'document' => $document,
                'collection' => 'snippet',
                'surface' => 'snippet',
            ]);
        }

        private function findFile(string $name, string $language): string
        {
            $safeName = preg_replace('/[^a-zA-Z0-9_-]+/', '', $name) ?? '';

            if ($safeName === '') {
                return '';
            }

            $candidates = [
                $this->context->path('snippets/' . $language . '/' . $safeName . '.mur.md'),
                $this->context->path('snippets/' . $language . '/' . $safeName . '.html'),
                $this->context->path('snippets/' . $safeName . '.mur.md'),
                $this->context->path('snippets/' . $safeName . '.html'),
            ];

            $defaultLanguage = (string) ($this->context->languages['default'] ?? '');

            if ($defaultLanguage !== '' && $defaultLanguage !== $language) {
                $candidates[] = $this->context->path('snippets/' . $defaultLanguage . '/' . $safeName . '.mur.md');
                $candidates[] = $this->context->path('snippets/' . $defaultLanguage . '/' . $safeName . '.html');
            }

            foreach ($candidates as $candidate) {
                if (is_file($candidate)) {
                    return $candidate;
                }
            }

            return '';
        }

        private function renderPhp(string $code, string $language): string
        {
            if (($this->config['allow_php'] ?? false) !== true) {
                return '';
            }

            $context = $this->context;

            ob_start();
            (static function () use ($code, $language, $context): void {
                eval('?>' . $code);
            })();

            return (string) ob_get_clean();
        }
    };

    $context->hooks->add('snippets.render', [$plugin, 'apiRender']);
    $context->hooks->add('snippets.registry', [$plugin, 'apiRegistry']);

    foreach (['content.home', 'content.document', 'layout.footer'] as $hook) {
        $context->hooks->add($hook, [$plugin, 'filter']);
    }

    $systemSnippets = [
        'layout.head' => 'system-head',
        'layout.body_start' => 'system-body-start',
        'layout.header_before' => 'system-header-before',
        'layout.header_after' => 'system-header-after',
        'layout.main_before' => 'system-main-before',
        'layout.body_end' => 'system-body-end',
        'layout.content_start' => 'system-content-start',
        'content.article_start' => 'system-article-start',
        'content.article_end' => 'system-article-end',
        'layout.content_end' => 'system-content-end',
        'layout.main_after' => 'system-main-after',
    ];

    foreach ($systemSnippets as $hook => $name) {
        $context->hooks->add(
            $hook,
            static fn (string $html, array $hookContext): string => $plugin->system($html, $hookContext, $name),
            100,
        );
    }
};
