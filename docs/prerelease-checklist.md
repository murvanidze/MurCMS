# MurCMS release and deployment checklist

Дата: 2026-09-07
Статус: обязательный выпускной контроль

## Назначение

Этот checklist проверяет конкретный release/install environment. Он не хранит состояние одной локальной машины и не заменяет [`installation-update.md`](installation-update.md).

## 1. Проверка дистрибутива

- Профиль конкретного дистрибутива известен и не содержит локальных журналов, временных файлов, рабочих секретов и старых архивов.
- Root-only server package содержит только server tree `root/`; documentation package может распространяться отдельно.
- Build/test scripts считаются repository tooling только если они реально присутствуют в полном repository.
- В `root/data/config/` присутствуют `*.dist.php`.
- Рабочие `*.php` конфиги конкретного сайта не заменяются при обновлении.
- Document root веб-сервера указывает на `root/`.

## 2. Обязательное окружение

- PHP 8.5 или новее.
- `mbstring`.
- `session`.
- `json`.
- `zip` / `ZipArchive` для штатных ZIP-функций админки.
- GD с `imagewebp()` либо Imagick для WebP-конвертации.
- Apache 2.4 с `mod_rewrite`.
- Разрешён `AllowOverride` для чтения `.htaccess`.
- Рекомендуются `mod_headers`, `mod_expires`, `mod_deflate`, OPcache и Imagick.

Если полный repository содержит штатный production smoke, он должен пройти без ошибок. В root/documentation package наличие `tests/smoke.php` не гарантируется. Независимо от repository tooling проверьте PHP/extensions фактически на целевом сервере.

## 3. Production-конфигурация

В `root/data/config/site.php`:

```php
'base_url' => 'https://example.com',
'environment' => 'production',
```

Также проверяются:

- корректный часовой пояс;
- основной и включённые языки;
- список включённых плагинов;
- политика `snippets.allow_php`;
- реальные пользователи и e-mail;
- отсутствие общей starter/distribution учётной записи и уникальный пароль
  `super_admin` для конкретной установки.

Если PHP snippets не нужны, безопаснее установить:

```php
'allow_php' => false,
```

## 4. Права и защита

Веб-сервер читает весь `root/` и пишет только в рабочие каталоги:

```text
root/data/config/
root/data/content/
root/data/blocks/
root/data/galleries/
root/data/menus/
root/data/snippets/
root/data/storage/
root/uploads/
root/.htaccess
```

Проверить через HTTP:

- `.mur.md`, `.md`, `.bak`, `.log`, `.dist.php` не отдаются;
- `/data/`, `/murcms/app/`, `/murcms/admin/`, `/murcms/plugins/` закрыты;
- PHP и скрипты внутри `/uploads/` не выполняются;
- загружаемое изображение проверяется по фактическому формату, а не только по
  расширению имени;
- листинг каталогов выключен;
- `/admin/` без сессии ведёт на login.

Не использовать права `0777`.

## 5. Функциональная проверка

- Главные страницы всех включённых языков.
- Обычная, вложенная и listing-страница.
- Категория и назначенные ей страницы.
- Пагинация, включая `?page=2`.
- Меню, короткая/длинная подпись верхнего уровня, обычный третий уровень и
  `menu-mega` на desktop/mobile.
- Ordered layouts главной и футера, fallback блока из основного языка и точная
  позиция `menu:footer`.
- Listing `titles` без изображений и описаний, даже при старых
  `list.show_*: true`.
- Переключатель языка с переводом и без перевода.
- Gallery, carousel, counters, logos.
- Обычные и системные snippets.
- `robots.txt` и `sitemap.xml`.
- 404 на неизвестном URL и черновике.

## 6. Проверка админки

- Вход пользователями `super_admin`, `admin` и `editor`.
- `editor` получает 403 в admin-only разделах, но сохраняет доступ к страницам,
  категориям, меткам, блокам и Media Manager.
- `admin` может создавать, редактировать, отключать, менять пароль и удалять
  только редакторов.
- `super_admin` может управлять администраторами и редакторами; создать или
  удалить второго супер-администратора через UI нельзя.
- Группа «Шаблон» идёт после «Настройки» и содержит отдельные страницы общих
  настроек, главной и футера.
- `Content → Blocks` отсутствует; Blocks и Gallery доступны только через admin
  routes соответствующих включённых плагинов.
- На всех admin pages breadcrumbs строятся из sidebar group/item metadata;
  группы без собственного route остаются текстом.
- Toolbar действий в однотипных таблицах не переносится на desktop; узкие
  таблицы остаются доступны через горизонтальную прокрутку.
- Вложенность категории смещает единый блок title/ID/URL, но не Status,
  Actions или checkbox.
- Dashboard компактно показывает число страниц, блоков главной, блоков футера,
  URL в sitemap и активных плагинов.
- Фильтр списка страниц выбирает язык, рубрику или прямого родителя и независимо
  комбинируется со статусом; сброс убирает фильтры.
- Список страниц переключается между режимами «Все», 50 и 10; пагинация общая
  для языковых веток и сохраняет фильтры, а каждая строка имеет чекбокс выбора.
- Групповые операции страниц скачивают ZIP, меняют статус, назначают допустимого
  родителя, добавляют рубрику и создают ZIP-backup перед подтверждённым удалением.
- Создание, сохранение, клонирование и удаление тестовой страницы.
- Удаление страницы и категории подтверждается `Y`, `y` или `1`, а не ID.
- Сохранение `seo.*`, `list.*` и `page.header` через реальный HTTP POST.
- Проверка двух резервных версий после повторного сохранения.
- Запрет дублирующего ID, URL и translation key.
- Запрет цикла родителей и удаления используемой категории.
- Смена пароля с проверкой старого пароля.
- Управляемая смена пароля прекращает действие старой сессии пользователя.
- Загрузка WebP и конвертация JPEG/PNG в WebP.
- При отключённой опции WebP JPEG/PNG/GIF сохраняются в исходном формате.
- В галерее и списке Media Manager работают чекбоксы, «Выделить все», инверсия,
  режимы «Все»/50/10, список URL, ZIP-скачивание, перемещение и подтверждённое
  удаление выбранных файлов.
- Создание папки, скачивание и удаление файла.
- Создание каталога и блока home/footer, дублирование во вложенный путь,
  сохранение порядка и проверка отсутствия legacy `id/status/order`.
- Upload одного `.mur.md` с явным target language/scope, лимитом 2 MB,
  безопасным basename и суффиксами `-1`, `-2` без перезаписи.
- Replace `.mur.md` через picker и drag-and-drop с сохранением исходного
  filename, backup перед записью и неизменностью исходника при ошибке.
- Подсказки pipe-синтаксиса в редакторах menu, carousel, counters, logos и
  gallery.
- Brand в режимах site name, HTML и image; размеры image-brand не меняются при
  промежуточной ширине viewport.
- Ручные frontend/admin favicons из `uploads`; frontend auto-discovery принимает
  только `favicon*.png|svg|webp` в корне uploads и корректно возвращается к
  стандартной MurCMS-иконке.
- Frontend admin bar виден с активной admin-сессией, содержит корректную ссылку
  редактирования/метаданные и отсутствует без admin cookie.
- Если включён MurText `0.2.3`: его document overrides, toolbar, update-safe `data/config/murtext.php` и сохранение обычной формы редактора без вложенных `<form>`.
- Root-only package MurText содержит ровно одну пару admin assets в `murcms/assets/plugins/murtext/`; каталог `murcms/plugins/murtext/assets/` отсутствует.
- Если включён MurConsent `1.0.3`: `/admin/plugin/murconsent` доступен роли `admin` и выше; до согласия настроенные необязательные Google/Flag Counter resources не загружаются.
- Проверить authorization regression для plugin admin routes по каноническому
  [`Plugin API`](development/plugin-api.md), включая прямой переход по URL с
  недостаточной ролью.
- Сохранение robots, sitemap snapshot и `.htaccess` с backup.
- Registry snippets содержит 11 системных позиций, показывает точный scope и
  fallback и не создаёт файл до Save.
- Custom Critical CSS и Additional CSS сохраняются отдельно для активного
  шаблона в `uploads/murcms-custom/{template}/`, соблюдают лимиты 64/256 KB и
  удаляются при сохранении пустого поля.

## 7. Качество фронтенда

- Нет 404 у CSS, JS, изображений, SVG и шрифтов.
- Изображения имеют размеры или стабильный контейнер.
- Нет заметного layout shift.
- В `<head>` инлайнится template/theme critical CSS, а `app.css` и
  `themes/{scheme}/theme.css` загружаются обычными stylesheet.
- После `layout.head` инлайнится непустой custom Critical CSS, затем последним
  stylesheet подключается непустой `custom.css` с `mtime` cache-busting.
- Body использует scope `ts-{scheme}`; все установленные схемы имеют
  `critical.css` и `theme.css`.
- Управление доступно с клавиатуры.
- Проверены focus-состояния, Esc, стрелки и reduced motion.
- Lighthouse/PageSpeed проверены минимум для главной, listing и страницы с
  галереей.
- На мобильной ширине нет горизонтального переполнения.

## 8. Резервная копия и обновление

Перед обновлением сохранить:

```text
root/data/
root/uploads/
root/.htaccess
```

Обновление сначала проверяется на staging. Код и assets можно заменять новой
версией, но данные сайта и рабочие конфиги не перезаписываются. Новые параметры
из `*.dist.php` переносятся в рабочие конфиги осознанно либо через штатный admin UI. Mutable plugin config остаётся в `data/config/`; site-specific CSS в `uploads/murcms-custom/` сохраняется при обновлении.

## 9. Согласованность документации

Перед финальной сборкой release/documentation package выполните лёгкие anti-drift проверки из
[`development/versioning-release.md`](development/versioning-release.md): canonical CMS/plugin versions,
внутренние Markdown-ссылки, критичные distribution defaults/contracts, package hygiene и отсутствие
machine-specific результатов в current docs. Эти проверки должны оставаться быстрыми и не заменяют
ручную редакционную проверку или runtime smoke.

## 10. Известные границы текущей версии

Сейчас отсутствуют:

- web-установщик и автоматический updater;
- формы обратной связи и почтовый слой;
- поиск;
- штатный CSV-каталог;
- статическая генерация и полноценный cache layer;
- UI для JSON-LD;
- автоматическое enforcement режима `mirror`;
- полный Markdown;
- полноценные HTTP-регрессионные тесты всех admin POST-сценариев;
- несколько полноценных независимых frontend-шаблонов и миграция настроек между ними.

Эти ограничения не мешают пробным корпоративным и информационным сайтам, если
они явно учтены в проекте.
