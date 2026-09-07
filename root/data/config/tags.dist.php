<?php
declare(strict_types=1);

if (!defined('MUR_CMS_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('No direct script access allowed.');
}

return [
    'cloud' => [
        'max' => 40,
        'min_count' => 1,
        'show_counts' => true,
        'sort' => 'name',
        'order' => 'asc',
    ],
    'pages' => [
        'per_page' => 20,
    ],
    'seo' => [
        'title_templates' => [
            'ru' => [
                '{tag} — материалы и страницы | {site}',
                'Все материалы по теме «{tag}» | {site}',
                'Метка «{tag}»: подборка страниц | {site}',
                '{tag}: статьи, услуги и материалы | {site}',
                'Страницы с меткой «{tag}» | {site}',
            ],
            'ro' => [
                '{tag} — articole si pagini | {site}',
                'Toate materialele despre {tag} | {site}',
                'Eticheta «{tag}»: selectie de pagini | {site}',
                '{tag}: servicii, articole si materiale | {site}',
                'Pagini cu eticheta «{tag}» | {site}',
            ],
            'default' => [
                '{tag} — pages and articles | {site}',
                'All materials about {tag} | {site}',
                'Tag «{tag}»: selected pages | {site}',
                '{tag}: articles, services and materials | {site}',
                'Pages tagged «{tag}» | {site}',
            ],
        ],
        'description_templates' => [
            'ru' => [
                'Подборка материалов сайта {site} по теме «{tag}»: страницы, услуги, кейсы и справочная информация. Найдено материалов: {count}.',
                'Все опубликованные страницы с меткой «{tag}» на сайте {site}. Удобная тематическая подборка материалов и разделов.',
                'Раздел метки «{tag}» объединяет связанные материалы сайта {site}: статьи, страницы услуг, портфолио и полезные заметки.',
                'Материалы по теме «{tag}» на сайте {site}: смотрите страницы, связанные разделы и дополнительные материалы в одной подборке.',
                'Страницы с меткой «{tag}»: тематическая навигация по опубликованным материалам сайта {site}.',
            ],
            'ro' => [
                'Selectie de materiale pe tema «{tag}» pe site-ul {site}: pagini, servicii, proiecte si informatii utile. Total materiale: {count}.',
                'Toate paginile publicate cu eticheta «{tag}» pe site-ul {site}. O selectie tematica pentru navigare rapida.',
                'Eticheta «{tag}» grupeaza materiale relevante de pe site-ul {site}: articole, servicii, portofoliu si note utile.',
                'Materiale despre «{tag}» pe site-ul {site}: pagini conexe, sectiuni si informatii suplimentare intr-o singura lista.',
                'Pagini cu eticheta «{tag}»: navigare tematica prin materialele publicate pe site-ul {site}.',
            ],
            'default' => [
                'A selection of materials about «{tag}» on {site}: pages, services, cases and useful information. Total items: {count}.',
                'All published pages tagged «{tag}» on {site}. A focused topic page for faster navigation.',
                'The «{tag}» tag groups related materials on {site}: articles, service pages, portfolio items and notes.',
                'Materials about «{tag}» on {site}: related pages, sections and additional information in one list.',
                'Pages tagged «{tag}»: topic navigation across published materials on {site}.',
            ],
        ],
    ],
    'sitemap' => true,
    'show_document_tags' => true,
];
