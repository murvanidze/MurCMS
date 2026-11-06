# BerryMur 1.0.0

Лёгкий плагин отложенного подключения локальной Berry CSS в MurCMS.

BerryMur:

- не изменяет ядро и frontend-шаблон MurCMS;
- использует штатные hooks `layout.head` и `layout.body_end`;
- подключает CSS только на страницах, где присутствует `.mur-berry`;
- начинает загрузку после события `window.load` и в период простоя браузера;
- использует только локальные ассеты;
- не использует cookies, Local Storage, внешние запросы, `eval` или inline JavaScript;
- сохраняет версионирование ассетов через `mur_asset_url()`;
- содержит `noscript`-резерв для посетителей с отключённым JavaScript.

## Состав

```text
root/
└── murcms/
    ├── plugins/
    │   └── berrymur/
    │       └── plugin.php
    └── assets/
        └── plugins/
            └── berrymur/
                ├── berry.scoped.css
                └── berrymur-loader.js
```

## Установка

1. Скопируйте содержимое каталога `root/` в корень сайта, сохраняя структуру.
2. Включите плагин `berrymur` через управление плагинами MurCMS.
3. Если включение выполняется вручную, добавьте `berrymur` в массив `enabled`
   файла `data/config/plugins.php`.

Пример:

```php
return [
    'enabled' => [
        'snippets',
        'blocks',
        'gallery',
        'tags',
        'redirects',
        'murconsent',
        'murtext',
        'berrymur',
    ],
];
```

Не заменяйте рабочий `data/config/plugins.php` файлом из другого сайта.
BerryMur намеренно не включает конфигурационные файлы.

## Использование

Размещайте Berry-разметку внутри явно ограниченной зоны:

```html
<section class="mur-berry">
    <div class="flex gap20">
        ...
    </div>
</section>
```

Не добавляйте `.mur-berry` на `html`, `body`, глобальный `main`, шапку или
первый экран. Можно использовать несколько независимых Berry-зон.

## Логика загрузки

1. Плагин добавляет локальный `berrymur-loader.js` перед `</body>`.
2. Loader проверяет наличие `.mur-berry`.
3. Если Berry-зон нет, CSS не запрашивается.
4. Если зона есть, loader ждёт `window.load`.
5. Затем CSS загружается через `requestIdleCallback`; для браузеров без него
   используется короткий `setTimeout`.
6. Ссылка на CSS вставляется перед штатными stylesheet, чтобы `app.css`, тема
   и пользовательский CSS MurCMS оставались позже в порядке каскада.

## Обновление Berry

Создайте новый `berry.scoped.css` инструментом Berry Scope Tool и замените:

```text
root/murcms/assets/plugins/berrymur/berry.scoped.css
```

URL изменится автоматически благодаря `filemtime`-версии `mur_asset_url()`.

## Быстрая проверка

- На странице без `.mur-berry` в Network не должно быть запроса
  `berry.scoped.css`.
- На странице с `.mur-berry` запрос должен начаться после основной загрузки.
- В DOM должна появиться ссылка с `id="murcms-berrymur-css"`.
- Первый экран не должен визуально изменяться после загрузки Berry.
- При отключённом JavaScript CSS подключается через `<noscript>`.

## Удаление

1. Отключите `berrymur` в списке enabled plugins.
2. Удалите каталоги:

```text
murcms/plugins/berrymur/
murcms/assets/plugins/berrymur/
```

Плагин не создаёт данные, таблицы, кеши или пользовательскую конфигурацию.
