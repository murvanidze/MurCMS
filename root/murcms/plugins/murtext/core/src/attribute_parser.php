<?php

declare(strict_types=1);

namespace MurCMS\MurText;

if (!defined('MUR_CMS_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('No direct script access allowed.');
}

/**
 * Parses MurText element metadata: classes, styles, #id and {attributes}.
 */
final class AttributeParser
{
    /**
     * Parses metadata immediately following an element command.
     *
     * @return array{
     *   attributes:array<string,string|true>,
     *   remainder:string,
     *   warnings:list<string>
     * }
     */
    public function parse(string $input): array
    {
        $cursor = 0;
        $length = strlen($input);
        $groups = [];
        $attributes = [];
        $warnings = [];

        while ($cursor < $length && $input[$cursor] === '(' && count($groups) < 2) {
            [$value, $next, $closed] = $this->readDelimited($input, $cursor, '(', ')');
            if (!$closed) {
                $warnings[] = 'Unclosed parenthesized metadata group.';
                break;
            }

            $groups[] = trim($value);
            $cursor = $next;
        }

        if (isset($groups[0])) {
            $classes = preg_split('/\s+/u', $groups[0], -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $validClasses = [];

            foreach ($classes as $class) {
                if (preg_match('/^[\pL_][\pL\pN_-]*$/u', $class) === 1) {
                    $validClasses[] = $class;
                } else {
                    $warnings[] = sprintf('Invalid CSS class ignored: %s', $class);
                }
            }

            if ($validClasses !== []) {
                $attributes['class'] = implode(' ', $validClasses);
            }
        }

        if (isset($groups[1]) && $groups[1] !== '') {
            $attributes['style'] = $groups[1];
        }

        if ($cursor < $length && $input[$cursor] === '#') {
            $remaining = substr($input, $cursor);
            if (preg_match('/^#([A-Za-z_][A-Za-z0-9_-]*)/', $remaining, $idMatch) === 1) {
                $attributes['id'] = $idMatch[1];
                $cursor += strlen($idMatch[0]);
            } else {
                $warnings[] = 'Invalid or empty element ID ignored.';
                $cursor++;
                while ($cursor < $length && !ctype_space($input[$cursor]) && $input[$cursor] !== '{') {
                    $cursor++;
                }
            }
        }

        if ($cursor < $length && $input[$cursor] === '{') {
            [$rawAttributes, $next, $closed] = $this->readDelimited($input, $cursor, '{', '}');
            if (!$closed) {
                $warnings[] = 'Unclosed attribute block.';
            } else {
                foreach ($this->parseAttributeList($rawAttributes, $warnings) as $name => $value) {
                    if ($name === 'class' && isset($attributes['class']) && is_string($value)) {
                        $attributes['class'] .= ' ' . $value;
                        continue;
                    }

                    if ($name === 'style' && isset($attributes['style']) && is_string($value)) {
                        $separator = str_ends_with((string) $attributes['style'], ';') ? ' ' : '; ';
                        $attributes['style'] .= $separator . $value;
                        continue;
                    }

                    $attributes[$name] = $value;
                }
                $cursor = $next;
            }
        }

        return [
            'attributes' => $attributes,
            'remainder' => ltrim(substr($input, $cursor)),
            'warnings' => $warnings,
        ];
    }

    /**
     * @param list<string> $warnings
     * @return array<string,string|true>
     */
    private function parseAttributeList(string $input, array &$warnings): array
    {
        $attributes = [];
        $pattern = '/(?:^|\s+)([A-Za-z_:][A-Za-z0-9:_.-]*)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s]+)))?/u';

        if (preg_match_all($pattern, trim($input), $matches, PREG_SET_ORDER) === false) {
            return $attributes;
        }

        foreach ($matches as $match) {
            $name = strtolower($match[1]);
            if (str_starts_with($name, 'on')) {
                $warnings[] = sprintf('Event attribute retained for trusted content: %s', $name);
            }

            $value = true;
            if (($match[2] ?? '') !== '') {
                $value = $match[2];
            } elseif (($match[3] ?? '') !== '') {
                $value = $match[3];
            } elseif (($match[4] ?? '') !== '') {
                $value = $match[4];
            }

            $attributes[$name] = $value;
        }

        return $attributes;
    }

    /** @return array{0:string,1:int,2:bool} */
    private function readDelimited(
        string $input,
        int $start,
        string $open,
        string $close,
    ): array {
        $length = strlen($input);
        $quote = null;
        $escaped = false;
        $depth = 1;

        for ($cursor = $start + 1; $cursor < $length; $cursor++) {
            $char = $input[$cursor];

            if ($escaped) {
                $escaped = false;
                continue;
            }

            if ($char === '\\') {
                $escaped = true;
                continue;
            }

            if ($quote !== null) {
                if ($char === $quote) {
                    $quote = null;
                }
                continue;
            }

            if ($char === '"' || $char === "'") {
                $quote = $char;
                continue;
            }

            if ($char === $open) {
                $depth++;
                continue;
            }

            if ($char === $close) {
                $depth--;
                if ($depth === 0) {
                    return [substr($input, $start + 1, $cursor - $start - 1), $cursor + 1, true];
                }
            }
        }

        return [substr($input, $start + 1), $length, false];
    }

    /** @param array<string,string|true> $attributes */
    public function render(array $attributes): string
    {
        $result = '';

        foreach ($attributes as $name => $value) {
            $safeName = preg_replace('/[^A-Za-z0-9:_.-]/', '', $name) ?? '';
            if ($safeName === '') {
                continue;
            }

            if ($value === true) {
                $result .= ' ' . $safeName;
                continue;
            }

            $result .= sprintf(
                ' %s="%s"',
                $safeName,
                htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            );
        }

        return $result;
    }
}
