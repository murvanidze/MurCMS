<?php
declare(strict_types=1);

namespace MurCMS\BanerinaPlugin;

if (!defined('MUR_CMS_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('No direct script access allowed.');
}

use Mur\Core\PluginContext;

final class AdminUi
{
    public function __construct(
        private readonly PluginContext $context,
        private readonly ConfigStore $store,
        private readonly SnippetGateway $snippets,
        private readonly BanerinaPlugin $banerina,
        private readonly Translator $translator,
    ) {
    }

    public function adminMenu(array $items): array
    {
        $items[] = [
            'id' => 'plugin-banerina',
            'label' => 'Banerina',
            'url' => '/admin/plugin/banerina',
            'role' => 'admin',
        ];
        return $items;
    }

    public function adminRoute(mixed $value, array $adminContext): string
    {
        $user = is_array($adminContext['user'] ?? null) ? $adminContext['user'] : [];
        $this->translator->setLanguage((string) ($user['admin_language'] ?? 'ru'));
        $notice = '';

        if ((string) ($adminContext['method'] ?? 'GET') === 'POST') {
            $notice = $this->handlePost($user);
        }

        return $this->assets() . $notice . $this->page($user);
    }

    private function page(array $user): string
    {
        $config = $this->store->config();
        $groups = $this->store->groups();
        $registry = $this->snippets->registry();
        $diagnostics = $this->diagnostics($groups, $registry);
        $query = $this->getString('q');
        $filtered = $query === '' ? $groups : array_filter($groups, function (array $group) use ($query): bool {
            $haystack = (string) (($group['id'] ?? '') . ' ' . ($group['name'] ?? '') . ' ' . ($group['description'] ?? ''));
            return preg_match('/' . preg_quote($query, '/') . '/iu', $haystack) === 1;
        });

        $uniqueSnippets = [];
        foreach ($groups as $group) {
            foreach ((array) ($group['snippets'] ?? []) as $id) {
                $uniqueSnippets[(string) $id] = true;
            }
        }
        $active = count(array_filter($groups, static fn (array $group): bool => ($group['active'] ?? false) === true));

        $html = '<section class="banerina-admin-head"><div><h1>Banerina</h1><p>' . $this->e($this->t('intro')) . '</p></div>'
            . '<a class="admin-button" href="/admin/plugin/banerina?edit=__new__">' . $this->e($this->t('create_group')) . '</a></section>';
        $html .= '<div class="admin-grid admin-grid--stats">'
            . $this->statCard((string) count($groups), $this->t('groups'))
            . $this->statCard((string) count($uniqueSnippets), $this->t('banerina_snippets'))
            . $this->statCard($active . ' / ' . count($groups), $this->t('active_groups'))
            . $this->statCard((string) count($diagnostics), '<a href="#banerina-diagnostics">' . $this->e($this->t('problems')) . '</a>', true)
            . '</div>';

        $html .= $this->groupsPanel($filtered, $query, $user);

        $edit = $this->getString('edit');
        if ($edit !== '') {
            $html .= $this->editorPanel($edit, $groups, $registry, $user);
        }

        $delete = $this->getString('delete');
        if ($delete !== '' && isset($groups[$delete])) {
            $html .= $this->deletePanel($groups[$delete], $user);
        }

        $preview = $this->getString('preview');
        if ($preview !== '' && isset($groups[$preview])) {
            $html .= $this->previewPanel($preview);
        }

        $html .= $this->settingsPanel($config['settings'] ?? [], $user);
        $html .= $this->diagnosticsPanel($diagnostics);
        $html .= '<section class="admin-panel"><p class="admin-help">Banerina ' . $this->e(BanerinaPlugin::VERSION) . '</p></section>';
        return $html;
    }

    private function groupsPanel(array $groups, string $query, array $user): string
    {
        $html = '<section class="admin-panel"><div class="admin-panel-head"><h2>' . $this->e($this->t('groups')) . '</h2>'
            . '<form method="get" action="/admin/plugin/banerina" class="banerina-search"><input type="search" name="q" value="' . $this->e($query) . '" placeholder="' . $this->e($this->t('search_groups')) . '"><button class="admin-button admin-button--ghost" type="submit">' . $this->e($this->t('search')) . '</button></form></div>';

        if ($groups === []) {
            return $html . '<p class="admin-empty">' . $this->e($this->t('no_groups')) . '</p></section>';
        }

        $html .= '<table class="admin-table"><thead><tr><th>' . $this->e($this->t('name')) . '</th><th>ID</th><th>' . $this->e($this->t('snippets')) . '</th><th>' . $this->e($this->t('status')) . '</th><th>' . $this->e($this->t('actions')) . '</th></tr></thead><tbody>';
        foreach ($groups as $group) {
            $id = (string) ($group['id'] ?? '');
            $active = ($group['active'] ?? false) === true;
            $html .= '<tr><td><strong>' . $this->e((string) ($group['name'] ?? $id)) . '</strong>'
                . ((string) ($group['description'] ?? '') !== '' ? '<br><small>' . $this->e((string) $group['description']) . '</small>' : '') . '</td>'
                . '<td><code>' . $this->e($id) . '</code></td><td class="banerina-group-snippets"><strong>' . count((array) ($group['snippets'] ?? [])) . '</strong><div>' . implode(', ', array_map(fn ($name): string => '<code>' . $this->e((string) $name) . '</code>', (array) ($group['snippets'] ?? []))) . '</div></td>'
                . '<td><span class="admin-status admin-status--' . ($active ? 'published' : 'draft') . '" title="' . $this->e($active ? $this->t('active') : $this->t('inactive')) . '" role="img" aria-label="' . $this->e($active ? $this->t('active') : $this->t('inactive')) . '">' . $this->icon($active ? 'published' : 'draft') . '</span></td><td><div class="admin-row-actions">'
                . '<a class="admin-link-button" href="/admin/plugin/banerina?edit=' . rawurlencode($id) . '" title="' . $this->e($this->t('edit')) . '" aria-label="' . $this->e($this->t('edit')) . '">' . $this->icon('edit') . '</a>'
                . '<a class="admin-link-button" href="/admin/plugin/banerina?preview=' . rawurlencode($id) . '" title="' . $this->e($this->t('preview')) . '" aria-label="' . $this->e($this->t('preview')) . '">' . $this->icon('view') . '</a>'
                . $this->toggleForm($id, $active, $user)
                . '<a class="admin-link-button admin-link-button--danger" href="/admin/plugin/banerina?delete=' . rawurlencode($id) . '" title="' . $this->e($this->t('delete')) . '" aria-label="' . $this->e($this->t('delete')) . '">' . $this->icon('delete') . '</a>'
                . '</div></td></tr>';
        }
        return $html . '</tbody></table></section>';
    }

    private function editorPanel(string $id, array $groups, ?array $registry, array $user): string
    {
        $isNew = $id === '__new__';
        $group = $isNew ? ['id' => '', 'name' => '', 'description' => '', 'active' => true, 'snippets' => []] : ($groups[$id] ?? null);
        if (!is_array($group)) {
            return $this->notice($this->t('group_not_found'), true);
        }

        $selected = array_fill_keys((array) ($group['snippets'] ?? []), true);
        $html = '<section class="admin-panel" id="banerina-editor"><div class="admin-panel-head"><h2>' . $this->e($isNew ? $this->t('create_group') : $this->t('edit_group')) . '</h2><a class="admin-button admin-button--ghost" href="/admin/plugin/banerina">' . $this->e($this->t('close')) . '</a></div>'
            . '<form class="admin-form" method="post" action="/admin/plugin/banerina">' . $this->csrf($user)
            . '<input type="hidden" name="action" value="save_group"><input type="hidden" name="original_id" value="' . $this->e($isNew ? '' : $id) . '">'
            . '<div class="admin-form-grid"><label><span>' . $this->e($this->t('name')) . '</span><input type="text" name="name" value="' . $this->e((string) ($group['name'] ?? '')) . '" required></label>'
            . '<label><span>ID</span><input type="text" name="id" value="' . $this->e((string) ($group['id'] ?? '')) . '" pattern="[A-Za-z0-9][A-Za-z0-9_.-]*" required><small>' . $this->e($this->t('id_help')) . '</small></label></div>'
            . '<label><span>' . $this->e($this->t('description')) . '</span><textarea name="description" rows="3">' . $this->e((string) ($group['description'] ?? '')) . '</textarea></label>'
            . '<label class="admin-check"><input type="checkbox" name="active" value="1"' . (($group['active'] ?? false) === true ? ' checked' : '') . '><span>' . $this->e($this->t('active')) . '</span></label>';

        $html .= '<div class="banerina-picker"><div class="admin-panel-head"><h3>' . $this->e($this->t('group_snippets')) . '</h3><input type="search" data-banerina-snippet-search placeholder="' . $this->e($this->t('search_snippets')) . '"></div>';
        if ($registry === null) {
            $html .= '<p class="admin-notice admin-notice--error">' . $this->e($this->t('registry_unavailable')) . '</p>';
        } elseif ($registry === []) {
            $html .= '<p class="admin-empty">' . $this->e($this->t('no_snippets')) . '</p>';
        } else {
            $html .= '<div class="banerina-picker-list">';
            foreach ($registry as $entry) {
                if (!is_array($entry)) continue;
                $snippetId = (string) ($entry['id'] ?? '');
                if ($snippetId === '') continue;
                $scopes = implode(', ', array_map('strval', (array) ($entry['scopes'] ?? [])));
                $html .= '<label class="banerina-picker-item" data-banerina-snippet-item="' . $this->e(strtolower($snippetId . ' ' . $scopes)) . '"><input type="checkbox" name="snippets[]" value="' . $this->e($snippetId) . '"' . (isset($selected[$snippetId]) ? ' checked' : '') . '><span><code>' . $this->e($snippetId) . '</code>' . ($scopes !== '' ? '<small>' . $this->e($scopes) . '</small>' : '') . '</span></label>';
            }
            $html .= '</div>';
        }
        $html .= '</div><div class="admin-actions"><button class="admin-button" type="submit">' . $this->e($this->t('save')) . '</button>';
        if (!$isNew) {
            $html .= '<a class="admin-button admin-button--ghost" href="/admin/plugin/banerina?preview=' . rawurlencode($id) . '">' . $this->e($this->t('test_selection')) . '</a>';
        }
        return $html . '</div></form></section>';
    }

    private function deletePanel(array $group, array $user): string
    {
        $id = (string) ($group['id'] ?? '');
        return '<section class="admin-panel banerina-danger"><h2>' . $this->e($this->t('delete_group')) . '</h2><p>' . $this->e($this->t('delete_confirm', ['id' => $id])) . '</p>'
            . '<form method="post" action="/admin/plugin/banerina" class="admin-actions">' . $this->csrf($user)
            . '<input type="hidden" name="action" value="delete_group"><input type="hidden" name="id" value="' . $this->e($id) . '"><input type="hidden" name="confirm" value="1">'
            . '<button class="admin-button admin-button--danger" type="submit">' . $this->e($this->t('delete')) . '</button><a class="admin-button admin-button--ghost" href="/admin/plugin/banerina">' . $this->e($this->t('cancel')) . '</a></form></section>';
    }

    private function previewPanel(string $groupId): string
    {
        $language = (string) ($this->context->languages['default'] ?? '');
        $preview = $this->banerina->previewGroup($groupId, $language);
        $html = '<section class="admin-panel"><div class="admin-panel-head"><h2>' . $this->e($this->t('preview')) . ': <code>' . $this->e($groupId) . '</code></h2><a class="admin-button admin-button--ghost" href="/admin/plugin/banerina">' . $this->e($this->t('close')) . '</a></div>';
        if (($preview['html'] ?? '') === '') {
            return $html . '<p class="admin-notice admin-notice--error">' . $this->e($this->t('preview_empty')) . '</p></section>';
        }
        return $html . '<p class="admin-help">' . $this->e($this->t('selected_snippet')) . ': <code>' . $this->e((string) ($preview['id'] ?? '')) . '</code>; ' . $this->e($this->t('language')) . ': <code>' . $this->e($language) . '</code></p><div class="banerina-preview">' . (string) $preview['html'] . '</div></section>';
    }

    private function settingsPanel(array $settings, array $user): string
    {
        return '<section class="admin-panel"><h2>' . $this->e($this->t('settings')) . '</h2><form class="admin-form" method="post" action="/admin/plugin/banerina">' . $this->csrf($user)
            . '<input type="hidden" name="action" value="save_settings"><input type="hidden" name="selection_mode" value="random">'
            . '<label class="admin-check"><input type="checkbox" name="avoid_duplicates" value="1"' . (($settings['avoid_duplicates'] ?? true) === true ? ' checked' : '') . '><span>' . $this->e($this->t('avoid_duplicates')) . '</span></label>'
            . '<label><span>' . $this->e($this->t('selection_mode')) . '</span><select disabled><option>Random</option></select></label>'
            . '<label class="admin-check"><input type="checkbox" name="diagnostics_logging" value="1"' . (($settings['diagnostics_logging'] ?? false) === true ? ' checked' : '') . '><span>' . $this->e($this->t('diagnostics_logging')) . '</span></label>'
            . '<button class="admin-button" type="submit">' . $this->e($this->t('save_settings')) . '</button></form></section>';
    }

    private function diagnosticsPanel(array $items): string
    {
        $html = '<section class="admin-panel" id="banerina-diagnostics"><h2>' . $this->e($this->t('diagnostics')) . '</h2>';
        if ($items === []) {
            return $html . '<p class="banerina-ok">' . $this->e($this->t('no_problems')) . '</p></section>';
        }
        $html .= '<div class="banerina-diagnostics">';
        foreach ($items as $item) {
            $html .= '<article class="banerina-diagnostic"><strong>' . $this->e((string) ($item['group'] ?? 'Banerina')) . '</strong><p>' . $this->e((string) ($item['message'] ?? '')) . '</p></article>';
        }
        return $html . '</div></section>';
    }

    private function diagnostics(array $groups, ?array $registry): array
    {
        $items = [];
        if ($this->store->loadProblem() !== '') {
            $items[] = ['group' => 'Banerina', 'message' => $this->t('diag_config_load')];
        }
        if ($registry === null) {
            $items[] = ['group' => 'Banerina', 'message' => $this->t('registry_unavailable')];
        }
        $known = [];
        if (is_array($registry)) {
            foreach ($registry as $entry) {
                if (is_array($entry) && (string) ($entry['id'] ?? '') !== '') {
                    $known[(string) $entry['id']] = true;
                }
            }
        }

        foreach ($groups as $group) {
            $id = (string) ($group['id'] ?? '');
            if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]*$/', $id) !== 1) {
                $items[] = ['group' => $id !== '' ? $id : 'Banerina', 'message' => $this->t('diag_invalid_id')];
            }
            $snippetIds = array_values(array_map('strval', (array) ($group['snippets'] ?? [])));
            if ($snippetIds === []) {
                $items[] = ['group' => $id, 'message' => $this->t('diag_empty_group')];
            }
            $counts = array_count_values($snippetIds);
            foreach ($counts as $snippetId => $count) {
                if ($count > 1) {
                    $items[] = ['group' => $id, 'message' => $this->t('diag_duplicate', ['id' => $snippetId])];
                }
            }
            if ($registry !== null) {
                foreach (array_keys($counts) as $snippetId) {
                    if (!isset($known[$snippetId])) {
                        $items[] = ['group' => $id, 'message' => $this->t('diag_missing', ['id' => $snippetId])];
                    }
                }
            }
        }
        return $items;
    }

    private function handlePost(array $user): string
    {
        if (!$this->validCsrf($user)) {
            return $this->notice($this->t('csrf_error'), true);
        }

        $action = $this->postString('action');
        try {
            return match ($action) {
                'save_group' => $this->saveGroup(),
                'toggle_group' => $this->toggleGroup(),
                'delete_group' => $this->deleteGroup(),
                'save_settings' => $this->saveSettings(),
                default => $this->notice($this->t('unknown_action'), true),
            };
        } catch (\Throwable) {
            return $this->notice($this->t('save_failed'), true);
        }
    }

    private function saveGroup(): string
    {
        $id = $this->postString('id');
        $original = $this->postString('original_id');
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]*$/', $id) !== 1) {
            return $this->notice($this->t('invalid_group_id'), true);
        }

        $name = $this->postString('name');
        if ($name === '') {
            return $this->notice($this->t('name_required'), true);
        }

        $rawSnippets = $_POST['snippets'] ?? [];
        $rawSnippets = is_array($rawSnippets) ? $rawSnippets : [$rawSnippets];
        $snippets = [];
        foreach ($rawSnippets as $value) {
            if (!is_string($value)) {
                continue;
            }
            $snippetId = trim($value);
            if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]*$/', $snippetId) === 1) {
                $snippets[$snippetId] = true;
            }
        }
        $snippets = array_keys($snippets);
        $config = $this->store->config();
        $groups = is_array($config['groups'] ?? null) ? $config['groups'] : [];
        if ($original !== '' && $original !== $id) {
            unset($groups[$original]);
        }
        if ($original !== $id && isset($groups[$id])) {
            return $this->notice($this->t('group_exists'), true);
        }
        $groups[$id] = [
            'id' => $id,
            'name' => $name,
            'description' => $this->postString('description'),
            'active' => isset($_POST['active']),
            'snippets' => $snippets,
        ];
        $config['groups'] = $groups;
        $this->store->replace($config);
        return $this->notice($this->t('group_saved'));
    }

    private function toggleGroup(): string
    {
        $id = $this->postString('id');
        $config = $this->store->config();
        if (!isset($config['groups'][$id]) || !is_array($config['groups'][$id])) {
            return $this->notice($this->t('group_not_found'), true);
        }
        $config['groups'][$id]['active'] = ($config['groups'][$id]['active'] ?? false) !== true;
        $this->store->replace($config);
        return $this->notice($this->t('group_toggled'));
    }

    private function deleteGroup(): string
    {
        $id = $this->postString('id');
        if ($this->postString('confirm') !== '1') {
            return $this->notice($this->t('delete_requires_confirmation'), true);
        }
        $config = $this->store->config();
        if (!isset($config['groups'][$id])) {
            return $this->notice($this->t('group_not_found'), true);
        }
        unset($config['groups'][$id]);
        $this->store->replace($config);
        return $this->notice($this->t('group_deleted'));
    }

    private function saveSettings(): string
    {
        $config = $this->store->config();
        $config['settings'] = [
            'avoid_duplicates' => isset($_POST['avoid_duplicates']),
            'selection_mode' => 'random',
            'diagnostics_logging' => isset($_POST['diagnostics_logging']),
        ];
        $this->store->replace($config);
        return $this->notice($this->t('settings_saved'));
    }

    private function toggleForm(string $id, bool $active, array $user): string
    {
        return '<form method="post" action="/admin/plugin/banerina" class="banerina-inline-form">' . $this->csrf($user)
            . '<input type="hidden" name="action" value="toggle_group"><input type="hidden" name="id" value="' . $this->e($id) . '">'
            . '<button class="admin-link-button" type="submit" title="' . $this->e($active ? $this->t('disable') : $this->t('enable')) . '" aria-label="' . $this->e($active ? $this->t('disable') : $this->t('enable')) . '">' . $this->icon($active ? 'hide' : 'show') . '</button></form>';
    }

    private function icon(string $name): string
    {
        return '<svg class="admin-icon" aria-hidden="true" focusable="false"><use href="#admin-icon-' . $this->e($name) . '"></use></svg>';
    }

    private function statCard(string $value, string $label, bool $labelContainsHtml = false): string
    {
        return '<section class="admin-panel"><strong>' . $this->e($value) . '</strong><span>' . ($labelContainsHtml ? $label : $this->e($label)) . '</span></section>';
    }

    private function assets(): string
    {
        return '<link rel="stylesheet" href="' . $this->e(mur_asset_url('plugins/banerina/admin.css')) . '"><script src="' . $this->e(mur_asset_url('plugins/banerina/admin.js')) . '" defer></script>';
    }

    private function csrf(array $user): string
    {
        return '<input type="hidden" name="_csrf" value="' . $this->e((string) ($user['csrf'] ?? '')) . '">';
    }

    private function validCsrf(array $user): bool
    {
        $expected = (string) ($user['csrf'] ?? '');
        $submitted = $this->postString('_csrf');
        return $expected !== '' && $submitted !== '' && hash_equals($expected, $submitted);
    }

    private function notice(string $message, bool $error = false): string
    {
        return '<div class="admin-notice' . ($error ? ' admin-notice--error' : '') . '" role="status">' . $this->e($message) . '</div>';
    }

    private function getString(string $key): string
    {
        return $this->scalarString($_GET[$key] ?? '');
    }

    private function postString(string $key): string
    {
        return $this->scalarString($_POST[$key] ?? '');
    }

    private function scalarString(mixed $value): string
    {
        return is_string($value) || is_int($value) || is_float($value)
            ? trim((string) $value)
            : '';
    }

    private function t(string $key, array $replace = []): string
    {
        return $this->translator->t($key, $replace);
    }

    private function e(string|int|float|null $value): string
    {
        return mur_escape($value);
    }
}
