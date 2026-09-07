<?php

declare(strict_types=1);

namespace MurCMS\MurConsent;

if (!defined('MUR_CMS_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('No direct script access allowed.');
}

final class LanguageResolver
{
    public const SUPPORTED = ['ro', 'ru', 'en'];

    public static function normalize(mixed $language, string $fallback = 'en'): string
    {
        if (!is_string($language)) {
            return $fallback;
        }
        $language = strtolower(trim(str_replace('_', '-', $language)));
        if ($language === '') {
            return $fallback;
        }
        $base = explode('-', $language, 2)[0];
        return in_array($base, self::SUPPORTED, true) ? $base : $fallback;
    }
}
