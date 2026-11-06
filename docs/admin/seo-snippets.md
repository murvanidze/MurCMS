# MurCMS admin: SEO and snippets

Date: 2026-09-07
Status: current MurCMS 0.4.2 behavior

## SEO section

SEO is now a core admin section, not a plugin.

Routes:

```text
/admin/seo
/admin/seo/sitemap
/admin/seo/robots
/admin/seo/htaccess
```

Access: `admin` role only.

Core files:

```text
root/murcms/app/Core/SeoManager.php
root/data/config/seo.php
root/murcms/app/Admin/AdminRouter.php
```

Public routes handled through `SeoManager`:

```text
/robots.txt
/sitemap.xml
```

### Sitemap

Admin route:

```text
/admin/seo/sitemap
```

Current options:

- enable/disable sitemap output;
- include language home pages;
- include published documents;
- include `lastmod`;
- write a generated snapshot to `root/data/storage/generated/sitemap.xml`.

The public `/sitemap.xml` is still dynamic and generated from current content/config.

### robots.txt

Admin route:

```text
/admin/seo/robots
```

The editable template is stored in `root/data/config/seo.php`.

Supported placeholder:

```text
{SITEMAP_URL}
```

`{SITEMAP_URL}` is replaced with the absolute sitemap URL from `site.base_url`.
The obsolete `Host` directive is removed from defaults, editor output and public
`robots.txt`; saved legacy templates are filtered automatically.

### .htaccess

Admin route:

```text
/admin/seo/htaccess
```

The editor reads/writes:

```text
root/.htaccess
root/.htaccess.bak
```

If the file does not exist, the editor shows a recommended Apache starter template with:

- `Options -Indexes`;
- front-controller rewrite to `index.php`;
- commented HTTPS redirect;
- long-lived asset cache rules;
- `X-Content-Type-Options`.

When an existing `.htaccess` is saved, the previous version is copied to `root/.htaccess.bak` before replacement.

Important: `.htaccess` is high-risk. A bad rule can break an Apache-hosted site. Keep HTTPS redirect commented until SSL is confirmed.


## Snippets / "Ushki"

Snippets are the MurCMS equivalent of named insert fragments.

Admin route:

```text
/admin/content/snippets
```

Access: `admin` role only, because snippets can contain scripts, analytics code, banners, embeds, and other sensitive HTML.

Public usage:

```text
[snippet=name]
[ushka=name]
```

### System snippets

The snippets plugin silently checks these reserved names:

```text
system-head
system-body-start
system-header-before
system-header-after
system-main-before
system-body-end
system-content-start
system-article-start
system-article-end
system-content-end
system-main-after
```

Placement:

- `system-head` — immediately before `</head>`;
- `system-body-start` — immediately after `<body>`;
- `system-header-before` — immediately before the primary site header;
- `system-header-after` — immediately after the primary site header;
- `system-main-before` — immediately before `<main>`;
- `system-body-end` — immediately before `</body>`;
- `system-content-start` — at the start of `<main>`, before breadcrumbs and the page header;
- `system-article-start` — after breadcrumbs/page header and before page text or a category listing;
- `system-article-end` — after page text and its generated listing;
- `system-content-end` — at the end of `<main>`, after the page content.
- `system-main-after` — immediately after `</main>` and before the footer.

No placeholder files are required. If a system snippet does not exist, is a draft, or the snippets plugin is disabled, the insertion point produces no output.

The admin page shows these 11 names in one scope-aware actionable registry.
For the selected global or language scope it shows the exact file state and any
effective global/default-language fallback. Create and Create override only
prefill the existing editor; the `.mur.md` file appears on Save.

A language-specific system snippet overrides the global snippet with the same name because normal snippet lookup rules are used.

Storage:

```text
root/data/snippets/{lang}/{name}.mur.md
root/data/snippets/{name}.mur.md
```

Language-specific snippets live under `root/data/snippets/{lang}`. Global/all-language snippets live directly under `root/data/snippets`.

Lookup order is: current language, global/all, then the default site language.
An existing draft intentionally stops fallback and outputs nothing.

Format:

```text
id: banner1
status: published
type: html
---

<div>...</div>
```

Allowed names:

```text
A-Z a-z 0-9 _ -
```

Current editor can:

- list snippets by language or global scope;
- edit existing snippets;
- create new snippets;
- set `status`;
- set `type` as `html` or `php`;
- save the `.mur.md` file safely inside allowed snippet paths.

`type: php` is executed only when the site runtime config `root/data/config/snippets.php` explicitly sets `allow_php => true`. The distributable `snippets.dist.php` keeps `allow_php => false`, so PHP snippets are disabled by default. Snippet editing is admin-only, but enabling PHP remains high-risk: a syntax or runtime error can stop public page rendering.

## 0.4.2 workflows

The Snippets screen has Editor, My snippets, System, and Upload tabs in this order.
Editor starts with Name and Body; additional metadata accepts the same key: value
front matter as pages and is written before ---. Unknown valid keys survive a
round trip. Save returns 303 to the same scope/name. My snippets omits the Purpose
column. System shows exact local files versus fallback and supports edit, download,
replace, confirmed delete, and creation of missing overrides. Deleting a file leaves
the system position. Scope links are generated from enabled languages plus Global;
upload uses visible language/global radio buttons.

Home SEO is edited at /admin/seo and stored in seo.php under home[language][title]
and home[language][description]. Each field falls back from current to default
language, then to legacy site.name/site.description. Site.name remains one system
name. New seo.dist.php enables sitemap lastmod; existing seo.php is preserved.
Sitemap uses valid updated_at, then legacy date, then file mtime. Date controls and
permission semantics are described in [content.md](content.md).
