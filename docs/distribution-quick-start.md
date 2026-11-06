# MurCMS production distribution quick start

Дата: 2026-09-07  
Статус: краткий сценарий для MurCMS `0.4.2`

Полные требования и update-safe правила находятся в
[`installation-update.md`](installation-update.md). Этот файл не дублирует весь installation reference.

## Root-only server package

Production root package содержит server tree `root/`: core, assets, templates, plugins, starter content
и distributable `data/config/*.dist.php`. Рабочие site-specific configs и runtime data конкретной
установки не должны подменяться defaults при обновлении.

Если сборка выполняется из полного repository, используйте только реально присутствующий build script.
Предоставленный root/documentation package сам по себе не гарантирует наличие `scripts/`, `tests/` или
`_dist/`.

## Первый запуск

1. Укажите document root веб-сервера на `root/`.
2. Создайте рабочие конфиги из `data/config/*.dist.php`; для `admin.php` в полном
   repository используйте `scripts/create-admin-config.ps1` и задайте уникальный пароль.
3. В `data/config/site.php` установите реальный HTTPS `base_url`, `environment => production` и timezone.
4. Проверьте языки, enabled plugins, `snippets.allow_php` и admin users.
5. Убедитесь, что публичный package не содержит общей admin-учётной записи, затем
   войдите через `/admin/login` с локально созданными credentials.
6. Настройте контент, меню, home/footer layouts и site-specific CSS.
7. Пройдите [`prerelease-checklist.md`](prerelease-checklist.md).

## Минимальные server requirements

- PHP `8.5+`;
- `session`, `json`, `mbstring`;
- `zip` / `ZipArchive` для штатных ZIP admin-actions;
- GD с `imagewebp()` либо Imagick для WebP conversion;
- Apache 2.4 + `mod_rewrite` + `.htaccess` support.

Подробности: [`installation-update.md`](installation-update.md).

## Контент и дизайн

MurCMS content использует `.mur.md`. Для синтаксиса и построения сайта см.
[`ai-site-building-guide.md`](ai-site-building-guide.md).

Reusable template code находится в `murcms/templates/{template}/`. Для оформления конкретного сайта
предпочитайте update-safe:

```text
uploads/murcms-custom/{template}/critical.css
uploads/murcms-custom/{template}/custom.css
```

Home/footer blocks публикуются только через ordered layouts `/admin/template/home` и
`/admin/template/footer`; существование файла само по себе не означает публикацию.

## Перед публикацией

Проверьте основные public URLs всех языков, nested/listing/category pages, menu, используемые plugins,
Media Manager, role boundaries, `/robots.txt`, `/sitemap.xml`, 404 и недоступность private files.
Если полный repository содержит собственные smoke/lint scripts, запустите их дополнительно; не считайте
их частью root-only package без фактического наличия.
