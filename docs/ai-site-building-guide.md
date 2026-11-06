# Инструкция для ИИ: сборка сайта на MurCMS

Дата: 2026-09-07
Статус: автономная рабочая инструкция для ChatGPT, Codex и других coding agents

## 1. Роль, минимальный комплект и границы

Ты собираешь и настраиваешь конкретный сайт на существующей MurCMS, а не проектируешь новую CMS. Для обычной site-building задачи используй готовые сущности платформы и сохраняй update-safe границу между кодом дистрибутива и данными конкретного сайта.

### Минимальный автономный комплект

Для стандартной сборки сайта достаточно двух документов:

1. этого `ai-site-building-guide.md`;
2. `current-reference.md` той же версии MurCMS.

Этот двухфайловый комплект рассчитан на создание и изменение страниц, языковых веток, меню, home/footer, categories/listings, snippets, штатных blocks/gallery/tags, media, SEO-настроек и site-specific оформления без разработки нового Plugin API или изменения архитектуры core.

Остальная документация является профильным дополнением, а не обязательной частью обычной задачи:

- установка, перенос или update CMS → `installation-update.md`;
- глубокая работа с конкретной admin-подсистемой → соответствующий файл из `admin/`;
- изменение или разработка плагина, нового hook/route или другого extension point → `development/plugin-api.md`;
- изменение конкретного bundled plugin → документация этого plugin из `plugins/`;
- полноценный release/deployment audit → `prerelease-checklist.md`.

Если такой профильный документ не передан, не выдумывай его контракт. Для обычной site-building задачи оставайся в пределах возможностей, описанных этим guide и `current-reference.md`. Если требование объективно выходит за эту границу, явно укажи, какой дополнительный reference нужен.

### Обязательные рабочие правила

Эти правила действуют даже если никаких других инструкций рядом нет:

- сначала используй контент, конфигурацию сайта, существующие сущности, snippets, bundled plugins и update-safe CSS; core меняй только для действительно общего поведения CMS;
- site-specific данные и изменяемые настройки не записывай внутрь distributable core/plugin/template code, если для них существует `data/`, `uploads/` или другой runtime path;
- client-specific CSS по умолчанию хранится в update-safe `uploads/murcms-custom/{template}/`, а не в распространяемом template;
- не придумывай отсутствующие hooks, routes, config keys, helper functions, scripts или файловые пути: сверяй их с текущим root и `current-reference.md`;
- не используй historical brief как описание текущего API. `archive/design/technical-brief.md` фиксирует исходный замысел проекта и может быть полезен только как исторический контекст;
- не перезаписывай несвязанные изменения и не удаляй пользовательские данные без прямого требования задачи;
- не помещай secrets, production credentials или персональные site-specific значения в distributable defaults;
- если нужного факта нет в предоставленном контенте, не выдумывай юридические реквизиты, цены, адреса, телефоны, характеристики или обещания;
- запускай только реально существующие проверки. Не объявляй тест, lint или smoke пройденным, если он фактически не выполнялся;
- в отчёте отделяй выполненные изменения от ограничений, непроверенных частей и данных, которые ещё требуется получить.

## 2. Что получить из брифа

До реализации сформируй рабочую модель проекта:

- назначение сайта и целевые действия;
- основной и дополнительные языки;
- дерево страниц каждого языка;
- редакционные категории;
- главное меню и footer;
- список повторяемых блоков;
- контент, контакты, юридические страницы;
- SEO title и description;
- изображения, alt-тексты и реальные размеры;
- внешние скрипты, аналитика и интеграции;
- требования к дизайну и примеры;
- что должно редактироваться через админку.

Если данных не хватает, не выдумывай юридические реквизиты, цены, адреса,
телефоны или фактические преимущества. Используй явно обозначенные placeholders
либо запроси критически важные данные.

## 3. Выбор сущности

Используй следующую таблицу решений.

| Задача | Сущность |
|---|---|
| Обычная публичная страница | `data/content/{lang}/pages/*.mur.md` |
| Вложенный URL | поле `parent` у страницы |
| Редакционная рубрика | `data/content/{lang}/categories/*.mur.md` |
| Назначение страницы в рубрики | поле `categories` |
| Автоматический список дочерних страниц | `template: listing`, `list.source: children` |
| Список страниц рубрики | category document, `list.source: category` |
| Секции главной | `data/content/{lang}/home/*.mur.md` |
| Footer | `data/content/{lang}/footer/*.mur.md` |
| Главное меню | `data/menus/{lang}/main.mur.md` |
| Повторяемый HTML/PHP-фрагмент | snippet |
| Системная вставка в layout | reserved system snippet |
| Карусель, счётчики, логотипы | blocks plugin |
| Галерея с lightbox | gallery plugin |
| Метки, tag pages и облако меток | tags plugin |
| Пользовательские изображения и документы | `root/uploads/` |
| Опциональное поведение | plugin + hooks |

`parent` и `categories` не взаимозаменяемы. `parent` строит дерево URL.
`categories` назначает страницу в одну или несколько рубрик.
Если нужен дополнительный внесистемный способ группировки страниц, используй
поле `tags` и плагин `tags`, а не создавай отдельную структуру файлов.

## 4. Планирование мультиязычности

Для каждой смысловой страницы:

- выбери стабильный `id` внутри языка;
- выбери человекочитаемый `slug`;
- задай одинаковый `translation_key` всем переводам;
- проверь, что ID, URL и translation key не дублируются внутри языка;
- создавай отдельное дерево и меню для каждого языка.

В режиме `flexible` структура языков может различаться. Если перевода нет,
переключатель ведёт на главную выбранного языка, а `hreflang` для
несуществующего перевода не создаётся.

Режим `mirror` пока является настройкой и не создаёт переводы автоматически.
ИИ должен сам проверять матрицу переводов, если проект требует зеркальную
структуру.

## 5. Формат документа

Минимальная страница:

```text
id: about
title: О компании
slug: o-kompanii
status: published
translation_key: about
order: 20
summary: Короткий анонс страницы.
seo.title: О компании - Название сайта
seo.description: Описание результата поиска длиной примерно 120-160 символов.
---

# О компании

Основной текст.
```

Header поддерживает только плоские строки `key: value`. Не записывай YAML
массивы, вложенные объекты или многострочные значения.

Body поддерживает:

- raw HTML;
- простые заголовки `#`, `##`, `###`;
- текстовые абзацы;
- зарегистрированные shortcodes.

Это не полный Markdown. Списки, таблицы, ссылки и сложную разметку надёжнее
писать валидным HTML.

Если включён опциональный плагин MurText, отдельный документ переводится на
него через `parser: murtext`; `parser: native` принудительно оставляет штатный
parser. Не используй MurText-синтаксис, пока не проверил, что плагин включён и
его scope разрешает нужную сущность. Настройки и встроенная справка находятся
в `/admin/plugin/murtext`.

Если `page.header` не равен false, MurCMS сама выводит breadcrumbs, `h1` и
summary. Не создавай второй видимый `h1`. Начинай ручную структуру с `##` либо
положись на автоматическое удаление первого Markdown `#`.

## 6. Listing и категории

Пример страницы-списка:

```text
template: listing
list.source: children
list.view: cards
list.sort: order
list.direction: asc
list.per_page: 12
list.position: bottom
list.show_image: true
list.show_description: true
```

Поддерживаются:

- source: `children`, `category`;
- view: `cards`, `titles`, `feed`;
- sort: `order`, `date`, `updated_at`, `title`, `id`, `filename`;
- direction: `asc`, `desc`;
- `list.limit`;
- `list.per_page` от 1 до 100;
- position: `top`, `bottom`, `shortcode`.

`list.view: titles` — строгий режим: выводятся только ссылки-заголовки.
`list.show_image` и `list.show_description` в нём игнорируются, даже если
остались в старом файле со значением `true`.

Если `list.position: shortcode`, поставь в body отдельную строку:

```text
[list]
```

Глобальные defaults для списков задаются в `root/data/config/theme.php` и в
админке `/admin/template`, отдельно для `children` и `category`. Локальные
поля `list.view`, `list.sort`, `list.direction`, `list.per_page`,
`list.position` у страницы переопределяют глобальные значения.

Карточка берёт описание из `summary`, затем `description`, затем
`seo.description`. Изображение берётся из `image`, `hero.image` или `og.image`.

Встроенная listing-картинка пока не принимает отдельные meta-поля intrinsic
width/height. Используй изображения единого соотношения сторон и стабильный CSS
контейнер. Если проект требует разные точные размеры без layout shift, расширь
renderer осознанно и добавь тест.

## 7. Главная, footer и меню

Порядок и показ home/footer задавай в `/admin/template/home` и
`/admin/template/footer` списками ID — по одному ID на строку. ID равен basename
файла без `.mur.md`:

```text
hero
benefits
gallery
```

Без `block_layout` файлы автоматически не публикуются. Поля `id`, `status`,
`order` и `footer.position` у home/footer-файлов не управляют выводом. В
flexible-режиме списки раздельны по языкам; в mirror-режиме используется один
список основного языка. Если ID нет в текущем языке, CMS ищет файл с тем же
basename в основном языке, поэтому нейтральный блок можно хранить один раз.
Даже во вложенных каталогах ID остаётся basename; не создавай одинаковые имена
в разных подпапках одной области и языка.

В footer layout вставляй меню в точное место отдельной строкой:

```text
footer-main
menu:footer
footer-partners
footer-utility
```

Параметры `footer_menu_enabled`/`footer_menu_name` сохраняются только как
fallback для старых конфигураций. Для нового сайта предпочитай `menu:{name}`.

Меню лежат в `root/data/menus/{lang}/main.mur.md` и
`root/data/menus/{lang}/footer.mur.md`. Редактирование доступно в
`/admin/template`, секция «Меню». Footer menu выводится строкой
`menu:{name}` в footer layout либо через включённый compatibility fallback.

Старый формат меню:

```text
page:about | О компании | О компании | | |
[
page:services | Услуги | Услуги | | |
page:service-a | Услуга A | Услуга A | | |
]
```

Колонки старого формата:

```text
href | label | title | class | span_class | attrs
```

Для верхнего уровня `main` доступна отдельная длинная мобильная подпись:

```text
href | short_label | long_label | title | class | span_class | attrs
```

До 760 px выводится `long_label`, на десктопе — `short_label`. Если длинная
подпись не нужна, сохраняй шестиколоночный формат.

Для подменю открывающая `[` ставится перед родительским пунктом, а `]` — после
последнего дочернего. Не ставь `[` после уже записанного родителя: parser
привязывает группу к следующему пункту.

Предпочитай ссылки `page:id`: они переживают изменение slug. Для внешних ссылок
используй полный HTTPS URL.

У пункта с подменю надпись всегда остается обычной ссылкой, а отдельная кнопка
управляет только раскрытием. Если hub-страница уже стоит первой в подменю,
родителю можно оставить `#`: renderer автоматически возьмет URL первой дочерней
ссылки для desktop и mobile.

Вложенность 3+ задаётся повторными парами `[`/`]`. Для мегаменю присвой
верхнему пункту класс `menu-mega`, а колонки оформи вложенными группами:

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

На desktop обычный третий уровень открывается flyout, мегаменю раскладывает
группы в колонки; на mobile оба варианта становятся многоуровневым accordion.

## 8. Snippets и системные места

Обычный вызов:

```text
[snippet=cta]
```

Поиск идёт в порядке:

1. текущий язык;
2. global;
3. основной язык.

Существующий draft останавливает fallback и ничего не выводит.

Системные имена:

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

Используй их для аналитики, verification tags, баннеров и общих вставок. Не
редактируй `Renderer` ради кода, который уже помещается в системный snippet.

PHP snippets допустимы только для доверенного кода. Если PHP не нужен, отключи
`allow_php`. Никогда не вставляй в snippet непроверенный пользовательский ввод.

## 9. Готовые блоки

Shortcodes:

```text
[carousel=services]
[counters=site]
[logos=partners]
[gallery=portfolio]
[tagcloud]
```

Carousel, counters и gallery подключают JS только при фактическом
использовании. Logos и tagcloud не требуют JS на публичной части.

Форматы строк:

```text
carousel: title | description | url | image | alt
counters: value | label | prefix | suffix
logos: image | alt | url | width | height | link_title | target
gallery: full | thumb | alt | caption | credit | thumb_width | thumb_height
```

У `logos` поле `link_title` относится к ссылке и не заменяет `alt` изображения.
`target` указывай как `_blank`, `_self`, `_parent`, `_top` или безопасное имя
окна; для `_blank` CMS сама добавляет `rel="noopener"`.

Для gallery готовь отдельные thumbnail и full-size файлы. Не используй один
огромный файл как миниатюру.

Метки задаются в meta страницы:

```text
tags: seo, pagespeed, cms
```

Публичные страницы меток доступны как `/{lang}/tag/{slug}`. Облако выводится
shortcode `[tagcloud]`; параметры: `max`, `min_count`, `show_counts`, `sort`,
`order`. Не заводи отдельную базу тегов вручную: плагин собирает ее из страниц.
SEO tag-страниц генерируется из шаблонов `root/data/config/tags.php`. Если
проекту нужны другие формулы title/description, меняй массивы
`seo.title_templates` и `seo.description_templates`, используя плейсхолдеры
`{tag}`, `{site}`, `{count}`. Не делай runtime-random для meta: один URL должен
стабильно отдавать один и тот же title/description.

## 10. Assets и дизайн

Публичные пути:

```text
/murcms/templates/default/assets/...  assets активного frontend-шаблона
/murcms/assets/...                    admin/plugin assets
/uploads/...                          данные конкретного сайта
```

Не используй несуществующий `/assets/...`.

Правила:

- контентные изображения размещай в `uploads`;
- задавай `width`, `height`, `alt`, `loading` и `decoding`;
- первый значимый hero image не делай lazy;
- не подключай jQuery и тяжёлый UI framework;
- CSS сетки и размеры подключай обычным stylesheet;
- JS подключай с `defer` и только там, где он нужен;
- учитывай keyboard, focus, reduced motion и мобильную ширину;
- расширяй SVG sprite активного шаблона вместо внешнего icon font.

Текущая сборка имеет frontend-шаблон `root/murcms/templates/default/`. Всегда
различай два сценария.

**Разработка reusable template:** если меняется сам распространяемый шаблон MurCMS,
редактируй `root/murcms/templates/{template}/...`, включая его собственные
`assets/critical.css`, `assets/app.css` и `themes/{scheme}/...`.

**Оформление конкретного сайта:** сначала используй контент, snippets, блоки,
`uploads/` и update-safe CSS активного шаблона:

```text
root/uploads/murcms-custom/{template}/critical.css
root/uploads/murcms-custom/{template}/custom.css
```

Эти два файла редактируются в `/admin/template` (`Template → General`). Custom Critical
CSS инлайнится после template/theme critical layer; Additional CSS подключается последним
stylesheet. Не записывай client-specific оформление в distributable template только потому,
что правило относится к первому экрану: иначе update шаблона смешивается с настройками сайта.

Для селекторов публичного сайта используй kebab-case с одним дефисом:
`page-content`, `content-list-item`, `button-ghost`. Не вводи BEM-разделители
`__` и `--`; двойной дефис допустим только в CSS custom properties.
Scope цветовой схемы имеет формат `.ts-{scheme}`, например
`.ts-memorial-elegant`.

Текст документа находится в `.page-content` внутри `.page-article`. Стили
типографики страницы ограничивай этим контейнером, чтобы не затрагивать header,
footer, домашние блоки и интерфейсные компоненты.

Цветовые схемы активного шаблона хранятся в
`root/murcms/templates/{template}/themes/{scheme}/`. Для добавления схемы
создай `critical.css` и `theme.css`; PHP и ручное добавление пункта в админку
не нужны. Правила схемы, необходимые для первого экрана, помещай в
`critical.css`, остальные — в `theme.css`. Не используй отложенную загрузку
layout CSS через `media=print`.

Базовая иконка CMS находится в `root/murcms/assets/site-icon.svg`. Default
template использует её в HTML-brand и как fallback favicon. Favicon конкретного
сайта настраивай в `/admin/settings/languages`: ручной `favicon_frontend` имеет
приоритет, затем CMS ищет только в корне `root/uploads/` первый по имени
`favicon*.png`, `favicon*.svg` или `favicon*.webp`, затем использует стандартную
иконку. В подкаталог автоматический поиск не заходит, но ручной путь может
указывать подкаталог. `favicon_admin` настраивается в `/admin/settings/admin` и задаёт отдельную иконку админки; пустое
значение оставляет стандартную MurCMS-иконку.

## 11. Когда менять код и когда нужен дополнительный reference

Для site-specific требования сначала попробуй, по порядку:

1. meta и `.mur.md`;
2. существующий shortcode или штатный block/gallery/tags mechanism;
3. snippet;
4. update-safe CSS и media;
5. уже существующий bundled plugin, если его поведение описано в `current-reference.md`.

Не проектируй новый plugin или новый hook по этой инструкции. Если требование действительно требует изменения/создания плагина, регистрации нового admin route, работы с hook contract или другого Plugin API-level изменения, минимальный двухфайловый комплект заканчивается: добавь канонический `development/plugin-api.md` и при необходимости документацию конкретного плагина. Не используй внутренние методы core как замену публичному API.

Core меняй только для общего поведения CMS: routing, document model, security, общий renderer или admin contract. Не добавляй в core логику одного клиента только ради конкретного сайта.

Если всё же выполняется code-level write/edit functionality, она должна учитывать применимый security/runtime contract MurCMS: role enforcement, CSRF для изменяющих admin operations, validation, безопасные пути, корректную запись и защиту от потери данных. Точные Plugin API contracts не дублируются здесь и должны браться из профильного reference.

## 12. Рабочий процесс ИИ

1. Проверь фактическое рабочее дерево и не перезаписывай несвязанные изменения.
2. Сверь версию/структуру CMS с `current-reference.md`.
3. Составь sitemap и матрицу языков.
4. Зафиксируй ID, slug, parent, categories и translation keys.
5. Подготовь site/language config без секретов.
6. Создай контентные файлы, меню, home и footer.
7. Добавь media, galleries, blocks и snippets.
8. Настрой дизайн существующими классами и update-safe CSS.
9. Проверь внутренние ссылки, URL и asset paths.
10. Если менялся код, выполни доступный lint/tests; если менялся только контент/config/CSS, проверь соответствующие файлы и runtime behavior.
11. Открой ключевые страницы в браузере на desktop и mobile, если среда позволяет.
12. Выполни встроенный checklist из следующего раздела. Для полноценного release/deployment, когда доступен `prerelease-checklist.md`, дополнительно пройди его; он не требуется для каждой обычной site-building правки.

## 13. Встроенный checklist стандартной site-building задачи

Этот раздел является достаточным базовым checklist для автономного двухфайлового комплекта. Не требуется искать отдельный checklist для обычной работы над сайтом.

### Структура и данные

- каждый созданный `.mur.md` имеет уникальные в своей области ID/slug и корректный статус;
- `parent`, `categories`, `translation_key` и listing fields используются по назначению;
- меню ссылаются на существующие страницы или осознанные внешние URL;
- home/footer layout содержит существующие ID и не полагается на случайный filesystem order;
- site-specific mutable settings находятся в runtime config/data, а не внутри distributable core/plugin/template code;
- secrets и credentials не попали в distributable/default files.

### Мультиязычность и SEO

- открывается главная каждого включённого языка;
- существующие переводы имеют согласованный `translation_key`;
- отсутствие перевода обрабатывается согласно текущей language model, а не фиктивной страницей;
- проверены title/description для ключевых страниц;
- canonical/hreflang/robots/sitemap не противоречат фактическим URL;
- 404 для несуществующего URL работает корректно.

### Frontend и media

- внутренние ссылки и asset paths не ведут на несуществующие файлы;
- изображения имеют корректные `alt` и, где применимо, intrinsic `width`/`height`;
- первый значимый hero asset не загружается лениво без причины;
- client-specific CSS находится в update-safe custom layer, если задача не является разработкой reusable template;
- desktop и mobile layout не имеют очевидных переполнений, сломанных меню или недоступных интерактивных элементов;
- gallery/carousel/blocks проверены только там, где они реально используются.

### Admin и runtime

Если задача затрагивала административные данные или workflows, проверь соответствующий сценарий через реальный UI: login, сохранение формы, отображение результата и отказ для недопустимого действия/роли там, где это относится к задаче. Не заявляй полную проверку всей admin-панели, если проверялся только один workflow.

### Технические проверки

Если работа ведётся в полном repository и соответствующие scripts/tests реально присутствуют, запускай их по repository instructions. В root/documentation package такие инструменты не гарантируются, поэтому не придумывай отсутствующую команду.

Минимум:

- изменённые PHP-файлы → `php -l`;
- изменённые JS-файлы → `node --check`, если Node доступен;
- Markdown/config/content → проверка синтаксиса/структуры и ссылок по фактическому формату;
- runtime behavior → browser smoke на доступной целевой среде.

Для полноценного production release дополнительно требуется профильный серверный/release checklist. Отсутствие такого прохода означает, что результат нельзя объявлять полностью production-ready.

## 14. Формат результата работы

В конце сообщи:

- какие страницы, языки, меню и блоки созданы или изменены;
- какие runtime/config/CSS/media файлы изменены;
- были ли изменения core, template code или plugins;
- какие проверки фактически выполнены и с каким результатом;
- какие реальные данные всё ещё нужны;
- какие ограничения, непроверенные части или server gates остаются;
- потребовался ли выход за автономный site-building комплект и какой дополнительный reference использовался.

Не объявляй сайт production-ready, если не выполнялся полноценный production smoke и серверный release/deployment check.
