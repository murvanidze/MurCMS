# MurCMS: текущий справочник системы

Дата: 2026-09-07
Статус: актуальный справочник стабильной версии `0.4.1`

Этот документ фиксирует то, что уже реализовано в MurCMS сейчас. Его цель - служить локальной инструкцией для сборки сайтов на этой платформе и контекстом для дальнейшей разработки.

Текущая фаза: стабильная версия `0.4.1` собрана. Публикация на конкретном
сервере требует server/release-проверки по [`prerelease-checklist.md`](prerelease-checklist.md).

## 1. Назначение

MurCMS сейчас - легкое PHP flat-file ядро для быстрых многоязычных сайтов:

- корпоративные сайты;
- сайты услуг;
- портфолио и галереи;
- небольшие каталоги без тяжелого e-commerce;
- информационные страницы и справочные разделы.

Главные приоритеты: скорость, accessibility, чистые URL, простая файловая структура, минимум JavaScript, отсутствие jQuery в базовых интерактивных блоках.

## 2. Как запустить локально

Из корня проекта:

```powershell
php -S 127.0.0.1:8000 -t root root/index.php
```

Основные URL:

```text
/          -> редирект на язык по умолчанию
/ru/       -> главная RU
/ro/       -> главная RO
/robots.txt
/sitemap.xml
```

## 3. Структура проекта

```text
root/
  murcms/
    app/                ядро и admin PHP
    admin/lang/         языковые файлы админки
    assets/             admin assets и условные assets плагинов
    plugins/            подключаемые плагины
    templates/          frontend-шаблоны
  data/
    blocks/             данные carousel, counters, logos
    config/             site, languages, plugins, snippets
    content/            страницы, категории, home/footer blocks
    galleries/          данные галерей
    menus/              файловые меню
    snippets/           HTML/PHP-фрагменты
    storage/            backups, cache, generated, logs
  uploads/              пользовательские файлы и изображения
  index.php
  .htaccess
```

Публичный web root — `root/`. `.htaccess` запрещает прямой доступ к коду,
конфигам, исходным `.mur.md`, storage и резервным копиям. `robots.txt`
дополнительно исключает технические URL из обхода, но не считается механизмом
защиты доступа.

Front controller также добавляет базовые security headers динамическим ответам
и при локальном `php -S` отдаёт только allowlisted public assets, не скрытые
файлы и не произвольные расширения.

Публичные assets админки и плагинов доступны по `/murcms/assets/...`.
Assets активного frontend-шаблона находятся в `/murcms/templates/{template}/assets/...`.
Пользовательские файлы - по `/uploads/...`. Каталога `/assets/` в текущей структуре нет.

## 4. Конфигурация

`root/data/config/site.php`:

- `name` - название сайта;
- `description` - описание для главной;
- `base_url` - база для canonical, sitemap, hreflang;
- `timezone`;
- `environment`;
- `home_redirect_status`;
- `favicon_frontend` — ручной путь к favicon сайта относительно `uploads`;
- `favicon_admin` — ручной путь к favicon админки относительно `uploads`.

`root/data/config/languages.php`:

- `default` - язык по умолчанию;
- `enabled` - включенные языки, сейчас `ru`, `ro`;
- `labels` - подписи в переключателе языка.

`root/data/config/plugins.php`:

```php
'enabled' => [
    'snippets',
    'blocks',
    'gallery',
    'tags',
    'redirects',
    'murconsent',
    'murtext',
]
```

Плагины грузятся по имени из `root/murcms/plugins/{name}/plugin.php`.

Дополнительные конфиги:

- `admin.php` - сессия, пользователи, роли, язык и help mode админки;
- `seo.php` - sitemap и шаблон robots;
- `snippets.php` - разрешение или запрет PHP snippets;
- `theme.php` - активный frontend-шаблон и его параметры;
- `tags.php` - параметры плагина меток.

Файлы `*.dist.php` являются шаблонами установки. Рабочие конфиги без `.dist`
относятся к конкретному сайту и не должны безусловно заменяться при обновлении.

## 5. Формат `.mur.md`

Файлы контента состоят из header и body, разделенных строкой `---`.

```text
id: about
title: О проекте
slug: o-proekte
status: published
translation_key: about
seo.title: О проекте - MurCMS
seo.description: Короткое SEO-описание.
---

# О проекте

Текст страницы.
```

Header парсится как простые `key: value`. Вложенные YAML-структуры сейчас не поддерживаются. Body умеет:

- HTML как есть, если внутри есть `<`;
- простые Markdown-заголовки `#`, `##`, `###`;
- абзацы из текста;
- block-shortcodes отдельной строкой.

Это ограниченный parser, а не полный Markdown. Списки, ссылки, таблицы и
сложные компоненты следует писать валидным HTML либо выводить shortcode.

Для назначения страницы в несколько категорий используется скалярный список через запятую:

```text
categories: company, company-news
```

Поле `parent` отвечает за вложенность и URL, а `categories` — за редакционную рубрикацию. Категория по умолчанию выводит опубликованные страницы, в которых указан ее ID.

Если включен плагин `tags`, страницы также могут иметь поле:

```text
tags: seo, pagespeed, cms
```

Метки хранятся прямо в `.mur.md`. Если плагин выключить, это поле не удаляется и просто игнорируется ядром.

Важно: если в body есть HTML, он возвращается как есть. Это удобно для ручной
верстки, но требует дисциплины: HTML должен быть валидным и безопасным.

## 6. Страницы и URL

Страницы лежат в:

```text
root/data/content/{lang}/pages/*.mur.md
```

Поддерживаемые коллекции в repository:

```text
pages, categories, services, info, news
```

Префиксы URL:

- `pages` -> без дополнительного префикса;
- `services` -> `/services/...`;
- `info` -> `/info/...`;
- `news` -> `/news/...`;
- `categories` -> `/category/...`.

Для вложенности используется поле:

```text
parent: services
```

URL строится из `slug` родителя и `slug` страницы. Для языковых версий используется отдельная ветка: `/ru/...`, `/ro/...`.

## 7. Переводы и языки

Связь переводов задается через:

```text
translation_key: about
```

Переключатель языка:

- показывает глобус и текущий язык;
- ведет на перевод текущей страницы, если найден;
- если перевода нет, ведет на главную выбранного языка;
- работает обычными ссылками, без зависимости от JavaScript для перехода.

В layout генерируются `alternate hreflang` для всех включенных языков.

`languages.structure: flexible` разрешает различающиеся деревья языков.
`mirror` пока хранится как режим проекта, но не создаёт переводы автоматически
и не блокирует публикацию неполной языковой структуры.

## 8. Frontend-Шаблон И Layout

Публичный frontend вынесен в активный шаблон:

```text
root/murcms/templates/default/
  template.php
  DefaultTemplate.php
  assets/
    critical.css
    app.css
    menu.js
  themes/
    {scheme}/
      critical.css
      theme.css
```

Сейчас доступен один шаблон `default`. Он подключается через `TemplateManager`, а его параметры хранятся в `root/data/config/theme.php`.
В главном меню админки после группы «Настройки» находится отдельная группа
«Шаблон» с тремя страницами:

- `/admin/template` — активный frontend-шаблон, базовые параметры `default`,
  brand, глобальные defaults списков, fallback footer-меню и файлы меню;
- `/admin/template/home` — ordered layout и файлы блоков главной;
- `/admin/template/footer` — ordered layout и файлы блоков футера.

Старый `/admin/settings/theme` оставлен как совместимый alias общей страницы, а
`/admin/settings/theme/menu` продолжает обслуживать общий файловый редактор меню.

Brand поддерживает три режима: название из `site.name`, доверенный HTML внутри
ссылки на главную или изображение из `root/uploads/`. Для изображения в
`brand_image` задается путь относительно uploads; шаблон выводит его только
после проверки пути, существования файла и размеров, добавляя `width`, `height`,
`loading="eager"` и `decoding="async"`. Параметр `brand_image_height` задаёт
отображаемую высоту в диапазоне 24–120 px. Ширина вычисляется из intrinsic
aspect ratio и не сжимается на промежуточных ширинах экрана.

Новый сайт по умолчанию использует HTML-brand: `site-icon.svg` размером 50×50
и текст `MurCMS`. Канонический asset находится в
`root/murcms/assets/site-icon.svg`; он используется как fallback favicon
frontend и админки и как иконка рядом с брендом MurCMS в административном
sidebar.

Favicon настраивается в `/admin/settings/site`. Для frontend приоритет такой:

1. существующий файл из `favicon_frontend`;
2. первый по natural sort файл в корне `root/uploads/`, имя которого начинается
   с lowercase `favicon`, с расширением PNG, SVG или WebP;
3. стандартный `root/murcms/assets/site-icon.svg`.

Автоматический поиск не заходит в подкаталоги. Ручной путь задаётся относительно
`uploads` и может включать подкаталоги. Для админки используется
`favicon_admin`, а при пустом, неверном или отсутствующем файле — стандартная
иконка MurCMS; автоматического поиска admin favicon нет. URLs favicon получают
cache-busting по времени изменения файла.

Базовые правила первого экрана находятся в читаемом файле шаблона
`assets/critical.css`; `DefaultTemplate` только инлайнит его. Полный набор
обычных правил остаётся в `assets/app.css`. Layout CSS не хранится PHP-строкой.

Цветовые схемы автономны и принадлежат конкретному frontend-шаблону. Схема
автоматически появляется в админке, если создан каталог
`root/murcms/templates/{template}/themes/{scheme}/` с двумя файлами:

- `critical.css` — небольшие переменные и правила первого экрана; содержимое
  инлайнится в `<head>`;
- `theme.css` — остальные правила схемы; подключается обычным stylesheet с
  cache-busting по времени изменения файла.

Имя каталога допускает только `a-z`, `0-9`, `_` и `-`. PHP-файлы и манифесты
для цветовой схемы не требуются. Если выбранная схема отсутствует или неполна,
используется `light` либо первая доступная схема.

В `Template → General` отдельная форма хранит пользовательские стили активного
шаблона в update-safe каталоге:

```text
root/uploads/murcms-custom/{template}/critical.css
root/uploads/murcms-custom/{template}/custom.css
```

Critical CSS ограничен 64 KB, безопасно инлайнится после `layout.head` и не
создаёт отдельного запроса. Буквальное закрытие `</style` нейтрализуется без
HTML-экранирования всего CSS. Additional CSS ограничен 256 KB и подключается
следующим, последним штатным stylesheet с `mtime` cache-busting. Пустое поле
удаляет соответствующий файл; запись выполняется через временный файл и atomic
rename. Файлы разных frontend-шаблонов не смешиваются.

`Renderer` теперь отвечает за выбор контента и document/listing-логику, а активный шаблон собирает:

- `<!doctype html>`, `lang`, meta, canonical, OG;
- inline critical CSS;
- обычное подключение `/murcms/templates/default/assets/app.css?v=...`;
- header с brand, меню, языками;
- main;
- footer;
- `/murcms/templates/default/assets/menu.js?v=...` с `defer`, если это включено в настройках шаблона;
- ассеты плагинов только если shortcode реально использован.

Для обычных страниц автоматически добавляются:

- breadcrumbs;
- `<article class="page-article">`;
- page header с `h1` внутри article;
- `<div class="page-content">` вокруг body страницы и её listing;
- summary из `summary`, `description` или `seo.description`, если поле есть.

Публичные CSS-классы используют kebab-case с одним дефисом: например,
`site-menu-row`, `button-ghost`, `content-list-item`. Двойной дефис сохраняется
только у CSS custom properties (`--accent`), где он обязателен по стандарту.
Активная цветовая схема добавляет короткий body-класс `ts-{scheme}`: например,
`ts-light`, `ts-dark` или `ts-memorial-elegant`.

Если в body уже есть первый `# Заголовок`, renderer убирает дублирующий `h1`, потому что page header выводит заголовок из meta.

Отключить автоматический page header можно:

```text
page.header: false
```

## 9. Меню

Меню лежит в:

```text
root/data/menus/{lang}/main.mur.md
root/data/menus/{lang}/footer.mur.md
```

`main` используется в шапке. Footer-меню выводится либо строкой
`menu:{name}` в ordered footer layout, либо совместимым fallback-механизмом:
если включён `footer_menu_enabled`, после блоков добавляется меню из
`footer_menu_name` (по умолчанию `footer`).

Файлы меню можно редактировать в админке: `/admin/template`, секция
«Меню». Если у файла меню `status: draft`, меню не выводится.

Старый шестиколоночный формат строки:

```text
href | label | title | class | span_class | attrs
```

Для верхнего уровня главного меню можно задать отдельные подписи для десктопа
и мобильной версии с помощью семиколоночного формата:

```text
href | short_label | long_label | title | class | span_class | attrs
```

На десктопе выводится `short_label`, на ширине до 760 px — `long_label`.
Этот режим действует только для верхнего уровня меню `main`. Если
`long_label` пуст или строка использует прежний шестиколоночный формат,
`short_label` применяется на обеих ширинах. Старый формат остается полностью
совместимым.

`href` может быть:

- абсолютным путем: `/ru/`;
- обычным путем: `contacts`;
- ссылкой на документ: `page:about`;
- `#`;
- внешним URL.

Вложенное меню задается квадратными скобками:

```text
[
page:about | О проекте | Информация | | |
page:history | История | Вложенная страница | | |
]
```

Открывающая `[` ставится перед родительским пунктом группы, закрывающая `]` —
после последнего дочернего пункта.

Вложенность может быть глубже одного уровня. Для третьего уровня открывается
еще одна группа внутри первой:

```text
[
# | Раздел | Раздел меню | | |
[
page:topic | Подраздел | Подраздел меню | | |
page:article | Материал | Страница третьего уровня | | |
]
]
```

На десктопе второй flyout открывается сбоку и автоматически переносится влево,
если справа не хватает места. На мобильной ширине каждый уровень работает как
независимый accordion.

Для мегаменю у элемента верхнего уровня укажите класс `menu-mega`. Его подменю
на десктопе раскладывается в колонки, а вложенные группы третьего уровня
показываются внутри колонок. На мобильной ширине оно остается обычным
многоуровневым accordion.

Минимальный каркас мегаменю:

```text
[
# | Разделы | Все разделы сайта | Каталог | menu-mega | |
[
# | Услуги | Услуги | | |
page:web | Сайты | | | |
page:seo | SEO | | | |
]
[
# | Компания | Компания | | |
page:about | О нас | | | |
page:contacts | Контакты | | | |
]
]
```

В примере верхний элемент использует семиколоночный формат, а элементы колонок
— совместимый шестиколоночный. `menu-mega` указывается именно в поле `class`.

На десктопе подменю открывается по hover/focus, а клик по надписи переходит по
ее ссылке. На мобильном подменю изначально скрыто и раскрывается отдельной
рамочной кнопкой-стрелкой как accordion; клик по надписи также выполняет
переход. Если у родителя указан `#`, CMS использует как его адрес первую
реальную ссылку подменю — это позволяет первой дочерней ссылке быть hub-страницей
без дублирования URL в данных меню.

## 10. Home и footer

Файлы блоков главной лежат в:

```text
root/data/content/{lang}/home/**/*.mur.md
```

Footer-блоки лежат в:

```text
root/data/content/{lang}/footer/**/*.mur.md
```

Порядок и видимость задаются отдельно в `/admin/template/home` и
`/admin/template/footer`: одно имя файла без `.mur.md` на строку. Имя файла
является идентификатором блока; поля
`id`, `status`, `order` и `footer.position` для home/footer-блоков не нужны и не
участвуют в выводе. Порядок строк является порядком вывода, а отсутствующие в
списке имена не выводятся.

В footer-списке также разрешена строка `menu:{name}`, например `menu:footer`.
Она вставляет меню `root/data/menus/{lang}/footer.mur.md` в точное место между
блоками. Старые параметры `footer_menu_enabled` и `footer_menu_name` остаются
совместимым режимом: если явной строки меню нет, включённое footer menu
добавляется после блоков.

В `flexible` режиме сохраняется отдельный список каждого включённого языка. В
`mirror` показывается один список основного языка, применяемый ко всем языкам.
При выводе каждого имени CMS сначала ищет файл в текущем языке, затем — в основном.
Это позволяет хранить общий copyright, счётчик или другой нейтральный блок один
раз. Найденный файл основного языка рендерится как fallback только при отсутствии
такого ID в текущем языке.

Если `block_layout` отсутствует, файлы автоматически не публикуются. Админка
заполняет textarea найденными именами как предложенный первоначальный список;
вывод начинается после его сохранения. Поэтому сам факт наличия файла не меняет
публичную страницу.

Допускаются вложенные каталоги. Например:

```text
root/data/content/ru/home/hero.mur.md
root/data/content/ru/home/sections/emergency.mur.md
```

В настройках шаблона можно создать блок, создать каталог внутри `home`/`footer`,
дублировать файл в другой язык или каталог, редактировать, скачать и удалить.
Идентификатором остается basename файла без `.mur.md`, а не относительный путь:
для примера выше ID равен `emergency`. Поэтому имя файла должно быть уникально
внутри `home` либо `footer` одной языковой ветки, в том числе между вложенными
каталогами.

Текущий footer поддерживает:

- название/логотип;
- короткое описание;
- нижнее меню;
- контакты;
- responsive flagcounter-картинку;
- map-картинку;
- legal links;
- copyright/CC.

## 11. Listing-страницы

Страница может автоматически вывести дочерние страницы.

Минимальный пример:

```text
template: listing
list.source: children
list.view: cards
list.sort: order
list.direction: asc
list.per_page: 12
list.position: bottom
list.limit: 60
list.show_image: false
list.show_description: true
```

Глобальные значения по умолчанию для списков задаются в общих настройках
шаблона `/admin/template` и хранятся в `root/data/config/theme.php`:

- отдельно для `children` — списков дочерних страниц;
- отдельно для `category` — списков страниц текущей рубрики.

Глобально настраиваются `view`, `sort`, `direction`, `per_page` и `position`.
Если у конкретной страницы заполнено соответствующее поле `list.*`, оно
переопределяет глобальную настройку.

`list.per_page` включает пагинацию и задаёт число элементов на странице.
Пустое значение оставляет список без пагинации. Допустимый диапазон в админке:
от 1 до 100. Номер страницы передаётся как `?page=N`; для страниц пагинации
canonical URL также содержит этот параметр.

`list.limit` ограничивает общий набор до разбиения на страницы.

`list.position` управляет местом вывода списка:

- `top` — перед основным текстом страницы, но после автоматического заголовка;
- `bottom` — после основного текста;
- `shortcode` — только в месте строки `[list]` внутри body.

Поддерживаемые варианты сортировки:

```text
order, date, updated_at, title, id, filename
```

Поддерживаемые view сейчас зависят от CSS:

- `cards` — карточки, в которых можно отдельно управлять изображением и описанием;
- `titles` — строгий список только заголовков; `list.show_image` и
  `list.show_description` в этом режиме игнорируются;
- `feed` — лента, частично поддержанная в renderer.

Дочерние страницы определяются по `parent`.

Карточка берёт описание из `summary`, `description` или `seo.description`, а
изображение из `image`, `hero.image` или `og.image`. Текущий listing renderer
не имеет отдельных meta-полей для intrinsic width/height изображения. Для
стабильного layout следует использовать одинаковые пропорции и CSS-контейнер
либо расширить renderer с тестом.

## 12. Shortcodes

Shortcodes должны стоять отдельной строкой, чтобы parser не завернул их в `<p>`.

Поддерживаются:

```text
[snippet=cta]
[ushka=cta]
[gallery=nature-demo]
[carousel=services]
[counters=site]
[logos=partners]
[list]
[tagcloud]
```

Также можно использовать `:` вместо `=`:

```text
[gallery:nature-demo]
```

## 13. Snippets

Плагин: `root/murcms/plugins/snippets/plugin.php`  
Данные:

```text
root/data/snippets/{lang}/{name}.mur.md
root/data/snippets/{lang}/{name}.html
root/data/snippets/{name}.mur.md
root/data/snippets/{name}.html
```

Пример:

```text
id: cta
status: published
type: html
---

<section class="callout">
  ...
</section>
```

Shortcode:

```text
[snippet=cta]
```

`type: php` технически поддержан, но выполняется только если
`root/data/config/snippets.php` содержит `allow_php => true`.
Дистрибутивный `snippets.dist.php` по умолчанию оставляет PHP выключенным;
включение делается явно в рабочем конфиге конкретного сайта.

Плагин также бесшумно проверяет системные snippets:

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

Четыре дополнительные позиции используют уже существующие hooks:
`system-header-before`/`after` — до и после основного header,
`system-main-before` — перед `<main>`, `system-main-after` — после `</main>` и
перед footer. Остальные позиции сохраняют прежнее расположение в head, body,
main и article.

Админка показывает все 11 позиций единым scope-aware registry. Он различает
точный файл выбранной области и fallback из global/основного языка. Действия
Create/Edit используют штатный snippet editor и не создают пустых файлов до
Save.

Порядок поиска: текущий язык, global, основной язык сайта. Найденный черновик
ничего не выводит и останавливает fallback. Дистрибутивный default оставляет `allow_php` выключенным; фактическое значение определяется рабочим `data/config/snippets.php` конкретного сайта. Редактирование snippets остается доступно только роли `admin`.

### Опциональный MurText

В сборку входит плагин `root/murcms/plugins/murtext/`; он включён в стандартном
списке плагинов новой установки. Его настройки доступны по `/admin/plugin/murtext`.

По умолчанию MurText применяется только к документам с meta-полем:

```text
parser: murtext
```

Если в настройках плагина включён глобальный режим, отдельный документ можно
вернуть к штатному parser через `parser: native`. Области применения:
страницы, категории, home, footer и snippets. Плагин добавляет свои поля к
редактору документа и toolbar/справку перед textarea, используя нейтральные
hooks MurCMS. Его admin CSS/JS подключаются внешними assets и только когда
инструменты редактора действительно выведены.

## 14. Tags / Метки

Плагин: `root/murcms/plugins/tags/plugin.php`  
Конфиг: `root/data/config/tags.php`

Плагин добавляет к страницам meta-поле:

```text
tags: seo, pagespeed, cms
```

В админке поле показывается в редакторе страниц. Рядом выводятся уже существующие
метки текущего языка; клик по метке добавляет ее в input. Данные сохраняются в
самом `.mur.md`, отдельной базы меток нет.

Публичные URL:

```text
/{lang}/tag/{slug}
```

На странице метки выводятся опубликованные страницы текущего языка с этой меткой.
Параметр `pages.per_page` в `tags.php` задает пагинацию tag-страниц.
SEO для tag-страниц генерируется автоматически из шаблонов в
`tags.php -> seo.title_templates` и `seo.description_templates`. Выбор шаблона
стабилен для каждой пары `language + tag slug`: это дает разнообразные title и
description для разных меток, но не меняет meta одного URL между запросами.
Доступные плейсхолдеры: `{tag}`, `{site}`, `{count}`. Для страниц пагинации к
title и description добавляется номер страницы, а canonical получает `?page=N`.

Shortcode облака:

```text
[tagcloud]
[tagcloud max=20 sort=count order=desc]
```

Поддержанные параметры shortcode: `max`, `min_count`, `show_counts`, `sort`,
`order`. CSS плагина подключается условно, только когда выводятся метки, tag cloud
или tag page. Если `sitemap => true`, страницы меток добавляются в `sitemap.xml`
через hook `seo.sitemap_urls`.

## 15. Blocks

Плагин: `root/murcms/plugins/blocks/plugin.php`  
Ассеты:

```text
root/murcms/assets/plugins/blocks/blocks.css
root/murcms/assets/plugins/blocks/blocks.js
```

CSS подключается только если на странице есть блок. JS подключается только для `carousel` или `counters`; для `logos` JS не нужен.

Данные ищутся так:

```text
root/data/blocks/{lang}/{type}/{name}.mur.md
root/data/blocks/{type}/{name}.mur.md
```

### Carousel

Shortcode:

```text
[carousel=services]
```

Файл:

```text
root/data/blocks/ru/carousel/services.mur.md
```

Header:

```text
id: services-carousel
status: published
title: Витрина услуг
text: Описание блока.
action.label: Все услуги
action.url: /ru/uslugi
```

Body, одна карточка на строку:

```text
Название | Описание | URL | image | alt
```

Поведение: scroll-snap лента, кнопки назад/вперед, без autoplay, карточки остаются читаемыми без JS.

### Counters

Shortcode:

```text
[counters=site]
```

Файл:

```text
root/data/blocks/ru/counters/site.mur.md
```

Body:

```text
value | label | prefix | suffix
```

Анимация: `IntersectionObserver` + `requestAnimationFrame`. При `prefers-reduced-motion: reduce` счетчики сразу показывают финальное значение.

### Logos

Shortcode:

```text
[logos=partners]
```

Body:

```text
image | alt | url | width | height | link_title | target
```

Если `url` пустой, логотип выводится как обычная картинка. Если указан, картинка становится ссылкой.
`link_title` задает атрибут `title` ссылки отдельно от `alt` картинки. `target`
принимает `_blank`, `_self`, `_parent`, `_top` или безопасное имя окна; для
`_blank` автоматически добавляется `rel="noopener"`.

## 16. Gallery

Плагин: `root/murcms/plugins/gallery/plugin.php`  
Данные:

```text
root/data/galleries/{lang}/{name}.mur.md
root/data/galleries/{name}.mur.md
```

Shortcode:

```text
[gallery=nature-demo]
```

Header:

```text
id: nature-demo
title: Легкая демо-галерея
status: published
columns: 3
captions: visible
```

Body:

```text
full_url | thumb_url | alt | caption | credit | thumb_width | thumb_height
```

Важно: `full_url` и `thumb_url` должны быть разными, если нужна нормальная полноразмерная картинка в lightbox. Миниатюра получает `loading="lazy"`, `decoding="async"`, фиксированные `width/height`.

Lightbox:

- создается только если на странице есть gallery;
- открывается по клику;
- поддерживает Esc, стрелки, swipe;
- пишет шаги в browser history;
- локализует aria-label для RU/RO/EN;
- не требует jQuery.

## 16.1 Redirects

Плагин: `root/murcms/plugins/redirects/plugin.php`
Конфиг: `root/data/config/redirects.php`
Dist-конфиг: `root/data/config/redirects.dist.php`

Плагин включен в стандартный список enabled plugins, но пустой конфиг не меняет публичное поведение сайта.
Админка доступна только роли `admin` и выше:

```text
/admin/plugin/redirects
```

Формат строк:

```text
source | target | status
```

Примеры:

```text
/ru/old | /ru/new | 301
/ro/vechi?x=1 | /ro/nou | 302
https://old.example/ru/about | /ru/about | 301
regex:^/ru/legacy/(.+)$ | /ru/archive/$1 | 308
```

`source` может быть site-root path, path с query string или абсолютным `http(s)` URL. Без query string
literal source сравнивается только по path, поэтому `/ru/old` сработает и для `/ru/old?utm=...`.
Регулярные выражения включаются только явным префиксом `regex:`; `$1`, `$2` и следующие группы
подставляются в target. `target` должен быть локальным path с `/` или абсолютным `http(s)` URL.
Допустимые статусы: `301`, `302`, `303`, `307`, `308`; пустой или недопустимый статус становится `302`.

В админке есть два textarea:

- active redirects — выполняются до поиска документа;
- 404-only redirects — выполняются только после того, как ядро и публичные plugin routes не нашли страницу.

При сохранении выполняются CSRF-проверка, проверка роли, валидация строк и резервная копия прежнего
`data/config/redirects.php` в `data/storage/backups/redirects/`.

## 16.2 MurConsent 1.0.3

Плагин: `root/murcms/plugins/murconsent/plugin.php`
Assets: `root/murcms/assets/plugins/murconsent/`
Рабочий конфиг: `root/data/config/murconsent.php`
Dist-конфиг: `root/data/config/murconsent.dist.php`

Плагин включён в стандартный список enabled plugins. В заводской конфигурации
GA4, Google Ads, GTM и Flag Counter отключены, поэтому плагин не меняет публичный
вывод, не создаёт consent cookie и не обращается к внешним сервисам.

Настройки доступны роли `admin` и выше:

```text
/admin/plugin/murconsent
```

Поддерживаются независимые прямые GA4 и Google Ads, строгий GTM Basic Consent Mode,
Flag Counter, RU/RO/EN, отзыв согласия и JavaScript API событий. До положительного
выбора соответствующей категории внешние скрипты и изображения не создаются.
Тестовый режим всегда показывает интерфейс, не сохраняет выбор и не загружает сервисы.

Безопасный preview `/?murconsent_preview=1` доступен только авторизованному
администратору. При обновлении копируй код и assets плагина, но не заменяй
существующий `murconsent.php`; новый `murconsent.dist.php` сравнивается с рабочим
конфигом вручную. Подробности: [`plugins/murconsent.md`](plugins/murconsent.md) и
[`plugins/murconsent-installation.md`](plugins/murconsent-installation.md).

## 17. CSS и JS политика

Текущее правило: не откладывать CSS, который задает размеры и сетки. Иначе возможен layout shift.

Сейчас:

- `/murcms/templates/default/assets/app.css?v=...` подключен обычным stylesheet;
- plugin CSS подключается только на страницах, где plugin используется;
- JS подключается через `defer`;
- `/murcms/templates/default/assets/menu.js?v=...` грузится, если включен параметр `load_menu_js`;
- `blocks.js` грузится только для carousel/counters;
- `gallery.js` грузится только для gallery.

Frontend design assets активного шаблона находятся по `/murcms/templates/{template}/assets/...`;
admin/plugin assets - по `/murcms/assets/...`; пользовательские файлы Media Manager - по `/uploads/...`.

При добавлении нового блока:

- задавать стабильные размеры картинок через `width`/`height`;
- использовать `aspect-ratio`, `minmax`, `min-width: 0`;
- избегать layout shift;
- не добавлять jQuery;
- учитывать keyboard и `prefers-reduced-motion`.

## 18. Иконки

Локальный sprite шаблона `default`:

```text
root/murcms/templates/default/assets/icons.svg
```

Сейчас используются globe для выбора языка, menu/hamburger для мобильной
навигации и базовые UI/social/contact icons. Для нового типового сайта
предпочтительно расширять sprite активного шаблона, а не подключать внешний
icon font.

## 19. SEO и служебные URL

Реализовано:

- canonical;
- meta description;
- OG title/description/url/type;
- `hreflang`;
- `robots.txt`;
- `sitemap.xml`;
- language-prefixed URL.

Sitemap включает главные языковые страницы и все опубликованные документы.

Если в браузере есть действующая admin-сессия, frontend дополнительно выводит
компактную фиксированную панель. Для обычных страниц она содержит ссылку в
существующий редактор, кнопку просмотра вычисленных title, description,
canonical, robots и Open Graph, а также вход в админку. Панель подключена через
`layout.body_end`, не создает нового write-маршрута и не запускает PHP-сессию у
обычного посетителя без admin cookie.

## 20. Что уже есть в демо-контенте

RU/RO страницы:

- главная;
- about;
- history как вложенная страница;
- services listing;
- service-sites;
- service-seo;
- portfolio listing;
- portfolio-school;
- portfolio-catalog;
- contacts;
- privacy;
- terms;
- cookies.

Демо-блоки:

- `services` carousel;
- `site` counters;
- `partners` logos;
- `nature-demo` gallery.

## 21. Границы текущей версии

Уже реализованы функциональные редакторы страниц, категорий, блоков, snippets,
конфигурации, SEO, базовая frontend-template система, плагин меток, а также Media Manager с загрузкой и WebP-конвертацией.

Пока не реализованы:

- web-установщик и автоматический updater;
- формы обратной связи и отправка почты;
- поиск;
- каталог CSV как штатный модуль;
- общий cache layer и генерация статического HTML;
- UI для JSON-LD;
- автоматическое enforcement режима `mirror`;
- полный Markdown parser;
- несколько полноценных независимых frontend-шаблонов и миграция настроек между ними;
- полный набор HTTP-регрессионных тестов для всех admin POST-сценариев.

Media Manager конвертирует JPEG/PNG/GIF в WebP через GD или Imagick, но пока не
генерирует responsive-набор размеров и не принимает SVG через UI.

## 22. Админка

Sidebar показывает название текущего сайта под брендом MurCMS и содержит
ссылку `Frontend`, открывающую публичную главную в новой вкладке. В списках
страниц и категорий заголовок, ID и URL объединены в одну широкую ячейку.
Список страниц по умолчанию показывает основной язык. Фильтр позволяет выбрать
другой один язык либо режим всех языков. Режим всех языков предназначен для
просмотра: чекбоксы и пакетные действия в нём не выводятся. В режиме одного
языка под списком доступны выделение всех показанных строк, инверсия выбора,
скачивание ZIP, смена статуса, назначение одного родителя, добавление одной или
нескольких рубрик и удаление. Сервер дополнительно отклоняет подменённый запрос
со страницами разных языков. Перед групповым удалением создаётся единый ZIP
исходных файлов в `data/storage/backups/bulk-pages`.

Общий `admin-theme.js` подключается на всех авторизованных страницах админки:
он обслуживает выделение, инверсию и диалоги как в списке страниц, так и в
Media Manager. Список адресов выбранных файлов открывается в модальном слое без
перезагрузки; серверный POST остаётся fallback-вариантом без JavaScript.

Директива `Host` больше не поддерживается в `robots.txt`: она удалена из
шаблонов и автоматически фильтруется из старых сохранённых конфигураций.

Текущая админка доступна по:

```text
/admin/
/admin/login
```

Реализовано:

- отдельный admin router;
- login по username или e-mail;
- password hash через `password_verify`;
- PHP sessions с `HttpOnly`, `SameSite=Lax`, `Secure` при HTTPS, strict mode и
  передачей идентификатора только через cookie;
- `session_regenerate_id(true)` после успешного входа;
- CSRF token для login/logout POST;
- простая защита от перебора в рамках session;
- роли `super_admin`, `admin` и `editor`; старое имя `content` остаётся
  совместимым alias роли редактора;
- `403` для разделов, где прав недостаточно;
- security headers для `/admin`: `no-store`, `nosniff`, `DENY`, `Referrer-Policy`, CSP;
- отдельный `/murcms/assets/admin.css`;
- dashboard с компактными счётчиками страниц, блоков главной, блоков футера,
  URL в sitemap и активных плагинов;
- редактор страниц и категорий с GUI-полями для header/meta и textarea для body;
- компактный фильтр списка страниц: один структурный критерий (язык, рубрика или
  родительская страница) совместно с необязательным фильтром статуса;
- основной язык по умолчанию, отдельные режимы других языков и обзорный режим
  всех языков без чекбоксов и пакетных действий;
- выбор полного списка или пагинации по 50/10 отфильтрованных страниц с
  сохранением фильтров в ссылках перехода;
- групповые операции страниц: выделение всех показанных строк, инверсия,
  скачивание ZIP, смена статуса, назначение родителя, добавление нескольких
  рубрик и удаление с предварительным ZIP-backup; одна операция всегда
  ограничена одной языковой версией;
- тулбары строк: edit, clone, download, delete with confirmation;
- удаление страницы или категории после ввода `Y`, `y` или `1` вместо ID;
- редактор site settings;
- отдельные ручные favicon frontend/admin и автоматическое обнаружение
  `uploads/favicon*.{png,svg,webp}` для frontend;
- настройки активного frontend-шаблона, brand, глобальные defaults списков,
  fallback footer menu и ordered layouts home/footer с созданием каталогов,
  созданием, дублированием, редактированием, скачиванием и удалением блоков;
- общий файловый editor/uploader для allowlisted `.mur.md` сущностей;
- отдельный Upload одного файла с target language/scope и конфликтным суффиксом `-1`, `-2` без перезаписи;
- Replace существующего `.mur.md` под исходным filename с предварительным backup в `data/storage/backups/file-replacements/`;
- централизованные admin breadcrumbs, построенные из тех же групп и пунктов, что sidebar;
- файловый/image manager для `root/uploads`;
- frontend language settings and independent admin UI language settings;
- управление enabled plugins через `root/data/config/plugins.php`;
- core SEO-раздел для sitemap, robots.txt и `.htaccess`;
- snippets/ushki editor с language-specific и global/all scopes;
- plugin `tags`: поле меток в редакторе страниц, `/admin/plugin/tags`, tag pages, tag cloud и sitemap hook;
- plugin `redirects`: глобальные и 404-only редиректы через `/admin/plugin/redirects`;
- plugin `murconsent`: strict opt-in для GA4, Google Ads, GTM и Flag Counter,
  preview и диагностика через `/admin/plugin/murconsent`;
- расширение административного интерфейса через публичный Plugin API;
- контекстные подсказки pipe-синтаксиса в файловом редакторе меню, carousel,
  counters, logos и gallery;
- корректное сохранение GUI-полей с точками (`seo.*`, `list.*`, `page.header`)
  после PHP-нормализации имён POST-полей;
- личный профиль с именем, e-mail, телефоном, выбором языка интерфейса и
  безопасной сменой пароля;
- `/admin/settings/users` для управления пользователями: супер-администратор
  управляет администраторами и редакторами, администратор — только редакторами;
  второй супер-администратор через UI не создаётся;
- деактивация/удаление пользователя и смена его пароля прекращают действие
  старых сессий; перед записью `admin.php` сохраняются две резервные версии;
- проверка дубликатов ID, URL и translation key перед сохранением документа;
- детерминированная индексация файлов контента и отдельный индекс опубликованных
  переводов;
- проверка родителя и циклов дерева;
- две последние резервные версии документа перед сохранением или удалением;
- запрет удаления родителя или рубрики, пока на них ссылаются документы;
- разделение списков по языкам и иерархический вывод категорий;
- дерево категорий с множественным выбором в редакторе страницы;
- сворачиваемые второстепенные группы параметров и help mode;
- компактные SVG-иконки действий и статусов.

Основной интерфейс сейчас подготовлен на русском. Файлы RO/EN/UK и
fallback-механизм существуют, но полнота переводов админки ещё не является
выпускным обещанием.

Пользователи задаются в `root/data/config/admin.php`. Публичный
`admin.dist.php` намеренно содержит пустой список пользователей и не предоставляет
общих starter credentials. Для новой установки уникальная запись `super_admin`
создаётся локальным `scripts/create-admin-config.ps1`; существующий `admin.php`
скрипт не перезаписывает.

Текущие роли: `super_admin`, `admin`, `editor`; старое имя `content` существует
только как legacy alias редактора. Каноническое описание иерархии, разрешений,
управления пользователями и session invalidation находится в
[`admin/users.md`](admin/users.md).

Admin routes:

```text
/admin/
/admin/settings/site
/admin/settings/languages
/admin/settings/admin
/admin/settings/profile
/admin/settings/users
/admin/settings/users/edit
/admin/settings/users/delete
/admin/template
/admin/template/home
/admin/template/footer
/admin/settings/theme/menu
/admin/content/pages
/admin/content/categories
/admin/content/snippets
/admin/files/edit
/admin/files/delete
/admin/files/upload
/admin/files/replace
/admin/files/download
/admin/media
/admin/seo
/admin/seo/sitemap
/admin/seo/robots
/admin/seo/htaccess
/admin/plugins
/admin/plugin/{plugin}
/admin/plugin/tags
/admin/plugin/redirects
```

Совместимый route `/admin/settings/theme` открывает `/admin/template`.

На уровне продукта content workflows доступны `editor` и выше, а настройки,
SEO, plugins и trusted snippets относятся к административной области. Полная
матрица ролей и исключения не дублируются здесь; см.
[`admin/users.md`](admin/users.md).

Plugin extension model:

Публичный программный контракт plugins — loading model, `PluginContext`, hooks,
admin menu/routes, role enforcement, file collections, assets, configuration и
backward compatibility — намеренно не дублируется в этом системном reference.
Единственный канонический developer reference: [`development/plugin-api.md`](development/plugin-api.md).

Этот документ фиксирует только текущее продуктовое состояние: включённые plugins
могут расширять frontend/content/admin через предусмотренный Plugin API, а их
административные разделы располагаются под `/admin/plugin/{plugin}`. Конкретные
сигнатуры extension points, поля registration structures и security contract
нужно брать только из канонического Plugin API reference.

При стандартном наборе включённых плагинов admin-пункты добавляют `blocks`,
`gallery`, `tags`, `redirects`, `murconsent` и `murtext`. Snippets/ushki
перенесены в базовый admin-раздел `/admin/content/snippets`, потому что это
обязательная возможность системы.

Подробности текущих редакторов: [`admin/content.md`](admin/content.md), [`admin/media.md`](admin/media.md), [`admin/languages.md`](admin/languages.md) и [`admin/seo-snippets.md`](admin/seo-snippets.md).

## 23. Проверки перед сдачей изменений

Этот current reference не является владельцем полного release acceptance checklist.
Для обычной правки проверяйте только реально затронутые артефакты и доступное runtime behavior:

- изменённые PHP-файлы — `php -l`;
- изменённые JavaScript-файлы — `node --check`, если Node доступен;
- content/config/Markdown — проверка фактического формата, путей и ссылок;
- затронутые frontend/admin workflows — browser smoke на доступной среде.

В полном source repository используются repository-level проверки, если соответствующие
файлы присутствуют в рабочем дереве:

```powershell
.\scripts\test.ps1
php tests/smoke.php --production
```

Эти команды относятся к полному repository tooling. Root-only server package и отдельный
documentation package не обязаны содержать `scripts/` или `tests/`.

Для браузерного smoke выбирайте сценарии, относящиеся к изменению. Базовый набор обычно
включает языковые главные, обычную страницу/listing, меню, используемые blocks/gallery,
404, frontend admin bar при наличии admin-сессии и соответствующий admin workflow.
Проверка ролей выполняется отдельными пользователями без использования hardcoded credentials
из документации.

Полный server/release проход определяется только
[`prerelease-checklist.md`](prerelease-checklist.md).

## 24. Правила для будущей разработки

При работе над MurCMS:

- сначала читать этот справочник и фактический код;
- не переносить чужой код из MaxSite/MF16/Albireo напрямую;
- использовать референсы только как источник идей;
- держать ядро маленьким;
- новые возможности добавлять через файлы, hooks или plugins, если это не ломает простоту;
- не подключать тяжелые библиотеки ради одного UI-эффекта;
- не откладывать CSS, если он влияет на размеры первого экрана;
- не добавлять декоративные элементы, которые ухудшают PageSpeed или accessibility;
- все изображения в контенте должны иметь размеры или стабильный контейнер;
- новые JS-модули должны быть условными и `defer`;
- mobile и keyboard behavior проверять как часть задачи.

## 25. Статус версии 0.4.1

Текущий стабильный release — `0.4.1`. Канонический источник версии и правила
release snapshot описаны в [`development/versioning-release.md`](development/versioning-release.md).

Production readiness не выводится из этого справочника и не фиксируется здесь как результат
конкретной локальной машины. Перед публикацией на целевом сервере необходимо выполнить
[`prerelease-checklist.md`](prerelease-checklist.md), включая доступный repository smoke,
server/security checks, staging-update verification и browser/accessibility checks.
