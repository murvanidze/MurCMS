<?php
declare(strict_types=1);

namespace Mur\Admin;

use Mur\Core\Document;

if (!defined('MUR_CMS_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('No direct script access allowed.');
}

final class FrontendAdminBar
{
    public function __construct(private readonly AdminAuth $auth)
    {
    }

    public function render(array $context): string
    {
        if (!$this->auth->hasSessionCookie() || !$this->auth->isLoggedIn()) {
            return '';
        }

        $document = $context['document'] ?? null;
        $meta = is_array($context['meta'] ?? null) ? $context['meta'] : [];
        $editUrl = $document instanceof Document ? $this->editUrl($document) : '';
        $rows = [
            'Title' => (string) ($meta['title'] ?? ''),
            'Description' => (string) ($meta['description'] ?? ''),
            'Canonical' => (string) ($context['canonical'] ?? ''),
            'Language' => (string) ($context['language'] ?? ''),
            'Robots' => (string) ($document instanceof Document ? ($document->meta['seo.robots'] ?? 'index, follow') : 'index, follow'),
            'Open Graph title' => (string) ($meta['title'] ?? ''),
            'Open Graph description' => (string) ($meta['description'] ?? ''),
            'Open Graph URL' => (string) ($context['canonical'] ?? ''),
        ];

        if ($document instanceof Document) {
            $rows['Document ID'] = $document->id();
            $rows['Collection'] = $document->collection;
            $rows['Status'] = $document->status();
        }

        $table = '';
        foreach ($rows as $label => $value) {
            $table .= '<tr><th scope="row">' . mur_escape($label) . '</th><td>' . mur_escape($value !== '' ? $value : '—') . '</td></tr>';
        }

        $edit = $editUrl !== ''
            ? '<a class="mur-admin-bar__button" href="' . mur_escape($editUrl) . '">Редактировать страницу</a>'
            : '';

        return '<style>
.mur-admin-bar{position:fixed;right:12px;bottom:12px;z-index:10000;display:flex;gap:8px;align-items:center;padding:8px;border:1px solid #cbd5e1;border-radius:10px;background:#fff;color:#172033;box-shadow:0 10px 35px rgba(15,23,42,.2);font:600 14px/1.2 system-ui,sans-serif}
.mur-admin-bar__button{display:inline-flex;align-items:center;min-height:36px;padding:7px 12px;border:1px solid #cbd5e1;border-radius:7px;background:#fff;color:#172033;text-decoration:none;cursor:pointer;font:inherit}.mur-admin-bar__button:hover,.mur-admin-bar__button:focus-visible{border-color:#64748b;background:#f8fafc}.mur-admin-meta{width:min(760px,calc(100vw - 32px));max-height:calc(100vh - 32px);padding:0;border:1px solid #cbd5e1;border-radius:12px;color:#172033;background:#fff}.mur-admin-meta::backdrop{background:rgba(15,23,42,.58)}.mur-admin-meta__inner{padding:22px}.mur-admin-meta h2{margin:0 0 16px;color:#172033;font:700 22px/1.2 system-ui,sans-serif}.mur-admin-meta table{width:100%;border-collapse:collapse;font:14px/1.45 system-ui,sans-serif}.mur-admin-meta th,.mur-admin-meta td{padding:9px;border-bottom:1px solid #e2e8f0;text-align:left;vertical-align:top;overflow-wrap:anywhere}.mur-admin-meta th{width:180px;color:#475569}.mur-admin-meta__actions{display:flex;justify-content:flex-end;margin-top:18px}@media(max-width:560px){.mur-admin-bar{right:8px;bottom:8px;left:8px;justify-content:flex-end}.mur-admin-bar__button{padding-inline:10px}.mur-admin-meta th{width:120px}}
</style>
<aside class="mur-admin-bar" aria-label="Инструменты администратора">' . $edit . '<button class="mur-admin-bar__button" type="button" data-admin-meta-open>Показать метаданные</button><a class="mur-admin-bar__button" href="/admin/">Админка</a></aside>
<dialog class="mur-admin-meta" data-admin-meta-dialog><div class="mur-admin-meta__inner"><h2>Метаданные страницы</h2><table><tbody>' . $table . '</tbody></table><div class="mur-admin-meta__actions"><button class="mur-admin-bar__button" type="button" data-admin-meta-close>Закрыть</button></div></div></dialog>
<script>(function(){var d=document.querySelector("[data-admin-meta-dialog]");var o=document.querySelector("[data-admin-meta-open]");var c=document.querySelector("[data-admin-meta-close]");if(!d||!o||!c){return}o.addEventListener("click",function(){d.showModal()});c.addEventListener("click",function(){d.close()});d.addEventListener("click",function(e){if(e.target===d){d.close()}})})();</script>';
    }

    private function editUrl(Document $document): string
    {
        if (!in_array($document->collection, ['pages', 'categories'], true)) {
            return '';
        }

        return '/admin/content/' . $document->collection . '/edit?lang=' . rawurlencode($document->language)
            . '&id=' . rawurlencode($document->id());
    }
}
