<?php
declare(strict_types=1);

namespace Mur\Admin;

if (!defined('MUR_CMS_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('No direct script access allowed.');
}

final class AdminRenderer
{
    public function __construct(
        private readonly array $site,
        private readonly AdminI18n $i18n,
    )
    {
    }

    public function login(string $error, string $csrfToken, string $loginValue = ''): string
    {
        $errorHtml = $error !== '' ? '<div class="admin-alert" role="alert">' . mur_escape($this->t($error)) . '</div>' : '';

        return $this->document($this->t('Login'), '<main class="admin-login">
  <form class="admin-login__box" method="post" action="/admin/login">
    <input type="hidden" name="_csrf" value="' . mur_escape($csrfToken) . '">
    <h1>' . mur_escape($this->t('MurCMS Admin')) . '</h1>
    <p>' . mur_escape($this->t('Введите логин или e-mail и пароль.')) . '</p>
    ' . $errorHtml . '
    <label>' . mur_escape($this->t('Логин или e-mail')) . '
      <input name="login" autocomplete="username" value="' . mur_escape($loginValue) . '" required>
    </label>
    <label>' . mur_escape($this->t('Пароль')) . '
      <input name="password" type="password" autocomplete="current-password" required>
    </label>
    <button class="admin-button" type="submit">' . $this->icon('login') . '<span>' . mur_escape($this->t('Войти')) . '</span></button>
  </form>
</main>');
    }

    public function page(string $title, string $content, array $navGroups, array $user, string $active): string
    {
        $nav = '';

        foreach ($navGroups as $group) {
            $items = $group['items'] ?? [];

            if (!is_array($items) || $items === []) {
                continue;
            }

            $nav .= '<section class="admin-nav__group">';
            $nav .= '<h2>' . mur_escape($this->t((string) ($group['label'] ?? ''))) . '</h2>';

            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }

                $id = (string) ($item['id'] ?? '');
                $class = $id === $active ? ' class="is-active"' : '';
                $newWindow = ($item['target'] ?? '') === '_blank' ? ' target="_blank" rel="noreferrer"' : '';
                $nav .= '<a' . $class . ' href="' . mur_escape((string) ($item['url'] ?? '#')) . '"' . $newWindow . '>' . mur_escape($this->t((string) ($item['label'] ?? $id))) . '</a>';
            }

            $nav .= '</section>';
        }

        $breadcrumbs = $this->breadcrumbs($title, $navGroups, $active);
        $html = '<div class="admin-shell">
  <aside class="admin-sidebar">
    <div class="admin-brand-block">
      <a class="admin-brand" href="/admin/"><img class="admin-brand-icon" src="' . mur_escape(mur_asset_url('site-icon.svg')) . '" width="32" height="32" alt="" aria-hidden="true"><span>MurCMS</span></a>
      <span class="admin-site-name" title="' . mur_escape((string) ($this->site['name'] ?? '')) . '">' . mur_escape((string) ($this->site['name'] ?? '')) . '</span>
    </div>
    <nav class="admin-nav" aria-label="' . mur_escape($this->t('Admin menu')) . '">' . $nav . '</nav>
  </aside>
  <div class="admin-workspace">
    <header class="admin-topbar">
      <div>
        ' . $breadcrumbs . '
        <h1>' . mur_escape($this->t($title)) . '</h1>
      </div>
      <form method="post" action="/admin/logout">
        <input type="hidden" name="_csrf" value="' . mur_escape((string) ($user['csrf'] ?? '')) . '">
        <span>' . mur_escape((string) ($user['display_name'] ?? $user['username'] ?? '')) . '</span>
        <button class="admin-button admin-button--ghost" type="submit">' . $this->icon('logout') . '<span>' . mur_escape($this->t('Выйти')) . '</span></button>
      </form>
    </header>
    <main class="admin-content">' . $content . '</main>
  </div>
</div>';

        $scripts = '<script src="' . mur_escape(mur_asset_url('admin-theme.js')) . '" defer></script>';

        return $this->document($this->t($title), $html, $scripts);
    }

    public function errorPage(int $status, string $title, string $message, array $navGroups, array $user): string
    {
        http_response_code($status);

        return $this->page($title, '<section class="admin-panel"><p>' . mur_escape($this->t($message)) . '</p></section>', $navGroups, $user, '');
    }

    private function document(string $title, string $body, string $scripts = ''): string
    {
        return '<!doctype html>
<html lang="' . mur_escape($this->i18n->language()) . '">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex,nofollow">
  ' . mur_favicon_link($this->site, 'admin') . '
  <title>' . mur_escape($title) . ' - ' . mur_escape((string) ($this->site['name'] ?? 'MurCMS')) . '</title>
  <link rel="stylesheet" href="' . mur_escape(mur_asset_url('admin.css')) . '">
  ' . $scripts . '
</head>
<body>' . $this->iconSprite() . $body . '</body>
</html>';
    }

    private function icon(string $name): string
    {
        return '<svg class="admin-icon" aria-hidden="true" focusable="false"><use href="#admin-icon-' . mur_escape($name) . '"></use></svg>';
    }

    private function breadcrumbs(string $title, array $navGroups, string $active): string
    {
        $dashboard = $this->t('Панель управления');
        $items = [['label' => $dashboard, 'url' => $active === 'dashboard' ? '' : '/admin/']];
        $matchedGroup = null;
        $matchedItem = null;

        foreach ($navGroups as $group) {
            foreach ((array) ($group['items'] ?? []) as $item) {
                if ((string) ($item['id'] ?? '') === $active) {
                    $matchedGroup = $group;
                    $matchedItem = $item;
                    break 2;
                }
            }
        }

        if (is_array($matchedGroup) && (string) ($matchedGroup['label'] ?? '') !== 'Основное') {
            $items[] = ['label' => $this->t((string) ($matchedGroup['label'] ?? '')), 'url' => ''];
        }
        if (is_array($matchedItem)) {
            $itemLabel = $this->t((string) ($matchedItem['label'] ?? ''));
            if ($itemLabel !== $this->t($title)) {
                $items[] = ['label' => $itemLabel, 'url' => (string) ($matchedItem['url'] ?? '')];
            }
        }
        if ($active !== 'dashboard') {
            $items[] = ['label' => $this->t($title), 'url' => ''];
        }

        $html = '<nav class="admin-breadcrumbs" aria-label="' . mur_escape($this->t('Breadcrumbs')) . '"><ol>';
        foreach ($items as $index => $item) {
            $label = mur_escape((string) $item['label']);
            $url = (string) $item['url'];
            $html .= '<li>' . ($url !== '' && $index < count($items) - 1 ? '<a href="' . mur_escape($url) . '">' . $label . '</a>' : '<span' . ($index === count($items) - 1 ? ' aria-current="page"' : '') . '>' . $label . '</span>') . '</li>';
        }

        return $html . '</ol></nav>';
    }

    private function iconSprite(): string
    {
        return '<svg class="admin-icon-sprite" aria-hidden="true" focusable="false">
  <symbol id="admin-icon-add" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></symbol>
  <symbol id="admin-icon-edit" viewBox="0 0 24 24"><path d="m4 20 4.5-1 10-10-3.5-3.5-10 10L4 20zM13.5 7l3.5 3.5"/></symbol>
  <symbol id="admin-icon-delete" viewBox="0 0 24 24"><path d="M4 7h16M9 7V4h6v3M7 7l1 13h8l1-13M10 11v5M14 11v5"/></symbol>
  <symbol id="admin-icon-copy" viewBox="0 0 24 24"><rect x="8" y="8" width="11" height="11" rx="2"/><path d="M16 8V5a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h3"/></symbol>
  <symbol id="admin-icon-download" viewBox="0 0 24 24"><path d="M12 3v12M7 10l5 5 5-5M5 20h14"/></symbol>
  <symbol id="admin-icon-replace" viewBox="0 0 24 24"><path d="M20 7h-9M15 3l-4 4 4 4M4 17h9M9 13l4 4-4 4"/></symbol>
  <symbol id="admin-icon-save" viewBox="0 0 24 24"><path d="M5 3h12l2 2v16H5zM8 3v6h8V3M8 21v-7h8v7"/></symbol>
  <symbol id="admin-icon-view" viewBox="0 0 24 24"><path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6z"/><circle cx="12" cy="12" r="2.5"/></symbol>
  <symbol id="admin-icon-back" viewBox="0 0 24 24"><path d="m10 5-7 7 7 7M3 12h18"/></symbol>
  <symbol id="admin-icon-login" viewBox="0 0 24 24"><path d="M14 4h6v16h-6M10 8l4 4-4 4M14 12H3"/></symbol>
  <symbol id="admin-icon-logout" viewBox="0 0 24 24"><path d="M10 4H4v16h6M14 8l4 4-4 4M8 12h10"/></symbol>
  <symbol id="admin-icon-show" viewBox="0 0 24 24"><path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6z"/><circle cx="12" cy="12" r="2.5"/></symbol>
  <symbol id="admin-icon-hide" viewBox="0 0 24 24"><path d="m4 4 16 16M10.5 6.2A10.7 10.7 0 0 1 12 6c6 0 9.5 6 9.5 6a15 15 0 0 1-3 3.7M6.2 7.3A15.5 15.5 0 0 0 2.5 12s3.5 6 9.5 6c.9 0 1.8-.1 2.5-.4M9.9 9.9a3 3 0 0 0 4.2 4.2"/></symbol>
  <symbol id="admin-icon-published" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="m8 12 2.5 2.5L16 9"/></symbol>
  <symbol id="admin-icon-draft" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M8 12h8"/></symbol>
</svg>';
    }

    private function t(string $key): string
    {
        return $this->i18n->t($key);
    }
}
