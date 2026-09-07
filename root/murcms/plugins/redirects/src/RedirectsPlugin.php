<?php
declare(strict_types=1);

namespace MurCMS\RedirectsPlugin;

if (!defined('MUR_CMS_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('No direct script access allowed.');
}

use Mur\Admin\AdminUserManager;
use Mur\Core\PluginContext;

final class RedirectsPlugin
{
    private array $config = [
        'rules' => '',
        'not_found_rules' => '',
    ];

    public function __construct(private readonly PluginContext $context)
    {
        $this->config = array_replace($this->config, $this->readConfig());
    }

    public function register(): void
    {
        $this->context->hooks->add('route.public', [$this, 'publicRoute'], 1);
        $this->context->hooks->add('route.not_found', [$this, 'notFoundRoute'], 1);
        $this->context->hooks->add('admin.menu', [$this, 'adminMenu']);
        $this->context->registerAdminRoute([
            'path' => '/admin/plugin/redirects',
            'role' => 'admin',
            'handler' => [$this, 'adminRoute'],
        ]);
    }

    public function publicRoute(mixed $html, array $routeContext): mixed
    {
        $this->redirectIfMatched((string) ($this->config['rules'] ?? ''), $routeContext);

        return $html;
    }

    public function notFoundRoute(mixed $html, array $routeContext): mixed
    {
        $this->redirectIfMatched((string) ($this->config['not_found_rules'] ?? ''), $routeContext);

        return $html;
    }

    public function adminMenu(array $items): array
    {
        $items[] = [
            'id' => 'plugin-redirects',
            'label' => 'Redirects',
            'url' => '/admin/plugin/redirects',
            'role' => 'admin',
        ];

        return $items;
    }

    public function adminRoute(mixed $html, array $adminContext): mixed
    {
        if (($adminContext['route'] ?? '') !== 'plugin/redirects') {
            return $html;
        }

        $method = (string) ($adminContext['method'] ?? 'GET');
        $user = is_array($adminContext['user'] ?? null) ? $adminContext['user'] : [];
        $notice = '';

        if ($method === 'POST') {
            $notice = $this->saveSettings($user);
        }

        return $notice . $this->adminPage($user);
    }

    public function parseRules(string $source): array
    {
        $rules = [];
        $errors = [];

        foreach (preg_split('/\R/u', $source) ?: [] as $index => $line) {
            $lineNumber = $index + 1;
            $raw = trim($line);

            if ($raw === '' || str_starts_with($raw, '#')) {
                continue;
            }

            if (strlen($raw) > 3000) {
                $errors[] = 'Line ' . $lineNumber . ': rule is too long.';
                continue;
            }

            $parts = array_map('trim', explode('|', $raw, 3));
            $from = (string) ($parts[0] ?? '');
            $to = (string) ($parts[1] ?? '');
            $status = $this->statusCode((string) ($parts[2] ?? ''));

            if ($from === '' || $to === '') {
                $errors[] = 'Line ' . $lineNumber . ': expected source | target | status.';
                continue;
            }

            if (!$this->validTarget($to)) {
                $errors[] = 'Line ' . $lineNumber . ': target must be a local /path or an http/https URL.';
                continue;
            }

            $regex = $this->regexPattern($from);
            if ($regex !== null && !$this->validRegex($regex)) {
                $errors[] = 'Line ' . $lineNumber . ': invalid regex source.';
                continue;
            }

            $rules[] = [
                'from' => $from,
                'to' => $to,
                'status' => $status,
                'regex' => $regex,
                'line' => $lineNumber,
            ];
        }

        return [
            'rules' => $rules,
            'errors' => $errors,
        ];
    }

    public function matchRules(string $source, string $requestUri, ?string $host = null): ?array
    {
        $parsed = $this->parseRules($source);

        foreach ($parsed['rules'] as $rule) {
            $match = $this->matchRule($rule, $requestUri, $host);

            if ($match !== null) {
                return $match;
            }
        }

        return null;
    }

    private function redirectIfMatched(string $source, array $routeContext): void
    {
        $requestUri = $this->requestUri($routeContext);
        $host = $this->requestHost();
        $match = $this->matchRules($source, $requestUri, $host);

        if ($match === null || $this->isLoop($match['location'], $requestUri, $host)) {
            return;
        }

        http_response_code((int) $match['status']);
        header('Location: ' . $match['location']);
        exit;
    }

    private function matchRule(array $rule, string $requestUri, ?string $host): ?array
    {
        $candidates = $this->requestCandidates($requestUri, $host);
        $matches = [];

        if (is_string($rule['regex'] ?? null)) {
            foreach ([$candidates['path_query'], $candidates['path']] as $candidate) {
                if (@preg_match((string) $rule['regex'], $candidate, $matches) === 1) {
                    return [
                        'location' => $this->expandTarget((string) $rule['to'], $matches),
                        'status' => (int) $rule['status'],
                        'line' => (int) $rule['line'],
                    ];
                }
            }

            return null;
        }

        $from = $this->normalizeLiteralSource((string) $rule['from']);
        $sourceHasQuery = str_contains($from, '?');
        $literalCandidates = $sourceHasQuery
            ? [$candidates['path_query'], $candidates['absolute_query'], $candidates['site_absolute_query']]
            : [$candidates['path'], $candidates['absolute'], $candidates['site_absolute']];

        if (!in_array($from, $literalCandidates, true)) {
            return null;
        }

        return [
            'location' => $this->expandTarget((string) $rule['to'], []),
            'status' => (int) $rule['status'],
            'line' => (int) $rule['line'],
        ];
    }

    private function expandTarget(string $target, array $matches): string
    {
        $location = trim($target);

        foreach ($matches as $index => $value) {
            if (!is_int($index) || $index === 0) {
                continue;
            }

            $location = str_replace('$' . $index, (string) $value, $location);
        }

        return $location;
    }

    private function normalizeLiteralSource(string $source): string
    {
        $source = trim($source);
        $parts = parse_url($source);

        if (is_array($parts) && isset($parts['scheme'], $parts['host'])) {
            $scheme = strtolower((string) $parts['scheme']);
            $host = strtolower((string) $parts['host']);
            $path = $this->normalizePath((string) ($parts['path'] ?? '/'));
            $query = isset($parts['query']) ? '?' . (string) $parts['query'] : '';

            return $scheme . '://' . $host . $path . $query;
        }

        if (!str_starts_with($source, '/')) {
            $source = '/' . $source;
        }

        $query = '';
        $path = $source;
        if (str_contains($source, '?')) {
            [$path, $queryPart] = explode('?', $source, 2);
            $query = '?' . $queryPart;
        }

        return $this->normalizePath($path) . $query;
    }

    private function normalizePath(string $path): string
    {
        $path = '/' . trim($path, '/');

        return $path === '/' ? '/' : rtrim($path, '/');
    }

    private function requestCandidates(string $requestUri, ?string $host): array
    {
        $path = $this->normalizePath((string) (parse_url($requestUri, PHP_URL_PATH) ?: '/'));
        $query = (string) (parse_url($requestUri, PHP_URL_QUERY) ?? '');
        $pathQuery = $path . ($query !== '' ? '?' . $query : '');
        $baseOrigin = $this->baseOrigin($host);
        $siteOrigin = $this->siteOrigin();

        return [
            'path' => $path,
            'path_query' => $pathQuery,
            'absolute' => $baseOrigin . $path,
            'absolute_query' => $baseOrigin . $pathQuery,
            'site_absolute' => $siteOrigin . $path,
            'site_absolute_query' => $siteOrigin . $pathQuery,
        ];
    }

    private function baseOrigin(?string $host): string
    {
        if ($host !== null && $host !== '') {
            $https = strtolower((string) ($_SERVER['HTTPS'] ?? '')) === 'on'
                || (string) ($_SERVER['SERVER_PORT'] ?? '') === '443';
            return ($https ? 'https' : 'http') . '://' . strtolower($host);
        }

        $baseUrl = (string) ($this->context->site['base_url'] ?? '');
        $parts = parse_url($baseUrl);

        if (is_array($parts) && isset($parts['scheme'], $parts['host'])) {
            return strtolower((string) $parts['scheme']) . '://' . strtolower((string) $parts['host']);
        }

        return '';
    }

    private function siteOrigin(): string
    {
        $baseUrl = (string) ($this->context->site['base_url'] ?? '');
        $parts = parse_url($baseUrl);

        if (is_array($parts) && isset($parts['scheme'], $parts['host'])) {
            return strtolower((string) $parts['scheme']) . '://' . strtolower((string) $parts['host']);
        }

        return '';
    }

    private function requestUri(array $routeContext): string
    {
        $serverUri = (string) ($_SERVER['REQUEST_URI'] ?? '');

        if ($serverUri !== '') {
            return $serverUri;
        }

        $language = trim((string) ($routeContext['language'] ?? ''), '/');
        $path = trim((string) ($routeContext['path'] ?? ''), '/');

        return '/' . trim($language . '/' . $path, '/');
    }

    private function requestHost(): ?string
    {
        $host = trim((string) ($_SERVER['HTTP_HOST'] ?? ''));

        if ($host === '') {
            return null;
        }

        return strtolower(preg_replace('/:\d+$/', '', $host) ?? $host);
    }

    private function isLoop(string $location, string $requestUri, ?string $host): bool
    {
        $location = $this->normalizeLiteralSource($location);
        $candidates = $this->requestCandidates($requestUri, $host);

        return in_array($location, [
            $candidates['path'],
            $candidates['path_query'],
            $candidates['absolute'],
            $candidates['absolute_query'],
            $candidates['site_absolute'],
            $candidates['site_absolute_query'],
        ], true);
    }

    private function statusCode(string $status): int
    {
        $code = (int) trim($status);

        return in_array($code, [301, 302, 303, 307, 308], true) ? $code : 302;
    }

    private function validTarget(string $target): bool
    {
        $target = trim($target);

        if ($target === '' || preg_match('/[\x00-\x1F\x7F]/', $target)) {
            return false;
        }

        if (str_starts_with($target, '/') && !str_starts_with($target, '//') && !str_contains($target, '\\')) {
            return true;
        }

        $parts = parse_url($target);

        return is_array($parts)
            && in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            && trim((string) ($parts['host'] ?? '')) !== '';
    }

    private function regexPattern(string $from): ?string
    {
        $from = trim($from);

        if (!str_starts_with($from, 'regex:')) {
            return null;
        }

        $pattern = trim(substr($from, 6));

        if ($pattern === '') {
            return '~\b\B~';
        }

        $first = $pattern[0];
        if (in_array($first, ['~', '#', '!', '/'], true) && strrpos($pattern, $first) > 0) {
            return $pattern;
        }

        return '~' . str_replace('~', '\~', $pattern) . '~i';
    }

    private function validRegex(string $pattern): bool
    {
        set_error_handler(static fn (): bool => true);
        $valid = preg_match($pattern, '') !== false;
        restore_error_handler();

        return $valid;
    }

    private function saveSettings(array $user): string
    {
        if (AdminUserManager::roleRank((string) ($user['role'] ?? ''))
            < AdminUserManager::roleRank(AdminUserManager::ROLE_ADMIN)) {
            return '<div class="admin-notice admin-notice--error">Redirect settings require admin permissions.</div>';
        }

        if (!hash_equals((string) ($user['csrf'] ?? ''), (string) ($_POST['_csrf'] ?? ''))) {
            return '<div class="admin-notice admin-notice--error">CSRF token expired. Refresh the page and try again.</div>';
        }

        $next = [
            'rules' => $this->normalizeTextarea((string) ($_POST['rules'] ?? '')),
            'not_found_rules' => $this->normalizeTextarea((string) ($_POST['not_found_rules'] ?? '')),
        ];
        $errors = array_merge(
            $this->parseRules($next['rules'])['errors'],
            $this->parseRules($next['not_found_rules'])['errors'],
        );
        $this->config = $next;

        if ($errors !== []) {
            return '<div class="admin-notice admin-notice--error">Redirects were not saved. ' . mur_escape(implode(' ', $errors)) . '</div>';
        }

        if (!$this->writeConfig()) {
            return '<div class="admin-notice admin-notice--error">Could not write <code>data/config/redirects.php</code>.</div>';
        }

        return '<div class="admin-notice">Redirects saved.</div>';
    }

    private function adminPage(array $user): string
    {
        $rules = (string) ($this->config['rules'] ?? '');
        $notFoundRules = (string) ($this->config['not_found_rules'] ?? '');
        $parsedRules = $this->parseRules($rules);
        $parsedNotFound = $this->parseRules($notFoundRules);
        $ruleCount = count($parsedRules['rules']);
        $notFoundCount = count($parsedNotFound['rules']);

        return '<section class="admin-panel">
  <div class="admin-panel-head"><div><h2>Redirects</h2><p>One redirect per line. Format: <code>source | target | status</code>.</p></div></div>
  <form class="admin-form" method="post" action="/admin/plugin/redirects">
    <input type="hidden" name="_csrf" value="' . mur_escape((string) ($user['csrf'] ?? '')) . '">
    <label>Active redirects
      <textarea name="rules" rows="14" spellcheck="false" placeholder="/ru/old | /ru/new | 301">' . mur_escape($rules) . '</textarea>
    </label>
    <p class="admin-help">These redirects run before MurCMS looks for a document. Literal sources match <code>/path</code>, <code>/path?query</code>, or an absolute <code>http(s)</code> URL. Use <code>regex:</code> for captures: <code>regex:^/ru/old/(.+)$ | /ru/new/$1 | 301</code>. Allowed statuses: 301, 302, 303, 307, 308. Missing or invalid status falls back to 302.</p>
    <label>404-only redirects
      <textarea name="not_found_rules" rows="8" spellcheck="false" placeholder="/ru/missing | /ru/new-page | 301">' . mur_escape($notFoundRules) . '</textarea>
    </label>
    <p class="admin-help">These rules run only after no published MurCMS page or plugin route was found. They are useful for legacy URLs during migrations.</p>
    <div class="admin-actions"><button class="admin-button" type="submit">Save redirects</button></div>
  </form>
</section>
<section class="admin-panel"><h2>Summary</h2><table class="admin-table"><tbody>
  <tr><th>Active redirects</th><td>' . $ruleCount . '</td></tr>
  <tr><th>404-only redirects</th><td>' . $notFoundCount . '</td></tr>
  <tr><th>Config file</th><td><code>data/config/redirects.php</code></td></tr>
</tbody></table></section>';
    }

    private function normalizeTextarea(string $value): string
    {
        $value = str_replace(["\r\n", "\r"], "\n", $value);

        return trim($value);
    }

    private function readConfig(): array
    {
        foreach ([$this->context->path('config/redirects.php'), $this->context->path('config/redirects.dist.php')] as $path) {
            if (!is_file($path)) {
                continue;
            }

            $config = require $path;
            if (is_array($config)) {
                return $config;
            }
        }

        return [];
    }

    private function writeConfig(): bool
    {
        $path = $this->context->path('config/redirects.php');
        $directory = dirname($path);

        if (!is_dir($directory)) {
            return false;
        }

        $this->backupConfig($path);

        $tmp = $path . '.tmp';
        $content = "<?php\n"
            . "declare(strict_types=1);\n\n"
            . "if (!defined('MUR_CMS_BOOTSTRAPPED')) {\n"
            . "    http_response_code(403);\n"
            . "    exit('No direct script access allowed.');\n"
            . "}\n\n"
            . 'return ' . var_export($this->config, true) . ";\n";

        if (@file_put_contents($tmp, $content, LOCK_EX) === false) {
            return false;
        }

        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            return false;
        }

        return true;
    }

    private function backupConfig(string $path): void
    {
        if (!is_file($path)) {
            return;
        }

        $backupDir = $this->context->path('storage/backups/redirects');
        if (!is_dir($backupDir) && !@mkdir($backupDir, 0775, true) && !is_dir($backupDir)) {
            return;
        }

        @copy($path, $backupDir . '/redirects-' . date('Ymd-His') . '.php');
    }
}
