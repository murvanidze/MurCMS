# MurCMS documentation map

Дата: 2026-09-07  
Статус: актуально для MurCMS `0.4.1`

Этот каталог содержит текущую документацию MurCMS, документацию штатных плагинов,
release notes и отдельно изолированные исторические материалы. При расхождении текста
с текущим кодом приоритет имеет фактическая реализация; затем должен быть исправлен
соответствующий канонический документ.

## Канонические документы

| Тема | Source of truth |
|---|---|
| Текущее устройство и поведение MurCMS | [`current-reference.md`](current-reference.md) |
| Установка и обновление | [`installation-update.md`](installation-update.md) |
| Сборка конкретного сайта с помощью ИИ | [`ai-site-building-guide.md`](ai-site-building-guide.md) |
| Выпуск и серверная проверка | [`prerelease-checklist.md`](prerelease-checklist.md) |
| Пользователи и роли | [`admin/users.md`](admin/users.md) |
| Редактирование контента | [`admin/content.md`](admin/content.md) |
| Media Manager | [`admin/media.md`](admin/media.md) |
| Языки и admin i18n | [`admin/languages.md`](admin/languages.md) |
| SEO и snippets | [`admin/seo-snippets.md`](admin/seo-snippets.md) |
| Plugin API | [`development/plugin-api.md`](development/plugin-api.md) |
| Версия CMS, release process и лёгкие anti-drift проверки документации | [`development/versioning-release.md`](development/versioning-release.md) |
| Документация штатных плагинов | [`plugins/README.md`](plugins/README.md) |

`development/plugin-api.md` является единственным каноническим developer reference по Plugin API.
Остальные current-документы могут называть конкретный hook или Plugin API как часть
поведения подсистемы/плагина, но не определяют его сигнатуры, registration contract,
role semantics или backward compatibility.

## Релизы

- [`releases/0.4.1.md`](releases/0.4.1.md) — текущий стабильный релиз;
- [`releases/0.4.0.md`](releases/0.4.0.md) — исторический стабильный snapshot;
- [`releases/0.1.0-rc.1.md`](releases/0.1.0-rc.1.md) — исторический prerelease snapshot.

Release notes описывают конкретный выпущенный snapshot и не должны использоваться вместо
current reference.

## Исторические материалы

В [`archive/`](archive/README.md) сохранён только исходный рабочий brief/TZ проекта:

- `archive/design/technical-brief.md`.

Он служит историческим контекстом замысла и не является описанием текущего API или поведения MurCMS. Устаревший архитектурный черновик и research/source inventory исключены из documentation package, чтобы не смешивать старые проектные решения с current source of truth.

Site-specific operational notes, obsolete audits, comparison artifacts и устаревшие test reports также не входят в current documentation package.

## Быстрый маршрут

- **Минимальный автономный AI-комплект для стандартной сборки сайта:** [`ai-site-building-guide.md`](ai-site-building-guide.md) + [`current-reference.md`](current-reference.md). Этих двух файлов достаточно для обычной работы с контентом, языками, меню, штатными блоками, media, SEO и site-specific оформлением без разработки нового Plugin API.
- Новая установка или обновление: дополнительно [`installation-update.md`](installation-update.md); для полноценного release/deployment — [`prerelease-checklist.md`](prerelease-checklist.md).
- Глубокая работа с конкретной admin-подсистемой: профильный документ из `admin/`.
- Разработка или изменение плагина/Plugin API: [`development/plugin-api.md`](development/plugin-api.md) и документация конкретного plugin при необходимости.
- Работа с ролями: [`admin/users.md`](admin/users.md).
- Проверка версии и подготовка release notes: [`development/versioning-release.md`](development/versioning-release.md).
