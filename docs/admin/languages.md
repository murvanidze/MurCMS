# MurCMS admin: languages and admin UI i18n

Date: 2026-09-07
Status: current MurCMS 0.4.2 behavior

## Frontend languages

Frontend languages are configured in:

```text
root/data/config/languages.php
```

Admin route:

```text
/admin/settings/languages
```

Current config keys:

```php
'default' => 'ru',
'available' => ['ru', 'ro', 'en', 'uk'],
'enabled' => ['ru', 'ro'],
'structure' => 'flexible',
'labels' => [
    'ru' => 'RU',
    'ro' => 'RO',
    'en' => 'EN',
    'uk' => 'UK',
],
```

Meaning:

- `available` is the list shown in admin as possible frontend languages;
- `enabled` controls public language branches, routing, menus, content loading, sitemap and language switcher;
- `default` is the default public language;
- `labels` are frontend language switcher labels;
- `structure` is `flexible` or `mirror`.

`mirror` is currently a visible/editable mode flag. It does not enforce page creation or translation checks; current documentation must not treat it as an enforced translation workflow.

## Admin UI language

Admin interface languages are independent from frontend languages.

Admin config:

```text
root/data/config/admin.php
```

Admin route:

```text
/admin/settings/admin
```

Current config keys:

```php
'ui' => [
    'default_language' => 'ru',
    'available_languages' => ['ru', 'ro', 'en', 'uk'],
],
```

Each admin-panel user can also have:

```php
'admin_language' => 'ru',
```

The selected admin language is stored in the session after login. Changing it
in `/admin/settings/admin` or `/admin/settings/profile` updates the current
user in `root/data/config/admin.php` and refreshes the session value. User
management and role definitions are documented in [`users.md`](users.md).

## Lang files

Admin lang files live in:

```text
root/murcms/admin/lang/{lang}.php
```

Current language files:

```text
root/murcms/admin/lang/ru.php
root/murcms/admin/lang/ro.php
root/murcms/admin/lang/en.php
root/murcms/admin/lang/uk.php
```

The i18n loader uses this fallback order:

1. selected admin language file;
2. Russian file;
3. the original key string.

Russian strings are used as translation keys. `ro.php`, `en.php` and `uk.php` already exist and translate the current core key set. The boundary is broader than the core dictionaries: plugin UI and isolated hardcoded strings may still fall back to Russian/original text, so the presence of a language file does not guarantee complete translation of every third-party or plugin screen.

Example:

```php
return [
    'Панель управления' => 'Panou de administrare',
    'Страницы' => 'Pagini',
];
```

## Important boundary

Frontend language settings do not change the admin UI language.

Admin UI language settings do not create public language branches and do not affect `/ru/`, `/ro/`, sitemap, menus or content folders.

## Settings navigation in 0.4.2

The existing /admin/settings/languages route is labelled Настройки фронтенда and contains Languages and Frontend favicon. The admin favicon is under /admin/settings/admin. Both favicon values retain site.php runtime keys; moving the controls does not reset them. My profile is linked from the topbar user name and the user list, and is absent from the Settings sidebar.
