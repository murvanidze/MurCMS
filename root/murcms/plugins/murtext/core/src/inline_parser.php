<?php

declare(strict_types=1);

namespace MurCMS\MurText;

if (!defined('MUR_CMS_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('No direct script access allowed.');
}

/**
 * Parses inline MurText constructs while preserving raw HTML tags and BB codes.
 */
final class InlineParser
{
    /** @var array<string,mixed> */
    private array $options;

    public function __construct(
        private readonly AttributeParser $attributeParser,
        array $options = [],
    ) {
        $this->options = $options;
    }

    public function parse(string $text): string
    {
        $tokens = [];
        $text = $this->protectEscapedCharacters($text, $tokens);
        $text = $this->protectHtmlTags($text, $tokens);
        $text = $this->protectCodeSpans($text, $tokens);
        $text = $this->parseImagesAndLinks($text, $tokens);

        $text = preg_replace_callback(
            '/@@(?=\S)(.+?)(?<=\S)@@/us',
            static fn (array $match): string => '<kbd>' . $match[1] . '</kbd>',
            $text,
        ) ?? $text;

        $text = preg_replace_callback(
            '/@(?=\S)(.+?)(?<=\S)@/us',
            static fn (array $match): string => '<mark>' . $match[1] . '</mark>',
            $text,
        ) ?? $text;

        $text = preg_replace_callback(
            '/~~(?=\S)(.+?)(?<=\S)~~/us',
            static fn (array $match): string => '<del>' . $match[1] . '</del>',
            $text,
        ) ?? $text;

        $text = preg_replace_callback(
            '/\*\*\*(?=\S)(.+?)(?<=\S)\*\*\*/us',
            static fn (array $match): string => '<strong><em>' . $match[1] . '</em></strong>',
            $text,
        ) ?? $text;

        $text = preg_replace_callback(
            '/\*\*(?=\S)(.+?)(?<=\S)\*\*/us',
            static fn (array $match): string => '<strong>' . $match[1] . '</strong>',
            $text,
        ) ?? $text;

        $text = preg_replace_callback(
            '/(?<!\*)\*(?=\S)(.+?)(?<=\S)\*(?!\*)/us',
            static fn (array $match): string => '<em>' . $match[1] . '</em>',
            $text,
        ) ?? $text;

        $text = preg_replace_callback(
            '/(?<![\pL\pN_])__(?=\S)(.+?)(?<=\S)__(?![\pL\pN_])/us',
            static fn (array $match): string => '<strong>' . $match[1] . '</strong>',
            $text,
        ) ?? $text;

        $text = preg_replace_callback(
            '/(?<![\pL\pN_])_(?=\S)(.+?)(?<=\S)_(?![\pL\pN_])/us',
            static fn (array $match): string => '<em>' . $match[1] . '</em>',
            $text,
        ) ?? $text;

        return $this->restoreTokens($text, $tokens);
    }

    /** @param array<string,string> $tokens */
    private function protectEscapedCharacters(string $text, array &$tokens): string
    {
        $special = ['\\', '*', '_', '@', '`', '#', '-', '|', '[', ']', '{', '}', '(', ')', '!', '>'];
        $result = '';
        $length = strlen($text);

        for ($index = 0; $index < $length; $index++) {
            $char = $text[$index];
            if ($char === '\\' && $index + 1 < $length) {
                $next = $text[$index + 1];
                if (in_array($next, $special, true)) {
                    $result .= $this->token($next, $tokens);
                    $index++;
                    continue;
                }
            }

            $result .= $char;
        }

        return $result;
    }

    /** @param array<string,string> $tokens */
    private function protectHtmlTags(string $text, array &$tokens): string
    {
        return preg_replace_callback(
            '/<!--.*?-->|<\/?[A-Za-z][^>]*>/us',
            fn (array $match): string => $this->token($match[0], $tokens),
            $text,
        ) ?? $text;
    }

    /** @param array<string,string> $tokens */
    private function protectCodeSpans(string $text, array &$tokens): string
    {
        return preg_replace_callback(
            '/`([^`\n]+)`/u',
            function (array $match) use (&$tokens): string {
                $html = '<code>' . htmlspecialchars($match[1], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</code>';
                return $this->token($html, $tokens);
            },
            $text,
        ) ?? $text;
    }

    /** @param array<string,string> $tokens */
    private function parseImagesAndLinks(string $text, array &$tokens): string
    {
        $text = preg_replace_callback(
            '/!!\[([^\]]*)\]\(([^)\s]+)\)(\{[^}]*\})?/u',
            function (array $match) use (&$tokens): string {
                $alt = $match[1];
                $url = $this->resolveUrl($match[2]);
                $attrs = $this->parseBraceAttributes($match[3] ?? '');
                $imageAttrs = [
                    'src' => $url,
                    'alt' => $alt,
                ];
                $this->appendImageDefaults($imageAttrs);
                $linkAttrs = ['href' => $url];

                foreach ($attrs as $name => $value) {
                    if (in_array($name, ['width', 'height', 'alt', 'title', 'loading', 'decoding'], true)) {
                        $imageAttrs[$name] = $value;
                    } else {
                        $linkAttrs[$name] = $value;
                    }
                }

                $html = '<a' . $this->attributeParser->render($linkAttrs) . '>'
                    . '<img' . $this->attributeParser->render($imageAttrs) . '>'
                    . '</a>';

                return $this->token($html, $tokens);
            },
            $text,
        ) ?? $text;

        $text = preg_replace_callback(
            '/!\[([^\]]*)\]\(([^)\s]+)\)(\{[^}]*\})?/u',
            function (array $match) use (&$tokens): string {
                $attributes = [
                    'src' => $this->resolveUrl($match[2]),
                    'alt' => $match[1],
                ];
                $this->appendImageDefaults($attributes);

                foreach ($this->parseBraceAttributes($match[3] ?? '') as $name => $value) {
                    $attributes[$name] = $value;
                }

                return $this->token(
                    '<img' . $this->attributeParser->render($attributes) . '>',
                    $tokens,
                );
            },
            $text,
        ) ?? $text;

        $text = preg_replace_callback(
            '/\[([^\]]*)\]\(([^)\s]+)\)(\{[^}]*\})?/u',
            function (array $match) use (&$tokens): string {
                $url = $this->resolveUrl($match[2]);
                $label = $match[1] !== '' ? $this->parse($match[1]) : htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $attributes = ['href' => $url];

                foreach ($this->parseBraceAttributes($match[3] ?? '') as $name => $value) {
                    $attributes[$name] = $value;
                }

                return $this->token(
                    '<a' . $this->attributeParser->render($attributes) . '>' . $label . '</a>',
                    $tokens,
                );
            },
            $text,
        ) ?? $text;

        return $text;
    }

    /** @return array<string,string|true> */
    private function parseBraceAttributes(string $raw): array
    {
        if ($raw === '') {
            return [];
        }

        $parsed = $this->attributeParser->parse($raw);
        return $parsed['attributes'];
    }


    /** @param array<string,string|true> $attributes */
    private function appendImageDefaults(array &$attributes): void
    {
        $loading = trim((string) ($this->options['image_loading'] ?? 'lazy'));
        $decoding = trim((string) ($this->options['image_decoding'] ?? 'async'));

        if ($loading !== '') {
            $attributes['loading'] = $loading;
        }

        if ($decoding !== '') {
            $attributes['decoding'] = $decoding;
        }
    }

    private function resolveUrl(string $url): string
    {
        $site = rtrim((string) ($this->options['site_url'] ?? ''), '/');
        $uploads = rtrim((string) ($this->options['uploads_url'] ?? ''), '/');
        $pages = rtrim((string) ($this->options['pages_url'] ?? ''), '/');

        return match (true) {
            str_starts_with($url, '##') => ($site !== '' ? $site : '') . '/' . ltrim(substr($url, 2), '/'),
            str_starts_with($url, '//') => ($uploads !== '' ? $uploads : '/uploads') . '/' . ltrim(substr($url, 2), '/'),
            str_starts_with($url, 'site:') => ($site !== '' ? $site : '') . '/' . ltrim(substr($url, 5), '/'),
            str_starts_with($url, 'uploads:') => ($uploads !== '' ? $uploads : '/uploads') . '/' . ltrim(substr($url, 8), '/'),
            str_starts_with($url, 'pages:') => ($pages !== '' ? $pages : '/pages') . '/' . ltrim(substr($url, 6), '/'),
            default => $url,
        };
    }

    /** @param array<string,string> $tokens */
    private function token(string $value, array &$tokens): string
    {
        $token = "\x1AMURINLINE" . count($tokens) . "\x1A";
        $tokens[$token] = $value;
        return $token;
    }

    /** @param array<string,string> $tokens */
    private function restoreTokens(string $text, array $tokens): string
    {
        for ($pass = 0; $pass < 4; $pass++) {
            $restored = strtr($text, $tokens);
            if ($restored === $text) {
                break;
            }
            $text = $restored;
        }

        return $text;
    }
}
