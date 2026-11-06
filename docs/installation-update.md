# MurCMS installation and update

Дата: 2026-09-07  
Статус: каноническая инструкция для MurCMS `0.4.2`

Этот документ владеет требованиями к окружению, моделью `*.dist.php`/runtime-конфигов,
writable paths и update-safe процедурой. Короткий сценарий первого запуска находится в
[`distribution-quick-start.md`](distribution-quick-start.md).

## 1. Требования

Базовая целевая платформа:

- PHP `8.5+`;
- Apache 2.4 с `mod_rewrite` и разрешённым чтением `.htaccess`;
- PHP `session` и `json`;
- PHP `mbstring` для полноценной Unicode-обработки;
- PHP `zip` / `ZipArchive` для штатных ZIP-функций админки: bulk download и ZIP-backup перед массовым удалением страниц;
- WebP-конвертация изображений требует GD с `imagewebp()` либо Imagick.

Рекомендуются OPcache, `mod_headers`, `mod_expires`, `mod_deflate` и Imagick. Если WebP-конвертер
не установлен, уже подготовленные WebP-файлы можно загружать без перекодирования, а JPEG/PNG/GIF
можно сохранять в исходном формате при отключённой конвертации. Отсутствие `ZipArchive` не является
поддерживаемым полным admin baseline: ZIP-actions завершатся ошибкой.

Production обычно должен использовать `display_errors=Off` и `log_errors=On`.

## 2. Document root

Document root веб-сервера должен указывать непосредственно на каталог `root/` установки MurCMS,
а не на родительский каталог проекта.

Критические private paths защищаются `.htaccess`. На сервере необходимо проверить, что через HTTP
не выдаются `data/`, `murcms/app/`, `murcms/plugins/`, `*.mur.md`, `*.dist.php`, backup/log-файлы,
а PHP/скрипты внутри `uploads/` не исполняются.

## 3. Distributable defaults и runtime config

В дистрибутив входят шаблоны конфигурации:

```text
root/data/config/*.dist.php
```

Рабочие site-specific конфиги имеют те же имена без `.dist`:

```text
root/data/config/site.php
root/data/config/languages.php
root/data/config/admin.php
root/data/config/plugins.php
root/data/config/snippets.php
root/data/config/murtext.php
root/data/config/murconsent.php
...
```

Ключевое правило: `*.dist.php` принадлежит дистрибутиву, рабочий `*.php` — конкретному сайту.
Обновление кода не должно безусловно перезаписывать существующие runtime-конфиги. Новые параметры
из `.dist.php` переносятся в site config осознанно либо через штатный admin UI конкретной подсистемы.

Mutable configuration плагина также хранится в `data/config/`, а не внутри
`murcms/plugins/<plugin>/`. Каталог plugin code должен считаться read-only runtime-кодом.

## 4. Writable paths

PHP-процессу нужны права записи только там, где конкретная установка использует редактирование:

```text
root/data/config/
root/data/content/
root/data/blocks/
root/data/galleries/
root/data/menus/
root/data/snippets/
root/data/storage/
root/uploads/
```

Если `.htaccess` редактируется через SEO-раздел, нужен controlled write access к `root/.htaccess`.
Не выдавайте всему `root/` права записи и не используйте `0777` как стандартную настройку.

## 5. Новая установка

1. Распакуйте root-пакет так, чтобы `index.php`, `data/`, `murcms/` и `uploads/` оказались в document root.
2. Создайте рабочие конфиги из соответствующих `.dist.php`. Публичный
   `admin.dist.php` не содержит пользователей; в полном repository создайте
   уникальный `admin.php` интерактивным `scripts/create-admin-config.ps1`.
3. В `data/config/site.php` задайте реальный HTTPS `base_url`, `environment => production` и часовой пояс.
4. Проверьте `languages.php`, `plugins.php`, `snippets.php` и реальные admin users.
5. Проверьте, что в установке нет общей distribution/starter учётной записи и
   используется уникальный пароль, созданный только для этого сайта.
6. Проверьте `.htaccess`, writable paths, `/robots.txt`, `/sitemap.xml` и `/admin/login`.
7. Выполните полный [`prerelease-checklist.md`](prerelease-checklist.md).

## 6. Обновление существующего сайта

Перед обновлением сохраните как минимум:

```text
root/data/
root/uploads/
root/.htaccess
```

Затем:

1. сначала примените обновление на staging/копии;
2. замените distributable core/plugin/template code и assets;
3. не заменяйте существующие site-specific `data/config/*.php` новыми defaults;
4. сравните новые `*.dist.php` с рабочими конфигами и перенесите необходимые параметры;
5. не удаляйте пользовательские `uploads/murcms-custom/{template}/critical.css` и `custom.css`;
6. выполните migration notes конкретных плагинов, если они есть;
7. повторите server/security/browser checks.

MurText `0.2.4` использует update-safe `data/config/murtext.php`; legacy
`murcms/plugins/murtext/config.php` допускается только как источник миграции старой установки.
Admin assets MurText поставляются только через `murcms/assets/plugins/murtext/`; plugin-local каталог
`murcms/plugins/murtext/assets/` в актуальном root-only package отсутствует.
MurConsent `1.0.3` использует `data/config/murconsent.php` и может предварительно мигрировать legacy
`data/config/privacy-consent.php`, если новый runtime config ещё не создан.

## 7. Assets конкретного сайта

Reusable template code находится в:

```text
root/murcms/templates/{template}/
```

Site-specific CSS должен в первую очередь храниться update-safe:

```text
root/uploads/murcms-custom/{template}/critical.css
root/uploads/murcms-custom/{template}/custom.css
```

Эти файлы управляются через `Template → General`. Не встраивайте client-specific CSS в
дистрибутивный template только потому, что он относится к первому экрану.

## 8. Проверка после обновления

Минимум проверьте:

- главные и обычные страницы всех включённых языков;
- nested/listing/category URLs;
- menu, home/footer layouts, Media Manager и используемые plugins;
- login и role boundaries `editor/admin/super_admin`;
- ZIP-actions, если они доступны пользователю;
- custom critical/additional CSS;
- `/robots.txt`, `/sitemap.xml`, 404;
- отсутствие HTTP-доступа к private/runtime files.

Если полный repository содержит project-specific lint/smoke scripts, запускайте их дополнительно.
Этот documentation/root package сам по себе не гарантирует наличие `scripts/test.ps1`,
`tests/smoke.php` или build scripts, поэтому их отсутствие нельзя трактовать как ошибку установки.

## Updating to 0.4.2

Use MurCMS-0.4.2-update.zip for an existing site and MurCMS-0.4.2-root.zip only for a new installation. Overlay the contents of root/ into the document root. Preserve all runtime data/config, content, uploads/custom CSS, data/templates/*/functions.php and .htaccess. New seo.dist.php enables sitemap lastmod only for new sites; keep the existing seo.php choice and enable it explicitly in SEO → Sitemap if desired. Home SEO adds an optional home[language][title|description] map to seo.php. No bulk content migration is required. Updated_at is created on first editor save if missing. Banerina is installed separately; existing installations keep their working plugins.php and Banerina runtime data. To enable the newly bundled BerryMur on an existing site, use plugin management.
