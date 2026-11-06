# Banerina plugin for MurCMS

Дата: 2026-11-07  
Статус: current для Banerina `0.1.1` / MurCMS `0.4.2`

## Назначение

Banerina выполняет управляемый выбор и ротацию существующих MurCMS snippets в
местах, которые редактор задаёт вручную. Плагин не хранит HTML/изображения/CTA
и не создаёт второй renderer: содержимое остаётся в `Snippets`.

Runtime files:

```text
root/murcms/plugins/banerina/
root/murcms/assets/plugins/banerina/admin.css
root/murcms/assets/plugins/banerina/admin.js
```

Конфигурация:

```text
root/data/config/banerina.php       # mutable site config
root/data/config/banerina.dist.php  # distribution default
```

Banerina распространяется отдельным `Banerina-0.1.1-update.zip` и не входит в стандартные CMS root/update packages. После установки включите её через менеджер плагинов; Snippets должен быть включён. На существующем сайте update-safe overlay не должен перезаписывать
рабочий `data/config/plugins.php`, поэтому Banerina нужно включить через admin
Plugins или добавить `banerina` в рабочий список `enabled` вручную.

## Shortcode

Поддерживаются три формы:

```text
[promo=pomana-01]
[promo=pomana-01,pomana-02,pomana-04]
[promo=pomana]
```

Пробелы около запятых игнорируются.

Если передано несколько значений, это явный список snippet IDs. Если значение
одно, Banerina сначала ищет одноимённую группу, а при её отсутствии трактует
значение как конкретный snippet ID.

Групповые ID допускают буквы, цифры, `_`, `-` и `.`, например
`ritus.transport`. Snippet IDs следуют действующему контракту Snippets:
`A-Z a-z 0-9 _ -`.

## Selection и no-repeat

Текущий `selection_mode` — только `random`, с равной вероятностью для каждого
уникального кандидата.

При `avoid_duplicates=true` успешно использованный snippet запоминается только
в памяти текущего request. Для следующего Banerina вызова он исключается, пока
остаётся другая отрендериваемая альтернатива. Если альтернативы исчерпаны или
не могут быть отрендерены, повтор снова разрешается.

Отсутствующие/draft/неотрендерированные candidates не ломают страницу: Banerina
пытается использовать другую допустимую альтернативу. Если ни один candidate
не даёт результата, публичный output — пустая строка.

Inactive group всегда выводит пустую строку.

## Snippets integration

Banerina использует только публичные bundled hooks Snippets:

```text
snippets.render
snippets.registry
```

`snippets.render` сохраняет штатные lookup, language fallback, publication
status, PHP policy и MurText behavior. `snippets.registry` используется admin UI
и диагностикой. Полный developer contract принадлежит
[`../development/plugin-api.md`](../development/plugin-api.md).

Если Snippets API недоступен, Banerina деградирует в пустой публичный output и
показывает admin diagnostic; собственный filesystem lookup Banerina не выполняет.

## Public wrapper

Успешный Banerina render оборачивается техническим контейнером:

```html
<div class="mur-promo"
     data-promo-id="pomana-03"
     data-promo-mode="group"
     data-promo-group="pomana">
  ...штатный render snippet...
</div>
```

Для direct/list `data-promo-group` отсутствует. Wrapper не содержит встроенного
tracking и не меняет ссылки. Его задача — стабильная техническая идентификация
для будущей analytics integration.

## Admin UI

Route:

```text
/admin/plugin/banerina
```

Минимальная роль: `admin`. Core MurCMS проверяет route role до вызова handler;
все POST operations дополнительно требуют CSRF token.

Страница содержит:

- summary: groups, unique Banerina snippets, active groups, problems;
- список групп, поиск, create/edit, enable/disable и подтверждаемое delete;
- picker существующих snippets с поиском;
- preview случайного элемента группы в default content language;
- settings `Avoid duplicates on page`, `Selection mode: Random`,
  `Diagnostics logging`;
- diagnostics для empty group, missing snippet, invalid group ID, duplicate
  snippet и недоступного Snippets registry.

Admin strings загружаются из plugin-local `languages/`; bundled translations:
`ru`, `ro`, `en`, `uk`.

## Storage safety

Banerina config записывается через temporary file + atomic rename; после замены PHP OPcache, если он доступен, явно инвалидируется. Перед заменой
существующего `data/config/banerina.php` создаётся backup:

```text
data/storage/backups/banerina/
```

Хранятся последние пять backup-файлов.

Если рабочий `banerina.php` отсутствует, нечитаем или повреждён синтаксически,
плагин не должен обрушать публичную страницу: используется безопасная
нормализованная конфигурация по умолчанию. В admin diagnostics при этом
показывается проблема загрузки runtime config; при следующем успешном
сохранении старый файл сначала попадает в backup.

При включённом `Diagnostics logging` runtime errors пишутся в:

```text
data/storage/logs/banerina.log
```

Plugin API MurCMS не предоставляет отдельный logger service, поэтому Banerina
использует стандартную runtime storage area и не пишет данные внутрь plugin
code tree.

## Интерфейс 0.1.1

Таблица групп использует всю ширину контейнера и общий горизонтальный scroll
на mobile. Действия и статусы представлены доступными SVG icons с title и
aria-label. В Snippets показываются жирное количество и все имена, включая 10+
элементов, без усечения. Runtime выбор групп и Snippets API не изменялись.

## Что не реализовано в 0.1.1

Нет weights, schedules, persistent rotation, cookies/session state, automatic
placement, geo/intent/profile logic, impression/click storage, UTM generation,
GA API integration, A/B testing, popup/interstitial/sticky behavior.

Архитектура config оставляет возможность добавить такие поля позднее без
переноса контента из Snippets.
