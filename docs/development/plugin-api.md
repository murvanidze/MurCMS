# Plugin API MurCMS

**Статус:** канонический developer reference  
**Применимо к MurCMS:** `0.4.2`
**Source of truth версии CMS:** `murcms/app/version.php` → `MUR_CMS_VERSION`

Этот документ описывает фактический публичный контракт Plugin API текущей MurCMS. Он предназначен для разработчиков штатных и сторонних плагинов и для coding agents.

Plugin API — не отдельный модуль или формат документации. Это программный контракт между core MurCMS и плагином: механизм загрузки `plugin.php`, `PluginContext`, hooks, административные menu/routes, общие helpers и правила размещения runtime-данных.

> **Security:** `admin.menu.role` управляет только видимостью пункта меню. `admin.route.role` управляет серверной авторизацией административного route. Core MurCMS проверяет `admin.route.role` **до вызова handler плагина**.

---

## 1. Границы ответственности

Через Plugin API плагин может:

- подписываться на frontend/content/admin hooks;
- изменять или дополнять HTML в предусмотренных точках;
- регистрировать виртуальные публичные маршруты;
- дополнять sitemap;
- добавлять поля и инструменты в редакторы;
- добавлять пункт административного меню;
- регистрировать защищённый admin route;
- регистрировать file-backed collection для общего файлового UI;
- подключать CSS/JS через публичный asset path;
- читать и сохранять update-safe runtime-конфигурацию и данные вне каталога кода плагина.

Core отвечает за:

- загрузку только включённых плагинов;
- порядок выполнения hooks;
- основную маршрутизацию и рендеринг;
- аутентификацию административного пользователя;
- централизованную проверку минимальной роли структурированного admin route;
- общий admin layout;
- общие file-collection actions после регистрации collection.

Плагин отвечает за:

- корректность callback-ов и возвращаемых типов;
- валидацию своих входных данных;
- CSRF-проверку собственных mutating admin actions;
- безопасное экранирование output;
- безопасную запись своей конфигурации/данных;
- собственные предметные ограничения поверх базовой role authorization;
- обработку ожидаемых ошибок внутри плагина.

---

## 2. Loading model и lifecycle

### 2.1. Где расположен плагин

Runtime entry point:

```text
murcms/plugins/<plugin-id>/plugin.php
```

Как и bundled plugins, runtime PHP-файлы должны отвергать прямой запуск вне bootstrap MurCMS через проверку `MUR_CMS_BOOTSTRAPPED`.

`<plugin-id>` допускает только:

```text
^[a-z0-9][a-z0-9_-]*$
```

Идентификатор с другим форматом `PluginManager` не загружает.

### 2.2. Как включается плагин

Список включённых плагинов читается из:

```text
data/config/plugins.php
```

Формат:

```php
return [
    'enabled' => [
        'example',
        'another-plugin',
    ],
];
```

Плагины загружаются последовательно в порядке массива `enabled` на каждом запросе после создания `Hooks`, `ContentRepository`, `SeoManager` и `PluginContext`, но до создания frontend `Renderer`/`Router` и до обработки admin route.

Порядок `enabled` может влиять на hooks с одинаковым priority, поэтому плагин не должен без необходимости зависеть от конкретной позиции в списке.

### 2.3. Допустимый entry-point contract

`plugin.php` должен вернуть один из двух вариантов.

Рекомендуемый:

```php
return static function (PluginContext $context): void {
    // registration
};
```

Совместимый вариант:

```php
return [
    'register' => static function (PluginContext $context): void {
        // registration
    },
];
```

Если файл отсутствует, plugin ID невалиден или возвращаемое значение не соответствует одному из этих контрактов, plugin не регистрируется.

Регистрация выполняется сразу во время загрузки `plugin.php`. Отдельных `install()`, `activate()`, `deactivate()` или manifest lifecycle callbacks в текущем Plugin API нет.

---

## 3. `PluginContext`

Plugin entry point получает `Mur\Core\PluginContext`.

Доступные свойства:

| Свойство | Тип | Назначение |
|---|---|---|
| `hooks` | `Mur\Core\Hooks` | регистрация filter/render hooks |
| `site` | `array` | загруженная конфигурация сайта |
| `languages` | `array` | загруженная конфигурация языков |
| `basePath` | `string` | абсолютный путь к `data/` |
| `pluginsPath` | `string` | абсолютный путь к `murcms/plugins/` |
| `content` | `Mur\Core\ContentRepository` | read API текущего content repository |

Методы:

```php
$context->path('config/example.php');
```

возвращает путь внутри `data/`:

```text
data/config/example.php
```

Регистрация admin route выполняется через:

```php
$context->registerAdminRoute([...]);
```

Для admin routes использовать этот helper, а не прямой вызов `Hooks::register()`.

---

## 4. Минимальная структура плагина

Plugin API требует только `plugin.php`. Остальная структура определяется задачей плагина.

Рекомендуемая production-структура:

```text
murcms/plugins/example/
  plugin.php                  # обязательный runtime entry point
  defaults.php                # optional immutable defaults
  src/                        # optional PHP classes

murcms/assets/plugins/example/
  admin.css                   # optional public admin asset
  frontend.css                # optional public frontend asset
  frontend.js                 # optional public frontend asset

data/config/
  example.dist.php            # optional distributable defaults/example
  example.php                 # mutable site runtime config; не часть update overlay

data/storage/
  backups/example/            # optional runtime backups
  cache/example/              # optional cache
  generated/example/          # optional generated data
  logs/                       # optional logs
```

`docs/` и `tests/` могут входить в source/dev package, но не требуются runtime API.

Immutable defaults допустимо хранить либо в `data/config/<plugin>.dist.php`, либо внутри plugin code, например `murcms/plugins/<plugin>/defaults.php`. Mutable site configuration всегда должна жить в runtime data area, а не внутри `murcms/plugins/<plugin>/`.

---

## 5. Hooks: общий контракт

### 5.1. Регистрация

```php
$context->hooks->add(
    'hook.name',
    static function (mixed $value, array $hookContext, Hooks $hooks): mixed {
        return $value;
    },
    10,
);
```

`priority` — целое число, default `10`. Меньшее значение выполняется раньше. При одинаковом priority сохраняется порядок регистрации.

`Hooks::filter()` последовательно передаёт результат предыдущего callback следующему:

```text
initial value → callback 1 → callback 2 → ... → final value
```

`Hooks::render()` является строковым вариантом filter и начинает цепочку со значения `''`.

Callback фактически вызывается с тремя аргументами:

```php
($value, $context, $hooks)
```

Публичные bundled plugins часто объявляют только необходимые первые один-два аргумента. Для нового кода рекомендуется объявлять столько аргументов, сколько callback действительно использует.

### 5.2. Правило типов

Hook должен возвращать тип, ожидаемый конкретной точкой расширения. Core не выполняет универсальную нормализацию результата каждого plugin callback.

Render/filter hook не должен возвращать `null`, если следующий обработчик ожидает строку, кроме hooks, где `null` специально означает «не обработано» (`content.render_body`, `listing.items`, `route.public`, `route.not_found`, legacy `admin.route`).

### 5.3. Side effects

Для обычных render hooks предпочтительны чистые преобразования значения. Изменение HTTP status/headers допустимо прежде всего в route hooks, где плагин сознательно принимает обработку запроса. Запись данных должна выполняться в явно обрабатываемых admin actions, а не в layout/content render hooks.

---

## 6. Frontend layout hooks

Все перечисленные hooks получают общий layout context:

```php
[
    'language' => string,
    'document' => ?Mur\Core\Document,
    'meta' => array,
    'current_path' => string,
    'template' => string,
    'template_options' => array,
    'canonical' => string,
]
```

| Hook | Начальное/текущее значение | Момент вызова | Возврат |
|---|---|---|---|
| `layout.head` | `string` | внутри `<head>` после базовых/template styles и до site custom CSS | HTML для `<head>` |
| `layout.body_start` | `string` | сразу после открытия `<body>` | HTML |
| `layout.header_before` | `string` | перед `<header class="site-header">` | HTML |
| `layout.header_after` | `string` | после site header | HTML |
| `layout.main_before` | `string` | перед `<main>` | HTML |
| `layout.main_after` | `string` | после `</main>` | HTML |
| `layout.content_start` | `string` | внутри `<main>` перед body страницы | HTML |
| `layout.content_end` | `string` | внутри `<main>` после body страницы | HTML |
| `layout.body_end` | `string` | перед закрывающим `</body>` | HTML |
| `layout.footer` | собранный `string` футера | после сборки footer blocks/menu, до вставки в `<footer>` | изменённый HTML футера |

Пример условного frontend asset:

```php
$context->hooks->add('layout.head', static function (string $html): string {
    return $html
        . '<link rel="stylesheet" href="'
        . mur_escape(mur_asset_url('plugins/example/frontend.css'))
        . '">';
});
```

---

## 7. Content hooks

### `content.home`

**Когда:** после рендера home blocks, перед layout.  
**Value:** готовый HTML главной страницы (`string`).  
**Context:** `['language' => string]`.  
**Возврат:** изменённый HTML (`string`).

### `content.article_start`

**Когда:** при рендере документа, перед его основным body/listing внутри document frame.  
**Value:** начинается с `''`.  
**Context:** `language`, `document`.  
**Возврат:** HTML-вставка (`string`).

### `content.article_end`

**Когда:** при рендере документа после основного body/listing внутри document frame.  
**Value:** начинается с `''`.  
**Context:** `language`, `document`.  
**Возврат:** HTML-вставка (`string`).

### `content.document`

**Когда:** после формирования document frame, перед layout.  
**Value:** готовый HTML документа (`string`).  
**Context:** `language`, `document`.  
**Возврат:** изменённый HTML (`string`).

### `content.render_body`

**Когда:** в `mur_render_body()` до штатного body renderer.  
**Initial value:** `null`.  
**Context:** исходный текст и информация о surface.

Типичный context:

```php
[
    'source' => string,
    'language' => string,
    'document' => Mur\Core\Document,
    'collection' => string,
    'surface' => 'document'|'home'|'footer'|'snippet',
]
```

Если финальный результат цепочки — `string`, он полностью заменяет штатный body renderer. Если финальный результат не строка, MurCMS продолжает стандартный `mur_render_body()`.

Пример:

```php
$context->hooks->add('content.render_body', static function (mixed $html, array $ctx): mixed {
    if (($ctx['surface'] ?? '') !== 'document') {
        return $html;
    }

    $source = (string) ($ctx['source'] ?? '');
    return '<div class="example-body">' . mur_escape($source) . '</div>';
});
```

### Snippets service hooks

Bundled plugin `snippets` публикует два service hook для других plugins. Они
доступны только когда `snippets` включён; consumer обязан проверить
`Hooks::has()` и безопасно деградировать, если provider отсутствует.

#### `snippets.render`

**Назначение:** отрендерить один snippet через штатный Snippets pipeline без
повторного filesystem lookup в consumer plugin.

**Initial value:** `''`.

**Context:** `id` — snippet ID, `language` — двухбуквенный код языка. Недопустимое значение языка безопасно заменяется основным языком.

**Возврат:** готовый HTML (`string`) либо пустая строка.

Допустимый `id` соответствует `^[A-Za-z0-9][A-Za-z0-9_-]*$`. Provider сохраняет
обычный порядок поиска language → global → default language, правило draft,
`allow_php` и обработку body через `mur_render_body()`, включая MurText. Пустой
результат означает отсутствующий, draft, запрещённый или неотрендерированный
snippet; consumer не должен обходить этот результат прямым чтением файла.

```php
$html = $context->hooks->filter('snippets.render', '', [
    'id' => 'cta',
    'language' => 'ru',
]);
```

#### `snippets.registry`

**Назначение:** получить безопасный inventory существующих snippet IDs для
admin picker и диагностики.

**Initial value:** `[]`.

**Context:** пустой массив.

**Возврат:** список records `id`, `scopes`, `formats`.

`scopes` содержит `global` и/или двухбуквенные language directories;
`formats` — `mur.md` и/или `html`. Registry сообщает наличие файлов, но не
обещает, что конкретный ID будет отрендерирован для выбранного языка: status,
fallback, PHP policy и body renderer проверяются только через
`snippets.render`.

```php
$registry = $context->hooks->filter('snippets.registry', [], []);
```

---

## 8. Listing hook

### `listing.items`

**Когда:** после выбора, сортировки, limit/pagination списка документа, до штатного rendering списка.  
**Initial value:** `null`.

Context:

```php
[
    'language' => string,
    'document' => Mur\Core\Document,
    'items' => array,
    'pagination' => ?array,
]
```

Если callback chain возвращает `string`, этот HTML заменяет штатный список; core затем добавляет штатный pagination HTML. Нестрочный финальный результат оставляет стандартный renderer списка.

---

## 9. Public route hooks

### `route.public`

**Когда:** для непустого content path до поиска обычного документа по URL. Корень `/` и языковая home-страница `/<lang>/` обрабатываются core отдельно. Hook также вызывается для пути без включённого языкового префикса с fallback на default language.  
**Initial value:** `null`.

Context:

```php
[
    'language' => string,
    'segments' => array,
    'path' => string,
    'renderer' => Mur\Core\Renderer,
]
```

Строковый результат считается полным HTML-ответом для этого route и выводится core. `null`/нестрочный результат означает «не обработано» и передаёт запрос дальше.

### `route.not_found`

**Когда:** после того как `route.public` не обработал запрос и обычный опубликованный документ не найден.  
**Initial value и context:** как у `route.public`.  
**Возврат:** строковый полный HTML-ответ или нестрочное значение для продолжения штатного 404.

Route plugin может устанавливать HTTP status/headers или выполнять redirect, если это является его назначением. Он обязан валидировать любой пользовательский input, влияющий на target/header.

---

## 10. SEO hook

### `seo.sitemap_urls`

**Когда:** после формирования core URL списка sitemap и до дедупликации по `path`.  
**Value:** массив URL records.  
**Context:** `site`, `languages`.

Record:

```php
[
    'path' => '/ru/example',
    'lastmod' => '2026-09-07', // допустима также пустая строка
]
```

Callback должен вернуть массив. Плагин не должен добавлять пустой `path` или рассчитывать на дубликаты: core удаляет повторяющиеся paths.

---

## 11. Admin editor hooks

### `admin.document_fields`

**Назначение:** дополнительные GUI-поля в редакторе страницы/категории.  
**Value:** HTML string, начинается с `''` через `render()`.  
**Context:**

```php
[
    'collection' => string,
    'language' => string,
    'document' => ?Mur\Core\Document,
    'meta' => array,
    'help' => bool,
]
```

Возвращайте исходный `$html` плюс собственную разметку.

### `admin.document_meta`

**Назначение:** изменить meta непосредственно перед записью документа.  
**Value:** `array $meta`.  
**Context:** `collection`, `id`, `language`, `post`.

Callback должен вернуть массив meta. Если финальный результат не массив, core заменит его на пустой массив.

### `admin.known_meta_keys`

**Назначение:** объявить plugin meta keys известными GUI, чтобы они не дублировались в поле Extra meta.  
**Value:** массив строк.  
**Context:** пустой массив.

### `admin.editor_tools`

**Назначение:** HTML непосредственно перед основным `textarea`.  
**Value:** HTML string, начинается с `''`.

Общие keys:

```php
'editor'   // document | file | snippet
'textarea' // имя textarea target
```

Для `editor=document` дополнительно: `collection`, `language`, `document`, `meta`.  
Для `editor=file`: `scope`, `file`.  
Для `editor=snippet`: `scope`, `name`, `type`.

Plugin admin assets, используемые только редактором, целесообразно подключать лениво через этот hook.

---

## 12. Admin menu API

Пункт меню регистрируется обычным filter hook. Context этого hook содержит текущего admin user в key `user`; итоговая role-фильтрация menu items всё равно выполняется core после callback chain.

```php
$context->hooks->add('admin.menu', static function (array $items): array {
    $items[] = [
        'id' => 'plugin-example',
        'label' => 'Example',
        'url' => '/admin/plugin/example',
        'role' => 'admin',
    ];

    return $items;
});
```

Поддерживаемые поля menu item:

| Поле | Тип | Обязательность | Default | Назначение |
|---|---|---:|---|---|
| `id` | `string` | рекомендуется | `''` | active item/breadcrumb identity; для `/admin/plugin/example` используйте `plugin-example` |
| `label` | `string` | рекомендуется | `id` | отображаемое название |
| `url` | `string` | рекомендуется | `#` | URL пункта |
| `role` | `string` | нет | `editor` | минимальная роль только для **видимости** menu item |
| `target` | `string` | нет | `''` | `_blank` открывает ссылку в новой вкладке; другие значения специальных эффектов не имеют |

Отдельного `order` поля current core не обрабатывает. Пункты появляются в порядке, полученном после цепочки `admin.menu` callbacks.

> **Security:** `admin.menu.role` не защищает URL. Скрытый menu item нельзя использовать как механизм авторизации.

---

## 13. Admin routes API

### 13.1. Регистрация

Рекомендуемый API:

```php
$context->registerAdminRoute([
    'path' => '/admin/plugin/example',
    'role' => 'admin',
    'handler' => static function (mixed $value, array $adminContext): string {
        return '<section class="admin-panel"><h2>Example</h2></section>';
    },
]);
```

Фактически поддерживаемые поля:

| Поле | Тип | Обязательность | Default | Назначение |
|---|---|---:|---|---|
| `path` | `string` | да | — | admin path; должен нормализоваться в `plugin/...` |
| `role` | `string` | для нового кода — да | `editor` | минимальная server-side роль route |
| `handler` | `callable` | да | — | обработчик admin page |

Канонический path нового плагина:

```text
/admin/plugin/<plugin-id>
```

Поле `methods` в текущем Plugin API **не существует**. Core передаёт фактический HTTP method в handler context, а plugin сам различает `GET`, `POST` и другие методы, если это необходимо.

### 13.2. Handler context

Structured route handler вызывается с current value `null`, context и объектом `Hooks`. Обычно достаточно первых двух аргументов:

```php
static function (mixed $value, array $adminContext): string
```

`$adminContext` содержит:

| Key | Тип | Значение |
|---|---|---|
| `route` | `string` | нормализованный route, например `plugin/example` |
| `method` | `string` | HTTP method в uppercase, например `GET`/`POST` |
| `user` | `array` | текущий authenticated admin user; core добавляет `csrf` |
| `render_file_collection` | `callable` | общий UI зарегистрированной file collection |

Handler должен вернуть непустую строку. Нестрочное или пустое значение приводит к штатной admin 404 «Плагин не обработал этот admin route».

Core оборачивает результат handler в общий admin layout.

### 13.3. Authorization contract

Для structured admin route действует следующий обязательный контракт:

1. `admin.route.role` задаёт минимальную роль доступа.
2. Core вычисляет требуемую роль до dispatch handler.
3. Core вызывает `AdminAuth::can()` до `pluginRoute()` и до plugin handler.
4. При недостаточной роли handler не вызывается; пользователь получает 403 admin page.
5. Проверка не зависит от наличия или видимости `admin.menu`.
6. Прямой переход по URL не обходит проверку.
7. Проверка применяется до handler независимо от HTTP method, поэтому распространяется и на `GET`, и на `POST`, и на другие методы.
8. Плагин может повторить или усилить проверку как defense in depth, но такая проверка не должна ослаблять core authorization.

Коротко:

```text
admin.menu.role  → UI visibility
admin.route.role → server authorization
```

Текущая иерархия ролей:

```text
editor < admin < super_admin
```

Legacy role `content` нормализуется в `editor`. Полное описание пользователей и ролей: [`../admin/users.md`](../admin/users.md).

### 13.4. HTTP methods

Так как route definition не содержит `methods`, plugin обязан сам решать, какие методы принимает:

```php
$method = (string) ($adminContext['method'] ?? 'GET');

if ($method === 'POST') {
    // CSRF + validation + write
}
```

Если plugin должен отвергать неподдерживаемый метод, он делает это внутри handler и при необходимости устанавливает соответствующий HTTP status.

---

## 14. Backward compatibility / legacy admin route

До structured route registration сторонние плагины могли обрабатывать:

```text
admin.route
```

как обычный filter hook.

Current core сохраняет этот путь совместимости: если structured definition для `plugin/...` не найден, `pluginRoute()` вызывает legacy `admin.route` filter.

Для такого route текущий fallback authorization — `editor`.

Это legacy compatibility behavior, а не рекомендуемый API. Новые плагины должны:

- использовать `PluginContext::registerAdminRoute()`;
- всегда задавать `role` явно;
- не полагаться на fallback `editor`.

В текущем коде MurCMS 0.4.2 не задана дата удаления legacy filter и не объявлен автоматический переход на более строгий fallback. Поэтому документация не устанавливает несуществующий deprecation schedule.

Все bundled plugin admin routes должны иметь explicit `role`.

---

## 15. Admin file collections

`admin.file_collections` позволяет плагину зарегистрировать файловую область и использовать общий UI MurCMS для list/edit/download/delete/upload/replace.

Регистрация:

```php
$context->hooks->add('admin.file_collections', static function (array $collections): array {
    $collections['example'] = [
        'label' => 'Example files',
        'menu_id' => 'plugin-example',
        'route' => '/admin/plugin/example',
        'role' => 'editor',
        'languages' => true,
        'allow_global' => false,
        'recursive' => false,
        'targets' => [
            'default' => [
                'label' => 'Example',
                'directory' => 'example/{lang}',
            ],
        ],
        'hint' => 'Optional editor syntax hint.',
    ];

    return $collections;
});
```

Collection fields:

| Поле | Назначение |
|---|---|
| `label` | заголовок общей файловой страницы |
| `menu_id` | active admin menu id |
| `route` | URL возврата после file action |
| `role` | минимальная роль для file-collection actions |
| `languages` | использовать language scopes |
| `allow_global` | разрешить global scope через `global_directory` |
| `recursive` | разрешить вложенные пути внутри target |
| `targets` | map target definitions |
| `hint` | optional HTML hint в file editor |

Target definition:

| Поле | Назначение |
|---|---|
| `label` | название target |
| `directory` | каталог внутри `data/`; `{lang}` заменяется выбранным языком |
| `global_directory` | optional global каталог при `allow_global=true` |

Core проверяет зарегистрированную collection и её `role` для общих file actions. После отключения plugin definition отсутствует, и его scope больше не доступен через этот общий admin UI.

Admin route может вывести collection UI через callable из handler context:

```php
$render = $adminContext['render_file_collection'] ?? null;
return is_callable($render) ? $render('example') : '';
```

Не используйте file-collection definition для произвольного доступа за пределами `data/`.

---

## 16. Configuration: update-safe contract

Главное правило:

> Mutable runtime configuration не должна храниться внутри `murcms/plugins/<plugin>/`.

Рекомендуемые варианты defaults:

```text
data/config/example.dist.php
```

или immutable plugin-local defaults:

```text
murcms/plugins/example/defaults.php
```

Рабочая site-specific конфигурация:

```text
data/config/example.php
```

Пример чтения:

```php
$configPath = $context->path('config/example.php');
$distPath = $context->path('config/example.dist.php');

$config = is_file($configPath)
    ? require $configPath
    : (is_file($distPath) ? require $distPath : []);

$config = is_array($config) ? $config : [];
```

`PluginContext::path()` указывает на `data/`, но Plugin API не предоставляет универсальный config merge/write service. Плагин сам отвечает за:

- merge defaults/runtime;
- schema normalization;
- миграцию legacy config;
- atomic write;
- backup перед перезаписью, если потеря конфигурации существенна.

Production update кода/asset-ов не должен перезаписывать `data/config/<plugin>.php`.

Каталог `murcms/plugins/<plugin>/` следует считать read-only runtime code. Плагин не должен требовать write permission к нему без объективно необходимого и отдельно документированного исключения.

Bundled reference implementations:

- Redirects: `data/config/redirects.php` + `redirects.dist.php`;
- MurConsent: runtime `data/config/murconsent.php` + immutable plugin defaults;
- MurText: runtime `data/config/murtext.php` + immutable plugin defaults; старый plugin-local `config.php` используется только как legacy migration source.

---

## 17. Assets

Публичные plugin assets должны находиться в:

```text
murcms/assets/plugins/<plugin-id>/
```

Канонический URL создаётся helper-ом:

```php
mur_asset_url('plugins/example/admin.css')
```

Он формирует URL вида:

```text
/murcms/assets/plugins/example/admin.css?v=<mtime>
```

и добавляет cache-busting по `filemtime`.

Пример admin CSS:

```php
$asset = '<link rel="stylesheet" href="'
    . mur_escape(mur_asset_url('plugins/example/admin.css'))
    . '">';
```

Пример frontend JS:

```php
$context->hooks->add('layout.body_end', static function (string $html): string {
    return $html
        . '<script src="'
        . mur_escape(mur_asset_url('plugins/example/frontend.js'))
        . '" defer></script>';
});
```

Frontend assets желательно подключать условно, только когда функциональность реально используется.

Plugin-local source assets допустимы в source/dev tree только при наличии явного build/sync process. Не поддерживайте вручную две равноправные runtime-копии одного CSS/JS: production runtime должен иметь один канонический asset path.

`mur_asset_url()` не публикует файлы из `murcms/plugins/<plugin>/assets/`.

---

## 18. Data, storage и writable files

Используйте общую runtime model MurCMS:

| Данные | Рекомендуемый путь |
|---|---|
| mutable config | `data/config/<plugin>.php` |
| distributable config example | `data/config/<plugin>.dist.php` |
| backups | `data/storage/backups/<plugin>/` |
| cache | `data/storage/cache/<plugin>/` |
| generated data | `data/storage/generated/<plugin>/` |
| logs | `data/storage/logs/` или plugin-specific child path |
| public/user uploads | `uploads/` только если данные действительно должны быть web-accessible |

Для путей доступны helpers:

```php
$context->path('storage/cache/example');
mur_data_path('storage/cache/example');
mur_uploads_path('example');
```

Не помещайте runtime data в `murcms/app/`, `murcms/plugins/`, `murcms/assets/` или `murcms/templates/`.

Общий storage/layout MurCMS описан в [`../current-reference.md`](../current-reference.md).

---

## 19. Security requirements

### 19.1. Authorization

- Никогда не считайте скрытый URL защитой.
- Никогда не используйте `admin.menu.role` вместо server authorization.
- Для нового admin route всегда задавайте explicit `admin.route.role`.
- Не ослабляйте роль внутри handler относительно role, объявленной route definition.

### 19.2. CSRF

Core проверяет роль structured plugin route автоматически, но **не выполняет CSRF verification за plugin handler**.

Для mutating request plugin должен:

1. поместить session token из `adminContext['user']['csrf']` в hidden `_csrf` field;
2. на `POST` проверить submitted token;
3. не выполнять запись при несовпадении.

Bundled plugins используют constant-time comparison:

```php
$user = is_array($adminContext['user'] ?? null) ? $adminContext['user'] : [];
$csrf = (string) ($user['csrf'] ?? '');
$submitted = (string) ($_POST['_csrf'] ?? '');

if ($csrf === '' || $submitted === '' || !hash_equals($csrf, $submitted)) {
    return '<div class="admin-notice admin-notice--error">Session expired.</div>';
}
```

### 19.3. Input/output

- Валидируйте и нормализуйте `$_GET`, `$_POST`, uploaded data и route segments.
- Для HTML text/attributes используйте `mur_escape()` согласно контексту.
- Для filesystem paths применяйте allowlist/safe path logic; не доверяйте пользовательскому relative path.
- Не сохраняйте user-controlled executable PHP как обычный plugin config. Такой режим допустим только как отдельная явно документированная capability с осознанным opt-in и соответствующими ограничениями.
- Plugin directory не должен быть writable для обычной работы.

---

## 20. Error handling

Текущий Plugin API не определяет отдельный plugin exception hierarchy и не предоставляет plugin logger service через `PluginContext`.

Практический контракт:

- ожидаемые ошибки admin handler обрабатывайте внутри plugin и возвращайте безопасный admin notice/page;
- не выводите raw exception messages, если они могут содержать пути, secrets или пользовательский input;
- необработанный exception из hook или route handler не нормализуется Plugin API и может прервать request;
- structured admin handler должен вернуть непустую строку; иначе core покажет 404 plugin page;
- route hook, принимающий полный request, сам отвечает за корректный HTTP status, headers и body;
- логирование, если оно требуется plugin, размещайте в runtime storage, а не в plugin code tree.

Для критичных записей используйте temp file + atomic rename там, где это возможно, и backup перед потенциально разрушительной перезаписью.

---

## 21. Versioning и compatibility

Каноническая версия MurCMS определяется core:

```php
MUR_CMS_VERSION
```

Значение загружается из:

```text
murcms/app/version.php
```

Не создавайте параллельный «номер версии MurCMS» внутри плагина.

В текущем Plugin API нет обязательного plugin manifest и нет стандартизированного поля:

```text
plugin version
requires MurCMS
compatible MurCMS
```

Поэтому plugin version и минимально совместимая версия MurCMS пока являются ответственностью самого plugin package/documentation. Bundled plugins используют собственные константы/metadata, но это не единый API contract.

Если plugin содержит отдельную embedded library/parser, различайте как минимум:

```text
integration plugin version
embedded/core library version
format/dialect version, если существует
```

Breaking change Plugin API — изменение, которое требует правок существующего корректного плагина, например:

- изменение допустимого entry-point contract;
- удаление/переименование публичного hook;
- несовместимое изменение hook context или return semantics;
- несовместимое изменение `PluginContext`;
- изменение fields/authorization semantics structured admin route;
- изменение canonical asset/config helper semantics, на которые опирается документированный Plugin API.

---

## 22. Complete minimal example

Пример ниже показывает рекомендуемый путь без legacy API и без write action.

### Файлы

```text
murcms/plugins/example/plugin.php
murcms/assets/plugins/example/admin.css
data/config/example.dist.php
# data/config/example.php — site runtime override, создаётся/редактируется отдельно
```

### `murcms/plugins/example/plugin.php`

```php
<?php
declare(strict_types=1);

if (!defined('MUR_CMS_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('No direct script access allowed.');
}

use Mur\Core\PluginContext;

return static function (PluginContext $context): void {
    $configPath = $context->path('config/example.php');
    $distPath = $context->path('config/example.dist.php');

    $config = is_file($configPath)
        ? require $configPath
        : (is_file($distPath) ? require $distPath : []);
    $config = is_array($config) ? $config : [];

    $context->hooks->add('layout.footer', static function (string $html) use ($config): string {
        if (($config['footer_note'] ?? '') === '') {
            return $html;
        }

        return $html . '<p class="example-note">'
            . mur_escape((string) $config['footer_note'])
            . '</p>';
    });

    $context->hooks->add('admin.menu', static function (array $items): array {
        $items[] = [
            'id' => 'plugin-example',
            'label' => 'Example',
            'url' => '/admin/plugin/example',
            'role' => 'admin',
        ];

        return $items;
    });

    $context->registerAdminRoute([
        'path' => '/admin/plugin/example',
        'role' => 'admin',
        'handler' => static function (mixed $value, array $adminContext): string {
            $method = (string) ($adminContext['method'] ?? 'GET');

            if ($method !== 'GET') {
                http_response_code(405);
                return '<section class="admin-panel"><p>Method not allowed.</p></section>';
            }

            return '<link rel="stylesheet" href="'
                . mur_escape(mur_asset_url('plugins/example/admin.css'))
                . '">'
                . '<section class="admin-panel"><h2>Example</h2>'
                . '<p>Plugin route is protected by core role authorization.</p></section>';
        },
    ]);
};
```

### `data/config/example.dist.php`

```php
<?php
declare(strict_types=1);

if (!defined('MUR_CMS_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('No direct script access allowed.');
}

return [
    'footer_note' => '',
];
```

### `murcms/assets/plugins/example/admin.css`

```css
.example-note {
  font-size: 0.9em;
}
```

Для write-enabled admin page добавьте CSRF verification, validation, update-safe write и backup согласно разделам выше. Не добавляйте `methods` в route definition: current core такого field не обрабатывает.

---

## 23. Anti-patterns

| Неправильно | Правильно |
|---|---|
| Считать скрытый admin menu item защитой route | Задать explicit `role` в `registerAdminRoute()` |
| Новый admin route без `role` | Всегда указывать минимальную роль явно |
| Хранить mutable settings в `murcms/plugins/example/config.php` | Хранить runtime config в `data/config/example.php` |
| Писать cache/logs внутрь plugin directory | Использовать `data/storage/...` |
| Дублировать одинаковый CSS/JS в двух runtime paths | Оставить один канонический файл в `murcms/assets/plugins/...` |
| Публиковать asset из `murcms/plugins/.../assets` вручную | Использовать `murcms/assets/plugins/...` + `mur_asset_url()` |
| Менять core ради plugin-specific integration при наличии hook | Использовать документированный hook/API |
| Использовать legacy `admin.route` filter в новом плагине | Использовать `PluginContext::registerAdminRoute()` |
| Придумывать route field `methods` | Читать `adminContext['method']` внутри handler |
| Выполнять `POST` write только потому, что role уже проверена | Дополнительно проверить CSRF и input |
| Использовать undocumented private/core methods как стабильный API | Опирайтесь только на контракт этого документа |

---

## 24. Reference implementations

При сверке поведения полезны bundled plugins:

- `redirects` — public route hooks, admin menu, structured admin route, update-safe config;
- `murconsent` — frontend layout hooks, conditional admin registration, assets, config migration;
- `murtext` — `content.render_body`, editor hooks, update-safe config migration;
- `tags` — public route, sitemap, document meta/editor hooks, admin route;
- `blocks` и `gallery` — conditional assets, `admin.file_collections`, shared file UI;
- `snippets` — использование layout/content hooks без отдельного admin route.

Bundled implementation не является автоматически нормой, если в нём сохранена дополнительная legacy/defense-in-depth проверка. При конфликте ориентируйтесь на публичный core contract этого документа.

---

## 25. Source-of-truth boundaries

Этот файл является единственным каноническим developer reference по Plugin API.

Связанные темы:

| Тема | Source of truth |
|---|---|
| Plugin API, hooks, admin menu/routes | **этот документ** |
| Пользователи и role hierarchy | [`../admin/users.md`](../admin/users.md) |
| Общая current architecture/storage | [`../current-reference.md`](../current-reference.md) |
| Admin content/file editors | [`../admin/content.md`](../admin/content.md) |
| Конкретный plugin | документация этого plugin |
| Версия/release MurCMS | `murcms/app/version.php` и соответствующий release document |

Другие документы могут перечислять hook names или давать краткий контекст использования, но не должны повторять сигнатуры, route fields, authorization contract, fallback behavior, asset/config contract и полный перечень extension points.

## Plugin inventory metadata (0.4.2)

Неисполняемый info.json в каталоге плагина описывает name, version и факультативные description, author, website, license. Все отображаемые значения — строки и HTML-экранируются. Файл ограничен 64 KB, некорректный JSON даёт пустые metadata; ID всегда определяется каталогом. Не подключайте отключённый plugin.php для получения версии. Пункт управления плагинами существует в начале группы Plugins даже при пустом enabled.
