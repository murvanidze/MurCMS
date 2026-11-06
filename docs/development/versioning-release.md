# MurCMS versioning and release process

Дата: 2026-09-07  
Статус: канонический versioning reference для MurCMS `0.4.2`

## Canonical CMS version

Единственный source of truth версии MurCMS находится в core:

```text
root/murcms/app/version.php
```

Текущая версия:

```text
0.4.2
```

Bootstrap загружает это значение и публикует его как `MUR_CMS_VERSION`; Dashboard показывает ту же
версию. Документация и release tooling должны сверяться с этим источником, а не поддерживать
независимую «текущую версию» в нескольких местах.

## Plugin versions

Версии штатных и сторонних plugins независимы от версии MurCMS. Например, текущий MurConsent имеет
версию `1.0.3`, а MurText integration plugin — `0.2.4` при parser core `0.2.0`.

Если плагин имеет собственный canonical version constant/class, его документация должна ссылаться на
эту версию. Не следует выводить версию плагина из версии CMS.

## Release snapshot

Файл в `docs/releases/` описывает конкретный выпущенный snapshot. После выпуска его содержательная
история должна считаться immutable: новые функции следующего состояния кода не дописываются задним
числом в старый release note.

Допустимы только технические исправления навигации документации, например изменение относительной
ссылки после перемещения файла, без переписывания истории самого релиза.

## Development state и release state

Нельзя использовать один и тот же release identifier для постоянно меняющегося development state.
Перед публикацией версии:

1. canonical version в `murcms/app/version.php` соответствует планируемому release;
2. current documentation описывает фактический код;
3. release note создан для этого snapshot;
4. выполнен `prerelease-checklist.md` на целевой среде;
5. после публикации release note больше не превращается в current reference.

## Breaking changes

Изменение считается потенциально breaking, если существующий корректный сайт/plugin после update
требует ручной переделки публичного контракта: URL, формата content/config, role semantics, Plugin API,
обязательного runtime layout или другого задокументированного interface. Такие изменения должны быть
явно отражены в release notes и migration instructions.

## Lightweight documentation drift checks

Чтобы current documentation не расходилась с кодом между крупными аудитами, перед изменением
current version/release и перед сборкой documentation package выполняется небольшой набор дешёвых
проверок. Они должны быть детерминированными и не требовать LLM, browser automation или полного
HTTP regression suite.

Минимальный набор:

1. **CMS version** — значение из `root/murcms/app/version.php` совпадает с current version,
   указанной в active documentation и текущем release note. Historical release notes исключаются.
2. **Bundled plugin versions** — current plugin docs сверяются с их canonical version constants/files;
   версия integration layer не смешивается с версией embedded parser/library.
3. **Document links** — все относительные Markdown-ссылки внутри active documentation ведут на
   существующие файлы; ссылки из fenced code examples могут проверяться отдельно.
4. **Stable defaults/contracts** — несколько критичных машинно-проверяемых фактов сверяются с кодом:
   current role names, безопасный distribution default `snippets.allow_php=false`, update-safe mutable
   plugin config в `data/config/`, отсутствие возвращённого duplicate MurText asset tree.
5. **Package hygiene** — в active documentation package не возвращаются удалённые operational,
   obsolete audit/comparison/test-report/research artifacts; historical brief остаётся только в `archive/`.
6. **No machine-state prose** — current reference/checklists не должны фиксировать результат одной
   конкретной локальной машины (`extension missing`, `smoke failed locally` и т. п.) как состояние продукта.
7. **Repository-tooling boundary** — документация может ссылаться на `scripts/`/`tests/`, существующие
   в полном source repository, но не должна утверждать, что они входят в root-only server package.

Эти проверки являются требованиями к сопровождению документации. Когда documentation package
находится внутри полного repository, рекомендуется реализовать их одним быстрым script/CI job и
запускать вместе с обычными repository checks. Скрипт должен только обнаруживать drift и завершаться
ошибкой с понятным сообщением; он не должен автоматически переписывать документацию.

Если машинная проверка обнаружила расхождение, приоритет определения факта имеет canonical code source,
а затем вручную обновляется соответствующий source-of-truth document.

## Metadata baseline 0.4.2

В каталоге каждого плагина находится расширяемый info.json: name, version, description; author, website, license факультативны. Менеджер читает JSON без исполнения plugin.php; отсутствующий, повреждённый, слишком большой или нестроковый metadata безопасно игнорируется. ID берётся из каталога.

Git 41589d6 и текущие docs не подтверждают прежние независимые версии blocks, gallery, redirects, snippets, tags. Для blocks/gallery/redirects/tags впервые документирован baseline 1.0.0 в выпуске CMS 0.4.2; это назначение, а не утверждение об их прошлых релизах. Для Snippets принят baseline 1.0.0 до расширения API, а текущие snippets.render/registry и проверка языка документированы как 1.1.0. MurConsent остаётся 1.0.3, MurText integration 0.2.4 / core 0.2.0, BerryMur 1.0.0. Проверенная исходная Banerina имела VERSION 0.1.0; UI patch повышает её до 0.1.1.

Сборка полного релиза: scripts/build-release.ps1 -Commit HEAD. Скрипт требует чистых tracked файлов, экспортирует зафиксированный commit через git archive и строит все пакеты из snapshot, затем проверяет manifests и SHA-256. Локальные файлы, включая рабочие конфиги и site functions.php, в snapshot не включаются.
