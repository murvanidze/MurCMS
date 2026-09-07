<?php

declare(strict_types=1);

namespace MurCMS\MurText;

if (!defined('MUR_CMS_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('No direct script access allowed.');
}

/**
 * Builds automatic unordered, ordered and task lists from contiguous source lines.
 */
final class ListParser
{
    /** @var list<array{tag:string,level:int,li_open:bool,output_index:int}> */
    private array $stack = [];

    /** @var list<string> */
    private array $output = [];

    /** @var list<Diagnostic> */
    private array $warnings = [];

    public function __construct(
        private readonly InlineParser $inlineParser,
        private readonly AttributeParser $attributeParser,
    ) {
    }

    public function consume(string $line, int $lineNumber): bool
    {
        if ($this->isContinuation($line)) {
            $this->output[] = $this->inlineParser->parse(ltrim($line));
            return true;
        }

        if (preg_match('/^(\s*)([-+*]|\d+\.)\s+(.+)$/u', $line, $match) !== 1) {
            return false;
        }

        $spaces = strlen($match[1]);
        $level = intdiv($spaces, 2);
        if ($spaces % 2 !== 0) {
            $this->warnings[] = new Diagnostic(
                'warning',
                'List indentation was normalized to two spaces per level.',
                $lineNumber,
                'list_indent_normalized',
            );
        }

        $type = str_ends_with($match[2], '.') ? 'ol' : 'ul';
        $content = $match[3];
        $this->adjustStack($level, $type, $lineNumber);

        $current = array_key_last($this->stack);
        if ($current === null) {
            return false;
        }

        if ($this->stack[$current]['li_open']) {
            $this->output[] = '</li>';
        }

        $attributes = [];
        if (preg_match('/^\[([xX ])\]\s+(.+)$/u', $content, $todoMatch) === 1) {
            $attributes['class'] = 'task-list-item';
            $checked = strtolower($todoMatch[1]) === 'x';
            $checkbox = '<input type="checkbox" disabled' . ($checked ? ' checked' : '') . '>';
            $content = $checkbox . ' ' . $this->inlineParser->parse($todoMatch[2]);
            $this->ensureTaskListClass($current);
        } else {
            $content = $this->inlineParser->parse($content);
        }

        $this->output[] = '<li' . $this->attributeParser->render($attributes) . '>' . $content;
        $this->stack[$current]['li_open'] = true;
        return true;
    }

    public function hasOpenList(): bool
    {
        return $this->stack !== [];
    }

    /** @return array{html:list<string>,warnings:list<Diagnostic>} */
    public function close(): array
    {
        while ($this->stack !== []) {
            $this->closeLevel();
        }

        $result = [
            'html' => $this->output,
            'warnings' => $this->warnings,
        ];

        $this->output = [];
        $this->warnings = [];
        return $result;
    }

    private function adjustStack(int $level, string $type, int $lineNumber): void
    {
        if ($this->stack === [] && $level > 0) {
            $this->warnings[] = new Diagnostic(
                'warning',
                'A nested list started without a parent item; indentation was reset.',
                $lineNumber,
                'orphan_nested_list',
            );
            $level = 0;
        }

        while (count($this->stack) - 1 > $level) {
            $this->closeLevel();
        }

        while (count($this->stack) - 1 < $level) {
            if ($this->stack === []) {
                $level = 0;
                break;
            }
            $this->openList($type, count($this->stack));
        }

        $current = array_key_last($this->stack);
        if ($current === null) {
            $this->openList($type, 0);
            return;
        }

        if ($this->stack[$current]['tag'] !== $type) {
            $this->closeLevel();
            $this->openList($type, $level);
        }
    }

    private function openList(string $tag, int $level): void
    {
        $outputIndex = count($this->output);
        $this->output[] = '<' . $tag . '>';
        $this->stack[] = [
            'tag' => $tag,
            'level' => $level,
            'li_open' => false,
            'output_index' => $outputIndex,
        ];
    }

    private function closeLevel(): void
    {
        $item = array_pop($this->stack);
        if ($item === null) {
            return;
        }

        if ($item['li_open']) {
            $this->output[] = '</li>';
        }
        $this->output[] = '</' . $item['tag'] . '>';
    }

    private function isContinuation(string $line): bool
    {
        if ($this->stack === []) {
            return false;
        }

        if (preg_match('/^(\s+)(?![-+*]\s|\d+\.\s)(\S.*)$/u', $line, $match) !== 1) {
            return false;
        }

        $current = $this->stack[array_key_last($this->stack)];
        return strlen($match[1]) >= ($current['level'] + 1) * 2;
    }

    private function ensureTaskListClass(int $current): void
    {
        $outputIndex = $this->stack[$current]['output_index'];
        $tag = $this->stack[$current]['tag'];
        if (($this->output[$outputIndex] ?? '') === '<' . $tag . '>') {
            $this->output[$outputIndex] = '<' . $tag . ' class="task-list">';
        }
    }
}
