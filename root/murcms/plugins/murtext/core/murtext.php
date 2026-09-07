<?php

declare(strict_types=1);

namespace MurCMS\MurText;

if (!defined('MUR_CMS_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('No direct script access allowed.');
}

require_once __DIR__ . '/src/parse_result.php';
require_once __DIR__ . '/src/attribute_parser.php';
require_once __DIR__ . '/src/inline_parser.php';
require_once __DIR__ . '/src/list_parser.php';
require_once __DIR__ . '/src/block_parser.php';
require_once __DIR__ . '/src/parser.php';

/**
 * Public facade for the MurText parser core.
 */
final class MurText
{
    /** @param array<string,mixed> $options */
    public static function parse(string $source, array $options = []): ParseResult
    {
        return (new Parser($options))->parse($source);
    }

    /** @param array<string,mixed> $options */
    public static function toHtml(string $source, array $options = []): string
    {
        return self::parse($source, $options)->html;
    }

    /** @param array<string,mixed> $options */
    public static function validate(string $source, array $options = []): ParseResult
    {
        $options['strict'] = true;
        $options['pretty'] = true;
        return self::parse($source, $options);
    }

    public static function version(): string
    {
        return '0.2.0';
    }
}
