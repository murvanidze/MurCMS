# MurText для MurCMS

Версия плагина: **0.2.4**
Версия ядра: **0.2.0**  

В 0.2.4 обновлена единая краткая справка и добавлен защищённый read-only маршрут
/admin/plugin/murtext/help с полной документацией, включённой в plugin update.
Snippets и home/footer сохраняют parser/murtext.* перед --- через общий front
matter. Специфичные меню/галереи/счётчики не включаются в MurText автоматически.
Диалект: **murtext-1**  
Целевая платформа: **PHP 8.5+**  
Внешние зависимости: **нет**

Текущий integration layer в core MurCMS имеет версию **0.2.4**. Parser core остаётся **0.2.0**; update-safe config model, введённая в 0.2.1, сохраняется.

MurText — управляемый текстовый парсер для страниц, категорий, блоков главной и футера, а также snippets MurCMS. Плагин содержит ядро парсера, адаптер MurCMS, страницу глобальных настроек и необязательный помощник редактора.

## Update-safe модель, введённая в 0.2.1

Версия 0.2.1 не меняла грамматику MurText, HTML-вывод и публичный API ядра. Изменён механизм конфигурации и упаковки:

- изменяемые настройки перенесены из каталога плагина в `data/config/murtext.php`;
- добавлен immutable-файл defaults плагина `murcms/plugins/murtext/defaults.php`;
- добавлен пример site-конфига `data/config/murtext.dist.php`;
- сохранение настроек больше не требует права записи на `murcms/plugins/murtext/`;
- legacy `murcms/plugins/murtext/config.php` используется только как источник миграции старых установок;
- канонический runtime-путь admin assets перенесён в `murcms/assets/plugins/murtext/`;
- встроенная справка не ссылается на отсутствующий runtime-каталог `docs/`;
- в интерфейсе отображаются раздельные версии: Plugin 0.2.4, Core 0.2.0, murtext-1.

## Packaging cleanup в 0.2.4

Версия 0.2.4 устраняет дублирование admin assets, не меняя parser core `0.2.0`, диалект `murtext-1` или синтаксис MurText:

- удалён неиспользуемый каталог `murcms/plugins/murtext/assets/`;
- единственный канонический runtime-путь admin assets — `murcms/assets/plugins/murtext/`;
- root-only package должен содержать ровно одну пару `admin.css` / `admin.js` MurText;
- regression-проверка package/build должна предотвращать повторное появление plugin-local дубля.

## Установка на новый сайт

Production-пакет устанавливается как overlay в корень сайта с сохранением структуры каталогов.

После распаковки:

1. Откройте **Админ-панель → Плагины**.
2. Включите `murtext`.
3. Откройте пункт **MurText** в меню админ-панели.
4. Выберите глобальный режим работы.

По умолчанию плагин установлен в безопасном для существующего сайта режиме: **MurText глобально выключен**. Он начинает обрабатывать только документы, в заголовке которых явно указано:

```text
parser: murtext
```

Для явного отключения на отдельном документе:

```text
parser: native
```

## Обновление с 0.2.0

В версии 0.2.0 настройки могли храниться в:

```text
murcms/plugins/murtext/config.php
```

В 0.2.1 этот путь считается legacy. Новый штатный runtime-конфиг:

```text
data/config/murtext.php
```

Автоматическая миграция работает так:

1. если `data/config/murtext.php` уже есть, используется он;
2. если его нет, но есть legacy `murcms/plugins/murtext/config.php`, плагин загружает legacy-настройки;
3. настройки нормализуются относительно новых defaults;
4. плагин пытается записать результат в `data/config/murtext.php`;
5. legacy-файл не удаляется автоматически;
6. после успешной миграции используется только `data/config/murtext.php`.

Важно: переходный overlay-пакет **не должен содержать новый файл** `murcms/plugins/murtext/config.php`, чтобы не перезаписать пользовательские настройки старой установки до миграции.

Если обновление выполняется с полным удалением старого каталога `murcms/plugins/murtext/`, сначала вручную перенесите старый конфиг:

```text
murcms/plugins/murtext/config.php → data/config/murtext.php
```

Или применяйте обновление overlay без предварительного удаления старого `config.php`. После первого успешного перехода следующие обновления становятся update-safe.

## Конфигурация

Файлы конфигурации:

```text
murcms/plugins/murtext/defaults.php   # заводские defaults, не изменять
data/config/murtext.dist.php          # пример site-конфига
data/config/murtext.php               # runtime-конфиг конкретного сайта
```

`defaults.php` поставляется вместе с кодом плагина и не должен изменяться администратором сайта.

`data/config/murtext.dist.php` — distributable-пример. Его можно использовать как образец для ручного создания `data/config/murtext.php`.

`data/config/murtext.php` — единственный штатный изменяемый конфиг. Административная страница MurText записывает только этот файл.

Порядок приоритета:

1. `data/config/murtext.php`;
2. legacy `murcms/plugins/murtext/config.php`, только если runtime-конфиг ещё отсутствует;
3. `murcms/plugins/murtext/defaults.php`.

Отсутствующие ключи автоматически дополняются значениями из defaults.

## Права на запись

Для работы и сохранения настроек PHP-процессу больше не нужно право записи на:

```text
murcms/plugins/murtext/
```

Необходимые writable locations:

```text
data/config/
data/storage/logs/
data/storage/backups/
```

Журнал диагностики MurText, если включён, пишется в:

```text
data/storage/logs/murtext.log
```

Перед перезаписью существующего runtime-конфига плагин пытается создать резервную копию в:

```text
data/storage/backups/murtext/
```

Хранятся последние 10 копий.

## Почему overlay содержит несколько файлов MurCMS

В исходной версии MurCMS тело документа сначала проходило через встроенную функцию `mur_render_body()`, и только после этого вызывались плагины. Поэтому плагин не мог получить исходный текст страницы, блока или snippet.

Текущая MurCMS предоставляет для этой интеграции публичные hooks
`content.render_body` и `admin.editor_tools`. MurText использует их как обычный
consumer Plugin API; они не принадлежат MurText и не определяются его
документацией. Их сигнатуры, контексты, return semantics и совместимость
описаны только в каноническом
[`../../development/plugin-api.md`](../../development/plugin-api.md).

## Глобальная активация

На странице настроек доступны два основных сценария.

### MurText выключен по умолчанию

Глобальный переключатель выключен. Штатный рендерер MurCMS используется везде, кроме файлов с:

```text
parser: murtext
```

Это рекомендуемый режим для постепенного внедрения.

### MurText включён по умолчанию

Глобальный переключатель включен. MurText применяется в отмеченных областях:

- страницы;
- категории;
- блоки главной;
- блоки футера;
- snippets.

Для отдельного документа можно вернуть штатную обработку:

```text
parser: native
```

Явное значение `parser` имеет приоритет над глобальной настройкой.

## Переопределения страницы

В редакторе страницы и категории появляется сворачиваемый блок **MurText**:

- парсер: наследовать / MurText / штатный;
- режим абзацев;
- компактный или читаемый HTML;
- строгая проверка.

В файле эти значения хранятся как meta:

```text
parser: murtext
murtext.paragraph_mode: blank_lines
murtext.pretty: 0
murtext.strict: 0
```

Пустые значения не записываются и наследуются от глобальных настроек.

## Режимы абзацев

### `blank_lines`

Рекомендуемый режим. Пустая строка разделяет абзацы, а соседние строки относятся к одному абзацу.

```text
Первая строка абзаца.
Продолжение абзаца.

Новый абзац.
```

### `line`

Каждая обычная строка становится отдельным абзацем.

## Принудительные переносы

Каждый способ можно независимо включить или выключить:

- два пробела в конце строки;
- обратный слэш в конце строки;
- отдельная команда `br`.

## Ссылки и пути

Пустые поля URL на странице настроек используют `site.base_url` автоматически.

Поддерживаются сокращения:

```text
[Контакты](##contacts)
![Фото](//photo.webp)
[Контакты](site:contacts)
![Фото](uploads:photo.webp)
```

`##` и `site:` указывают на корень сайта.  
`//` и `uploads:` указывают на каталог `/uploads`.

## Изображения

Глобально настраиваются значения `loading` и `decoding`:

```html
<img loading="lazy" decoding="async">
```

Оба атрибута можно отключить или переопределить непосредственно в разметке:

```text
![Описание](//photo.webp){loading="eager" decoding="sync" width="800"}
```

## Ошибки

Доступны два режима:

- **Исправить и вывести MurText** — парсер мягко закрывает незакрытые блоки и возвращает HTML;
- **Вернуться к штатному рендереру** — при строгих ошибках результат MurText не используется.

Журнал диагностики по умолчанию выключен. При включении записывается:

```text
data/storage/logs/murtext.log
```

## Помощник редактора

Три режима:

- выключен;
- только кнопка **MurText — справка**;
- справка и компактная панель вставки.

Помощник можно отдельно включить для:

- страниц и категорий;
- snippets;
- файлового редактора контентных блоков.

CSS и JavaScript помощника подключаются только в админке и только там, где помощник реально показан. Runtime-источник assets:

```text
murcms/assets/plugins/murtext/admin.css
murcms/assets/plugins/murtext/admin.js
```

На публичной части сайта assets MurText не подключаются.

## Производительность

Плагин использует ленивую загрузку:

1. На публичном запросе загружается только небольшой runtime-адаптер и config store.
2. Административный класс настроек и редактора подключается только для маршрутов `/admin`.
3. Файлы ядра MurText подключаются только если конкретный текст действительно должен быть обработан.
4. Повторный разбор одинакового текста в пределах одного запроса берётся из памяти.
5. Composer, база данных, сторонние библиотеки и фронтенд-зависимости отсутствуют.

При глобально выключенном MurText влияние на страницу ограничено одним вызовом хука, загрузкой defaults/runtime config и несколькими простыми проверками.

## Структура текущего production root

Рабочие runtime-компоненты MurText в MurCMS 0.4.2 расположены так:

```text
murcms/plugins/murtext/
├── plugin.php
├── defaults.php
├── src/
│   ├── version.php
│   ├── config_store.php
│   ├── plugin_adapter.php
│   └── admin_ui.php
└── core/
    ├── murtext.php
    └── src/...

murcms/assets/plugins/murtext/
├── admin.css               # канонический runtime asset
└── admin.js                # канонический runtime asset

data/config/
└── murtext.dist.php
```

`murcms/plugins/murtext/config.php`, `docs/` и `tests/` в текущий production root не входят. Legacy
`config.php` поддерживается кодом только как источник миграции старой установки, если такой файл
остался после обновления.

## Package profiles

### Production/runtime package

Рабочий профиль включает plugin code, единственную каноническую пару admin assets в
`murcms/assets/plugins/murtext/` и `data/config/murtext.dist.php`. Plugin-local каталог
`murcms/plugins/murtext/assets/` в production/root-only package отсутствует.

`docs/` и `tests/` в production plugin directory не требуются.

### Documentation package

Содержит документацию MurText, обычно в структуре:

```text
docs/plugins/murtext/
├── README_RU.md
├── CORE_README_RU.md
└── AI_INSTRUCTION_RU.md
```

### Source/dev package

Может содержать тесты, development artifacts и вспомогательные инструкции для сборки.

## Тесты

В development/source-пакете:

```bash
php murcms/plugins/murtext/tests/core.php
php murcms/plugins/murtext/tests/integration.php
```

Первый набор проверяет грамматику ядра. Второй проверяет подключение к хукам MurCMS, режимы `parser: murtext` и `parser: native`, страницу настроек, поля редактора, config migration и update-safe сохранение настроек.
