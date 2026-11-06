# MurCMS plugins documentation

Дата: 2026-09-07  
Статус: current plugin documentation map

Общий программный контракт для разработки plugins находится в
[`../development/plugin-api.md`](../development/plugin-api.md). Этот каталог описывает поведение и
настройку конкретных штатных plugins и не заменяет Plugin API reference.

## MurConsent

- [`murconsent.md`](murconsent.md) — возможности и runtime behavior MurConsent `1.0.3`;
- [`murconsent-installation.md`](murconsent-installation.md) — установка, update и migration;
- [`murconsent-third-party.md`](murconsent-third-party.md) — сторонние runtime-компоненты и внешние сервисы.


## MurText

Каталог [`murtext/`](murtext/) содержит документацию integration plugin `0.2.4` и parser core `0.2.0`:

- [`murtext/README_RU.md`](murtext/README_RU.md) — plugin integration/update model;
- [`murtext/CORE_README_RU.md`](murtext/CORE_README_RU.md) — parser core;
- [`murtext/AI_INSTRUCTION_RU.md`](murtext/AI_INSTRUCTION_RU.md) — инструкция по подготовке MurText-контента;
- дополнительные packaging notes сохранены рядом как version-specific reference.

## Banerina

- [`banerina.md`](banerina.md) — управляемый выбор и ротация существующих
  snippets через shortcode `[promo=...]`, admin UI и update-safe config.

## Остальные bundled plugins

`blocks`, `gallery`, `redirects`, `snippets` и `tags` документируются в текущем системном reference и
профильных admin-документах. Их общие hooks/routes/config conventions принадлежат
`development/plugin-api.md`.

## BerryMur

- [`berrymur.md`](berrymur.md) — BerryMur `1.0.0`, локальная отложенная Berry CSS.

Стандартная поставка 0.4.2 содержит восемь плагинов: snippets, blocks, gallery, tags, redirects, murconsent, murtext, berrymur. Banerina 0.1.1 устанавливается отдельно. Версии и baseline описаны в [`../development/versioning-release.md`](../development/versioning-release.md).
