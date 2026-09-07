<?php
declare(strict_types=1);

if (!defined('MUR_CMS_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('No direct script access allowed.');
}

return array(
    'banner_title' => 'Confidențialitatea dumneavoastră',
    'banner_text' => 'Acest site utilizează servicii opționale de analiză, măsurare a conversiilor publicitare și statistică externă. Acestea nu sunt încărcate înainte de acordul dumneavoastră. Puteți accepta toate serviciile opționale, le puteți refuza sau puteți configura separat categoriile.',
    'accept_all' => 'Acceptă toate',
    'reject_all' => 'Respinge toate',
    'customize' => 'Configurează',
    'save' => 'Salvează setările',
    'settings_title' => 'Setări de confidențialitate',
    'settings_intro' => 'Alegeți categoriile opționale pe care le permiteți. Puteți modifica sau retrage acordul în orice moment prin linkul „Setări de confidențialitate” de pe site.',
    'close' => 'Închide setările',
    'saved' => 'Setările au fost salvate.',
    'privacy_policy' => 'Politica de confidențialitate',
    'necessary_title' => 'Necesare',
    'necessary_desc' => 'Asigură funcționarea MurConsent și păstrează setările de confidențialitate alese.',
    'always_on' => 'Întotdeauna active',
    'analytics_title' => 'Analiză',
    'analytics_toggle' => 'Permite analiza',
    'analytics_desc' => 'Permite măsurarea audienței și utilizării paginilor pentru evaluarea și îmbunătățirea site-ului.',
    'marketing_title' => 'Marketing și măsurarea conversiilor',
    'marketing_toggle' => 'Permite măsurarea conversiilor publicitare',
    'marketing_desc' => 'Permite asocierea unui clic pe reclamă cu o acțiune importantă pe site, de exemplu un clic pe telefon sau mesagerie.',
    'flag_title' => 'Statistică externă — Flag Counter',
    'flag_toggle' => 'Permite Flag Counter',
    'flag_desc' => 'Încarcă un contor extern al țărilor vizitatorilor. La solicitarea imaginii, furnizorului îi sunt transmise datele tehnice obișnuite ale conexiunii.',
    'service_details' => 'Detalii despre serviciu',
    'provider_label' => 'Furnizor',
    'purpose_label' => 'Scop',
    'domains_label' => 'Domenii',
    'storage_label' => 'Cookie-uri și stocare',
    'retention_label' => 'Durată',
    'provider_policy' => 'Politica furnizorului',
    'website_operator' => 'Operatorul site-ului',
    'current_site' => 'Site-ul curent',
    'retention_days' => 'Până la %d zile',
    'retention_google' => 'Depinde de configurarea serviciului și de politica operatorului site-ului',
    'retention_container' => 'Este determinată de serviciile încărcate prin container',
    'retention_flag' => 'Conform politicii Flag Counter; filtrul tehnic al vizitelor repetate — până la 7 zile',
    'service_preferences_purpose' => 'Păstrarea setărilor de confidențialitate alese',
    'service_analytics_purpose' => 'Statistici privind audiența și utilizarea site-ului',
    'service_ads_purpose' => 'Măsurarea eficienței publicității și a acțiunilor de conversie',
    'service_gtm_purpose' => 'Încărcarea tehnică a etichetelor de analiză sau marketing permise',
    'service_gtm_storage' => 'Containerul nu setează de regulă cookie-uri; acestea pot fi create de etichetele din container',
    'service_flag_purpose' => 'Numărarea vizitelor după țară și afișarea statisticii externe',
    'service_flag_storage' => 'MurConsent nu creează cookie-uri pentru Flag Counter; încărcarea imaginii transmite furnizorului adresa IP, referrer-ul și User-Agent-ul'
);
