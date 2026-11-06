# MurCMS Codex Notes

This repository contains the current MurCMS prerelease candidate. Before changing code or content, read:

- `docs/current-reference.md`

For building or adapting a complete website on MurCMS, also read:

- `docs/ai-site-building-guide.md`
- `docs/prerelease-checklist.md`

Before synchronizing engine changes to a separate installation such as Parastas,
obtain and read its current installation-specific sync notes.

Project priorities:

- lightweight PHP flat-file CMS;
- fast local/server rendering;
- accessibility and Lighthouse/PageSpeed quality;
- multilingual URL branches such as `/ru/` and `/ro/`;
- no jQuery or heavy frontend libraries for basic UI;
- conditional plugin assets only when a shortcode is present;
- stable image dimensions and no avoidable layout shift.

Current implemented shortcodes:

```text
[snippet=name]
[ushka=name]
[gallery=name]
[carousel=name]
[counters=name]
[logos=name]
[list]
[tagcloud]
```

Development rules:

- Prefer existing architecture: `ContentRepository`, `Renderer`, `Hooks`, `PluginManager`.
- Keep content in `.mur.md` files unless a task explicitly needs code.
- Use plugins/hooks for optional behavior.
- Do not copy code directly from external reference systems in `sources/`; use them only for ideas.
- Keep CSS that affects layout as normal stylesheet, not delayed `media=print` CSS.
- Add JS with `defer`, and only conditionally when possible.
- For images, provide `width`, `height`, `loading`, and `decoding` where applicable.
- Check PHP with `php -l` and JS with `node --check` after edits.

Admin:

- Admin routes live under `/admin/`.
- Auth config is `root/data/config/admin.php`.
- Current roles are `super_admin`, `admin`, and `editor`; see
  `docs/admin/users.md`. Legacy `content` is an editor alias only.
- Plugin admin extensions use `admin.menu` and `admin.route` hooks.
- Do not add write/edit actions without CSRF, role checks, and careful filesystem path validation.
- SEO is a core admin section, not a plugin. See `docs/admin/seo-snippets.md`.
- Snippets/"ushki" are edited at `/admin/content/snippets` and can contain sensitive HTML, so keep write access admin-only.
- Current admin content editing behavior is documented in `docs/admin/content.md`.
- Current admin file/image manager behavior is documented in `docs/admin/media.md`.
- Current frontend/admin language behavior is documented in `docs/admin/languages.md`.
- The shared admin file editor may only write allowlisted `.mur.md`/SEO files inside the CMS root; preserve that constraint when adding editors.
