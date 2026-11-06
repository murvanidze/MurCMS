# MurText 0.2.4: состав поставок

MurText 0.2.4 — текущая версия интеграционного слоя MurText в MurCMS 0.4.2.

Версии:

- Plugin: `0.2.4`;
- Parser core: `0.2.0`;
- Dialect: `murtext-1`.

## Runtime paths

Канонические runtime-компоненты:

```text
root/murcms/plugins/murtext/
root/murcms/assets/plugins/murtext/
root/data/config/murtext.dist.php
```

Изменяемый конфиг конкретного сайта создаётся и обновляется только по пути:

```text
root/data/config/murtext.php
```

Legacy-файл `root/murcms/plugins/murtext/config.php` не входит в новую поставку и поддерживается
только как источник автоматической миграции старой установки.

## Текущее состояние packaging в MurCMS 0.4.2

MurText 0.2.4 использует одну каноническую пару admin assets:

```text
root/murcms/assets/plugins/murtext/admin.css
root/murcms/assets/plugins/murtext/admin.js
```

Каталог `root/murcms/plugins/murtext/assets/` удалён и не входит в root-only package. Runtime и
упаковка не должны поддерживать вторую tracked-копию этих файлов. Regression-проверка package/build
должна подтверждать отсутствие plugin-local дубля и наличие ровно одной пары MurText admin assets.

## Documentation package

Документация MurText входит в общий пакет документации MurCMS:

```text
docs/plugins/murtext/
├── README_RU.md
├── CORE_README_RU.md
├── AI_INSTRUCTION_RU.md
├── README_DOCS_ARCHIVE_RU.md
└── RELEASE_PACKAGE_RU.md
```

Runtime-плагин от этих файлов не зависит.

Пакет MurText-0.2.4-update.zip дополнительно доставляет read-only копию
канонического CORE_README_RU.md в root/murcms/plugins/murtext/help/. Она
сверяется по хешу с docs/plugins/murtext/CORE_README_RU.md и открывается через
/admin/plugin/murtext/help только роли admin и выше. Поэтому полная справка
работает даже без распаковки отдельного docs.zip. Прямая HTTP-раздача plugin/help
не разрешается. Краткая справка helpContent() общая для страницы и dialog.

## Source/dev package

Source/dev package может содержать tests и другие development artifacts. Их наличие не является
требованием production root.

## Обновление старых установок

Если старая установка содержит `root/murcms/plugins/murtext/config.php`, не удаляйте этот файл до
первого успешного перехода на update-safe config. При отсутствии `root/data/config/murtext.php`
плагин может прочитать legacy config, нормализовать его и создать новый runtime config.

Если старый plugin directory удаляется целиком до первого запуска новой версии, сначала перенесите
нужные настройки в `root/data/config/murtext.php` вручную.
