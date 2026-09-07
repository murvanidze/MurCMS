# MurCMS admin: content editing

Date: 2026-09-07
Status: current MurCMS 0.4.1 behavior

The pages and categories indexes use a compact three-column table. Title, ID
and public URL share the first wide cell; status and row actions remain in
separate columns.

The shared row-action toolbar is a single non-wrapping inline flex row. Its
table cell sizes to the toolbar content, while the informational cell remains
the flexible column; narrow screens use the existing horizontal table scroll.
For nested categories, the calculated hierarchy depth is applied to one
`admin-document-content` wrapper around title, ID, URL, and future metadata.
Status, actions, and selection cells are never indented.

The pages index defaults to the primary language. Its compact GET filter can
switch to another single language or to an explicit all-languages overview.
The overview is read-only for bulk purposes: it has no row checkboxes or bulk
panel. The same select also contains mutually exclusive category and
direct-parent choices grouped by language. The independent status select can
be combined with any of them and accepts all, published, or draft pages.
Parent choices include root pages ("Без родителя") and pages that are actually
used as a parent. A third select controls the list size: all matching pages
(the default), 50, or 10. Pagination preserves the structural filter, status,
and page size.
The categories index remains unchanged.

## Main routes

```text
/admin/content/pages
/admin/content/categories
/admin/content/snippets
/admin/files/edit
/admin/files/delete
/admin/files/upload
/admin/files/replace
/admin/files/download
/admin/settings/site
/admin/template
/admin/template/home
/admin/template/footer
/admin/settings/theme/menu
/admin/plugins
/admin/plugin/gallery
/admin/plugin/blocks
/admin/plugin/tags
```

`admin` can use all listed routes. The current content role is `editor`; it can use content editing, plugin content tools and Media Manager, but not SEO, site settings, snippet editing or plugin toggles. The legacy role name `content` is normalized to `editor` only for backward compatibility.

## Pages and categories

Lists have a compact per-row toolbar:

- edit;
- clone;
- download source file;
- replace the physical `.mur.md` file;
- delete with confirmation.

Each row in a single-language pages view has a selection checkbox. The bulk
panel below the list can select all currently displayed rows, invert the
current selection, download selected source files as one ZIP, change status,
assign a parent, add one or more categories, or delete selected pages. The
all-languages overview and the categories index do not expose these controls.

Bulk parent and category targets are limited to the current list language. All
selected pages must belong to that language; the server rejects forged
mixed-language selections and reports their counts by language. Parent
assignment remains single-choice and runs the same missing parent, cycle, and
URL collision checks used by the single-page editor. "Добавить в рубрики"
accepts multiple checkboxes, including parent and child categories together,
without removing existing category assignments.

Bulk deletion requires `Y`, `y`, or `1`. Before deleting the first source file,
MurCMS creates one ZIP archive under:

```text
root/data/storage/backups/bulk-pages/
```

The normal rotating per-file backups are also retained.

Actions are rendered as compact SVG icon buttons. Their text remains available
through tooltips and accessible labels, but does not occupy visual space.
Published/draft status is also shown as an icon with an accessible label.

The editor splits the `.mur.md` document into GUI fields for common front matter and a full-width body textarea. Unknown scalar meta fields are preserved through the `Extra meta` textarea.

Common editable fields:

```text
id
title
slug
status
parent
template
translation_key
order
seo.title
seo.description
summary
page.header
list.source
list.view
list.sort
list.direction
list.per_page
list.position
tags
```

The page editor also exposes a category tree with checkboxes. Selected categories are stored in the scalar front-matter field:

```text
categories: company, company-news
```

`parent` and `categories` have different purposes:

- `parent` controls page/category hierarchy and nested URLs;
- `categories` assigns a page to one or more editorial categories.

When the `tags` plugin is enabled, the page editor also shows a tags field with
existing tag suggestions for the current language. Tags are stored in the page
front matter:

```text
tags: seo, pagespeed, cms
```

If the plugin is disabled, the field is no longer rendered but the scalar meta
line is still preserved through `Extra meta`.

Category pages use their assigned pages as the default listing source. A listing can still explicitly use `list.source: children` for parent/child page structures.

Categories are stored as normal documents in:

```text
root/data/content/{lang}/categories/{id}.mur.md
```

Nested categories use the `parent` field with the parent category `id`. Demo category files now exist for `ru` and `ro`, including a nested `company-news` category under `company`.

## Editor layout and help mode

The page/category editor groups metadata into:

- main parameters;
- page categories;
- plugin fields such as tags;
- SEO;
- listing settings;
- compact service parameters;
- extra metadata and body.

Long fields use a two-column label/control layout on desktop and collapse to one column on narrow screens. Parameter labels in this editor are Russian in the current prototype.

Listing settings, service parameters, and extra metadata are placed in
collapsed disclosure blocks. Main parameters, page categories, and SEO remain
open by default.

`list.per_page` enables public pagination for a listing. A blank value disables
pagination; values from 1 to 100 set the number of items per page. Subsequent
pages use the `?page=N` query parameter.

`list.position` controls where the generated listing is inserted:

- `top`;
- `bottom`;
- `shortcode`, which renders only at a separate `[list]` line in the body.

If a listing field is empty, renderer uses global defaults from
`/admin/template`, separately for children lists and category lists.

The `titles` view is strict: it suppresses images and descriptions even if an
older file still contains `list.show_image: true` or
`list.show_description: true`.

GUI fields whose keys contain dots (`seo.*`, `list.*`, `page.header`) are read
through the PHP-normalized POST name as well as the original name. Saving them
through the admin editor therefore persists the expected dotted front-matter
key.

The page list renders one language section by default and can explicitly render
all language sections for overview. Category lists remain separated by
language, with the default language first. Category rows follow their
parent/child tree and use visual indentation. Row actions use a small embedded
SVG icon set instead of a third-party icon font.

Admin help mode is configured at:

```text
/admin/settings/admin
```

When enabled, concise explanations and examples appear below editor fields. The setting only changes the admin UI and does not modify content files.

## File-backed collections

There is no combined `Content → Blocks` screen. Each file-backed entity stays in
its logical admin section:

```text
root/data/content/{lang}/home/*.mur.md
root/data/content/{lang}/footer/*.mur.md
root/data/blocks/{lang}/carousel/*.mur.md
root/data/blocks/{lang}/counters/*.mur.md
root/data/blocks/{lang}/logos/*.mur.md
root/data/galleries/{lang}/*.mur.md
```

Pages and categories remain under Content, home/footer under Template, while
Blocks and Gallery register their own menu entry, admin route and file-collection
definition through the public Plugin API. Disabling either plugin removes both its menu
entry and access to its registered file scope.

Home/footer rendering is configured separately from `/admin/template/home` and
`/admin/template/footer` by ordered file name lists without the `.mur.md` suffix.
The file name is the block identifier;
legacy `id`, `status`, `order`, and `footer.position` fields are not used. The
footer list additionally accepts `menu:{name}` entries, such as `menu:footer`,
to place a menu between blocks. The theme screen provides create folder, create
block, clone, edit, download, and delete actions. Nested paths under
`content/{lang}/home` and `content/{lang}/footer` are allowlisted by the shared
editor.

In flexible language mode each enabled language has its own textarea and copy
buttons. Mirror mode has one textarea for the default language. A missing block
in a non-default language falls back by file name to the default language.

The shared file editor validates paths before writing and only allows locations
registered by core or an enabled plugin. Every `.mur.md` row has Replace next to
Download. Replace accepts one file up to 2 MB, ignores its uploaded name, creates
a timestamped backup in `root/data/storage/backups/file-replacements/`, and then
atomically writes under the original target filename. The document body and meta
are not parsed or changed.

Each collection also has a separate single-file Upload form with an explicit
language/global scope and, where necessary, target subtype. Upload accepts a safe
basename ending in `.mur.md`; collisions become `-1`, `-2`, and so on without
changing the file contents. JavaScript submits these forms through AJAX and shows
the result in place; the multipart POST remains the no-JavaScript fallback.

For known pipe-separated data files, the shared editor shows a compact syntax
hint above the textarea. The hint is selected by file location, not by file
name: any `blocks/{lang}/logos/*.mur.md` file uses the logos format, menu files
use the menu format, and carousel/counters/gallery files use their own formats.

Menu hints include both supported layouts:

```text
href | label | title | class | span_class | attrs
href | short_label | long_label | title | class | span_class | attrs
```

The seven-column layout provides separate desktop/mobile labels only for the
top level of `main`. Nested `[`/`]` groups can be deeper than one level, and
the top-level class `menu-mega` switches the desktop dropdown to columns. The
opening `[` goes before the parent item and the closing `]` after its last
child.

## Snippets / ushki

Snippets can be language-specific or global:

```text
root/data/snippets/{lang}/{name}.mur.md
root/data/snippets/{name}.mur.md
```

The snippets screen uses the same language grouping and includes reserved
`system-*` insertion names in the normal snippet workflow.

It also contains a scope-aware registry of all 11 system positions. The
registry distinguishes an exact file from a global/default-language fallback
and offers Create, Create override, or Edit without creating a file before the
editor form is saved. System snippets are omitted from the ordinary snippet
list so the registry remains the single reference table.

Use global scope for analytics, banners, counters, and code fragments that must exist in every language version.

Public usage:

```text
[snippet=name]
[ushka=name]
```

Snippet editing is admin-only because these files may contain scripts or embeds.

## Site and theme settings

`/admin/settings/site` currently edits:

```text
name
description
favicon_frontend
favicon_admin
```

Favicon paths are relative to `root/uploads/` and accept PNG, SVG, or WebP.
An empty frontend field enables automatic discovery of the first naturally
sorted `favicon*.png|svg|webp` file in the uploads root; subdirectories are not
scanned. An empty admin field uses the standard MurCMS SVG icon. Missing or
invalid manual files fall back in the same way.

Other `site.php` values such as `base_url`, `timezone`, `environment`, and
`home_redirect_status` are displayed in the current config table but are not
edited by this form.

The separate `Template` navigation group follows `Settings` in the admin
sidebar. `/admin/template` edits the active frontend template, `default`
template options, global listing defaults, fallback footer menu
visibility/name, and menu files. `/admin/template/home` and
`/admin/template/footer` edit their respective ordered layouts and provide the
block file operations. `/admin/settings/theme` remains a compatibility alias for
the general page.
For new layouts, `menu:{name}` in the footer list is preferred because it gives
an exact position; the separate footer menu checkbox remains a compatibility
fallback.

`Template → General` has a separate custom CSS form for the active frontend
template. It stores trusted administrator content outside distributable
template assets:

```text
root/uploads/murcms-custom/{template}/critical.css
root/uploads/murcms-custom/{template}/custom.css
```

The first file is inlined after `layout.head`; the second is linked immediately
after it with an `mtime` query parameter. Empty fields remove their files.
Limits are 64 KB and 256 KB respectively, and writes use a temporary file plus
atomic rename.

When the optional MurText plugin is enabled, it can add parser overrides to the
document form and a toolbar/help dialog before document, snippet, and file
textareas. Its public admin assets are external and conditional; the help
dialog does not create a nested form, so the surrounding save form remains
valid.

Page and category deletion requires `Y`, `y`, or `1` in the confirmation field.
Other generic file deletion screens continue to require the file name.

Menu files are edited through the shared file editor with a dedicated allowlist:

```text
root/data/menus/{lang}/main.mur.md
root/data/menus/{lang}/footer.mur.md
```

## Plugins

`/admin/plugins` reads folders from `root/murcms/plugins/*` and writes enabled plugin IDs to:

```text
root/data/config/plugins.php
```

The current UI enables/disables plugins by checkbox. Plugin-specific admin pages
are grouped under the "Plugins" menu. Gallery and Blocks expose file management
through the shared neutral core service registered with `admin.file_collections`.
The tags plugin page shows current tags and lets
admin users configure tag cloud limits, counters, sitemap inclusion and tag-page
pagination.

## Safety notes

- All POST actions use CSRF tokens.
- Upload and Replace accept one `.mur.md` file up to 2 MB and never parse its content.
- File writes use path allowlists and workspace-root checks.
- Delete actions require typed confirmation.
- Existing document IDs and language branches cannot be changed in place.
- Saving checks duplicate IDs, URL paths, translation keys, missing parents and
  parent cycles before writing.
- Saving or deleting a page/category rotates two copies in
  `root/data/storage/backups/content/...bak.1` and `.bak.2`.
- A parent document cannot be deleted while it has children. A category cannot
  be deleted while pages still reference it.
- `.htaccess` editing is in the SEO section and writes `root/.htaccess.bak` before replacing an existing file.
