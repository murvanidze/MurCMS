<?php

declare(strict_types=1);

namespace MurCMS\MurText;

if (!defined('MUR_CMS_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('No direct script access allowed.');
}

/**
 * Coordinates normalization, protected blocks, block parsing and diagnostics.
 */
final class Parser
{
    /** @var array<string,mixed> */
    private array $options;

    /** @param array<string,mixed> $options */
    public function __construct(array $options = [])
    {
        $this->options = array_replace([
            'pretty' => false,
            'strict' => false,
            'site_url' => '',
            'uploads_url' => '',
            'pages_url' => '',
            'paragraph_mode' => 'blank_lines',
            'hard_break_spaces' => true,
            'hard_break_backslash' => true,
            'hard_break_command' => true,
            'blockquote_join' => 'br',
            'image_loading' => 'lazy',
            'image_decoding' => 'async',
        ], $options);
    }

    public function parse(string $source): ParseResult
    {
        $source = $this->normalize($source);
        [$source, $protected] = $this->protectBlocks($source);

        $attributeParser = new AttributeParser();
        $inlineParser = new InlineParser($attributeParser, $this->options);
        $blockParser = new BlockParser($inlineParser, $attributeParser, $this->options, $protected);
        $parsed = $blockParser->parse($source);

        $renderedHtml = str_replace('<blockquote data-murtext-joined>', '<blockquote>', $parsed['html']);
        $html = $this->options['pretty'] === true
            ? $renderedHtml
            : $this->compact($renderedHtml);

        $warnings = $parsed['warnings'];
        $errors = $parsed['errors'];

        if ($this->options['strict'] === true) {
            $strictCodes = [
                'unclosed_block',
                'auto_closed_block',
                'unmatched_closing_command',
                'unclosed_code_fence',
                'invalid_metadata',
            ];

            foreach ($warnings as $warning) {
                if ($warning->code !== null && in_array($warning->code, $strictCodes, true)) {
                    $errors[] = new Diagnostic(
                        'error',
                        $warning->message,
                        $warning->line,
                        $warning->code,
                    );
                }
            }
        }

        return new ParseResult($html, $warnings, $errors);
    }

    private function normalize(string $source): string
    {
        $source = preg_replace('/^\xEF\xBB\xBF/', '', $source) ?? $source;
        $source = str_replace(["\r\n", "\r"], "\n", $source);
        $source = str_replace("\t", '  ', $source);
        return trim($source, "\n");
    }

    /** @return array{0:string,1:array<string,string>} */
    private function protectBlocks(string $source): array
    {
        $protected = [];

        $store = function (string $content) use (&$protected): string {
            $token = "\x1AMURBLOCK" . count($protected) . "\x1A";
            $protected[$token] = $content;
            return "\n" . $token . "\n";
        };

        $source = preg_replace_callback(
            '/^raw\s*\n(.*?)\n\/raw\s*$/msu',
            static fn (array $match): string => $store($match[1]),
            $source,
        ) ?? $source;

        $source = preg_replace_callback(
            '/<!--\s*nomurtext\s*-->(.*?)<!--\s*\/nomurtext\s*-->/isu',
            static fn (array $match): string => $store(trim($match[1], "\n")),
            $source,
        ) ?? $source;

        $source = preg_replace_callback(
            '/<(script|style)\b[^>]*>.*?<\/\1\s*>/isu',
            static fn (array $match): string => $store($match[0]),
            $source,
        ) ?? $source;

        return [$source, $protected];
    }

    private function compact(string $html): string
    {
        $html = preg_replace('/\n(?=<)/', '', $html) ?? $html;
        return trim($html);
    }
}
