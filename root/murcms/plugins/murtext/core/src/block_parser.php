<?php

declare(strict_types=1);

namespace MurCMS\MurText;

if (!defined('MUR_CMS_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('No direct script access allowed.');
}

/**
 * Parses MurText block structure and emits structured HTML.
 */
final class BlockParser
{
    /** @var list<string> */
    private array $output = [];

    /** @var list<array{tag:string,line:int}> */
    private array $stack = [];

    /** @var list<array{text:string,forced_break:bool}> */
    private array $paragraph = [];

    /** @var list<Diagnostic> */
    private array $warnings = [];

    /** @var list<Diagnostic> */
    private array $errors = [];

    /** @var array<string,string> */
    private array $protectedBlocks;

    /** @var array<string,mixed> */
    private array $options;

    /** @var list<string> */
    private const OPEN_BLOCKS = [
        'div', 'section', 'article', 'main', 'header', 'footer', 'aside', 'nav',
        'blockquote', 'fieldset', 'hgroup', 'ul', 'ol', 'dl', 'table', 'thead',
        'tbody', 'tfoot', 'tr',
    ];

    /** @var list<string> */
    private const INLINE_BLOCKS = [
        'summary', 'caption', 'dt', 'dd', 'th', 'td', 'li',
    ];

    private readonly ListParser $listParser;

    public function __construct(
        private readonly InlineParser $inlineParser,
        private readonly AttributeParser $attributeParser,
        array $options = [],
        array $protectedBlocks = [],
    ) {
        $this->options = $options;
        $this->protectedBlocks = $protectedBlocks;
        $this->listParser = new ListParser($inlineParser, $attributeParser);
    }

    /**
     * @return array{html:string,warnings:list<Diagnostic>,errors:list<Diagnostic>}
     */
    public function parse(string $source): array
    {
        $lines = explode("\n", $source);
        $inFence = false;
        $fenceStart = 0;
        $fenceLanguage = '';
        $fenceClasses = [];
        $fenceLines = [];

        foreach ($lines as $index => $originalLine) {
            $lineNumber = $index + 1;
            $line = rtrim($originalLine, "\r");
            $trimmed = trim($line);

            if ($inFence) {
                if ($trimmed === '```') {
                    $this->flushParagraph();
                    $this->closeAutoLists();
                    $this->renderCodeFence($fenceLines, $fenceLanguage, $fenceClasses);
                    $inFence = false;
                    $fenceLines = [];
                    continue;
                }

                $fenceLines[] = $line;
                continue;
            }

            if (preg_match('/^```(?:\(([^)]*)\)|([^\s]+)?(?:\s+(.+))?)?\s*$/u', $trimmed, $fenceMatch) === 1) {
                $this->flushParagraph();
                $this->closeAutoLists();
                $inFence = true;
                $fenceStart = $lineNumber;
                $fenceLanguage = '';
                $fenceClasses = [];

                if (($fenceMatch[1] ?? '') !== '') {
                    $fenceClasses = preg_split('/\s+/u', trim($fenceMatch[1]), -1, PREG_SPLIT_NO_EMPTY) ?: [];
                } else {
                    $fenceLanguage = trim($fenceMatch[2] ?? '');
                    $fenceClasses = preg_split('/\s+/u', trim($fenceMatch[3] ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: [];
                }
                continue;
            }

            if (isset($this->protectedBlocks[$trimmed])) {
                $this->flushParagraph();
                $this->closeAutoLists();
                $this->emitRaw($this->protectedBlocks[$trimmed]);
                continue;
            }

            if ($trimmed === '') {
                $this->flushParagraph();
                $this->closeAutoLists();
                continue;
            }

            if ($this->handleExplicitListItem($line)) {
                continue;
            }

            if ($this->listParser->consume($line, $lineNumber)) {
                continue;
            }

            $this->closeAutoLists();

            if ($this->handleParallelCommands($trimmed, $lineNumber)) {
                continue;
            }

            if ($this->handleClosingCommand($trimmed, $lineNumber)) {
                continue;
            }

            if ($this->handleCompactTableRow($trimmed)) {
                continue;
            }

            if ($this->handleHeading($trimmed, $lineNumber)) {
                continue;
            }

            if ($trimmed === 'hr' || $trimmed === '---') {
                $this->flushParagraph();
                $this->emit('<hr>');
                continue;
            }

            if ($trimmed === 'br' && ($this->options['hard_break_command'] ?? true) === true) {
                if ($this->paragraph !== []) {
                    $last = array_key_last($this->paragraph);
                    $this->paragraph[$last]['forced_break'] = true;
                } else {
                    $this->emit('<br>');
                }
                continue;
            }

            if ($this->handleQuoteLine($trimmed)) {
                continue;
            }

            if ($this->handleExplicitParagraph($trimmed, $lineNumber)) {
                continue;
            }

            if ($this->handleOneLineDiv($trimmed, $lineNumber)) {
                continue;
            }

            if ($this->handleElementCommand($trimmed, $lineNumber)) {
                continue;
            }

            if ($this->looksLikeRawHtml($trimmed)) {
                $this->flushParagraph();
                $this->emitRaw($line);
                continue;
            }

            $this->appendParagraphLine($line);
        }

        if ($inFence) {
            $this->warnings[] = new Diagnostic(
                'warning',
                'Unclosed fenced code block was closed at end of document.',
                $fenceStart,
                'unclosed_code_fence',
            );
            $this->renderCodeFence($fenceLines, $fenceLanguage, $fenceClasses);
        }

        $this->flushParagraph();
        $this->closeAutoLists();
        $this->closeAllBlocks();

        return [
            'html' => implode("\n", $this->output),
            'warnings' => $this->warnings,
            'errors' => $this->errors,
        ];
    }

    private function handleHeading(string $line, int $lineNumber): bool
    {
        if (preg_match('/^(h[1-6])(?=\s|\(|#|\{|$)(.*)$/u', $line, $match) === 1) {
            $tag = $match[1];
            $meta = $this->attributeParser->parse($match[2]);
            if ($meta['remainder'] === '' && $match[2] !== '') {
                return false;
            }

            $this->flushParagraph();
            $this->addMetadataWarnings($meta['warnings'], $lineNumber);
            $this->emit(sprintf(
                '<%1$s%2$s>%3$s</%1$s>',
                $tag,
                $this->attributeParser->render($meta['attributes']),
                $this->inlineParser->parse($meta['remainder']),
            ));
            return true;
        }

        if (preg_match('/^(#{1,6})\s+(.+)$/u', $line, $match) === 1) {
            $this->flushParagraph();
            $tag = 'h' . strlen($match[1]);
            $this->emit(sprintf(
                '<%1$s>%2$s</%1$s>',
                $tag,
                $this->inlineParser->parse($match[2]),
            ));
            return true;
        }

        return false;
    }

    private function handleQuoteLine(string $line): bool
    {
        $rest = null;
        if (str_starts_with($line, '>')) {
            $rest = substr($line, 1);
        } elseif (preg_match('/^bqq(?=\s|\(|#|\{|$)(.*)$/u', $line, $match) === 1) {
            $rest = $match[1];
        }

        if ($rest === null) {
            return false;
        }

        $meta = $this->attributeParser->parse($rest);
        $this->flushParagraph();
        $content = $this->inlineParser->parse($meta['remainder']);
        $lastIndex = array_key_last($this->output);
        $joinable = $meta['attributes'] === [];

        $joinMode = (string) ($this->options['blockquote_join'] ?? 'br');
        $canJoin = $joinable && $joinMode !== 'separate';

        if ($canJoin && $lastIndex !== null && str_starts_with($this->output[$lastIndex], '<blockquote data-murtext-joined>')) {
            $separator = $joinMode === 'space' ? ' ' : '<br>';
            $this->output[$lastIndex] = substr($this->output[$lastIndex], 0, -strlen('</blockquote>'))
                . $separator . $content . '</blockquote>';
        } else {
            $marker = $canJoin ? ' data-murtext-joined' : '';
            $this->emit('<blockquote' . $marker
                . $this->attributeParser->render($meta['attributes']) . '>'
                . $content . '</blockquote>');
        }

        return true;
    }

    private function handleExplicitParagraph(string $line, int $lineNumber): bool
    {
        if (preg_match('/^_(?=\s|\()(.*)$/u', $line, $match) !== 1) {
            return false;
        }

        $meta = $this->attributeParser->parse($match[1]);
        if ($meta['remainder'] === '' && !str_starts_with(substr($line, 1), ' ')) {
            return false;
        }

        $this->flushParagraph();
        $this->addMetadataWarnings($meta['warnings'], $lineNumber);
        $this->emit('<p' . $this->attributeParser->render($meta['attributes']) . '>'
            . $this->inlineParser->parse($meta['remainder']) . '</p>');
        return true;
    }

    private function handleOneLineDiv(string $line, int $lineNumber): bool
    {
        if (!preg_match('/^__(?=\s|\()(.*)$/u', $line, $match)) {
            return false;
        }

        $meta = $this->attributeParser->parse($match[1]);
        $this->flushParagraph();
        $this->addMetadataWarnings($meta['warnings'], $lineNumber);
        $this->emit('<div' . $this->attributeParser->render($meta['attributes']) . '>'
            . $this->inlineParser->parse($meta['remainder']) . '</div>');
        return true;
    }

    private function handleElementCommand(string $line, int $lineNumber): bool
    {
        if (preg_match('/^(details\+|detailsopen)(.*)$/u', $line, $match) === 1) {
            $meta = $this->attributeParser->parse($match[2]);
            if ($meta['remainder'] !== '') {
                return false;
            }
            $meta['attributes']['open'] = true;
            $this->openBlock('details', $meta['attributes'], $lineNumber, $meta['warnings']);
            return true;
        }

        if (preg_match('/^([A-Za-z][A-Za-z0-9]*)(.*)$/u', $line, $match) !== 1) {
            return false;
        }

        $tag = strtolower($match[1]);
        if ($tag === 'bq') {
            $tag = 'blockquote';
        }

        $meta = $this->attributeParser->parse($match[2]);

        if ($tag === 'details' && $meta['remainder'] === '') {
            $this->openBlock($tag, $meta['attributes'], $lineNumber, $meta['warnings']);
            return true;
        }

        if (in_array($tag, self::OPEN_BLOCKS, true) && $meta['remainder'] === '') {
            $this->openBlock($tag, $meta['attributes'], $lineNumber, $meta['warnings']);
            return true;
        }

        if (in_array($tag, self::INLINE_BLOCKS, true) && $meta['remainder'] !== '') {
            $this->flushParagraph();
            $this->addMetadataWarnings($meta['warnings'], $lineNumber);
            $this->emit(sprintf(
                '<%1$s%2$s>%3$s</%1$s>',
                $tag,
                $this->attributeParser->render($meta['attributes']),
                $this->inlineParser->parse($meta['remainder']),
            ));
            return true;
        }

        return false;
    }

    private function openBlock(string $tag, array $attributes, int $lineNumber, array $warnings = []): void
    {
        $this->flushParagraph();
        $this->addMetadataWarnings($warnings, $lineNumber);
        $this->emit('<' . $tag . $this->attributeParser->render($attributes) . '>');
        $this->stack[] = ['tag' => $tag, 'line' => $lineNumber];
    }

    private function handleClosingCommand(string $line, int $lineNumber): bool
    {
        if (preg_match('/^\/([A-Za-z][A-Za-z0-9]*)$/u', $line, $match) !== 1) {
            return false;
        }

        $tag = strtolower($match[1]);
        if ($tag === 'bq') {
            $tag = 'blockquote';
        }

        $position = null;
        for ($index = count($this->stack) - 1; $index >= 0; $index--) {
            if ($this->stack[$index]['tag'] === $tag) {
                $position = $index;
                break;
            }
        }

        if ($position === null) {
            $this->warnings[] = new Diagnostic(
                'warning',
                sprintf('Unknown or unmatched closing command treated as text: /%s', $tag),
                $lineNumber,
                'unmatched_closing_command',
            );
            $this->appendParagraphLine($line);
            return true;
        }

        $this->flushParagraph();
        for ($index = count($this->stack) - 1; $index >= $position; $index--) {
            $item = array_pop($this->stack);
            if ($item === null) {
                break;
            }

            if ($item['tag'] !== $tag) {
                $this->warnings[] = new Diagnostic(
                    'warning',
                    sprintf('Block <%s> was automatically closed before </%s>.', $item['tag'], $tag),
                    $lineNumber,
                    'auto_closed_block',
                );
            }
            $this->emit('</' . $item['tag'] . '>');
        }

        return true;
    }

    private function handleParallelCommands(string $line, int $lineNumber): bool
    {
        if (!str_contains($line, '||')) {
            return false;
        }

        $parts = array_map('trim', explode('||', $line));
        if ($parts === [] || in_array('', $parts, true)) {
            return false;
        }

        $allClosing = true;
        $allOpening = true;
        foreach ($parts as $part) {
            $allClosing = $allClosing && preg_match('/^\/[A-Za-z][A-Za-z0-9]*$/', $part) === 1;
            $allOpening = $allOpening && preg_match('/^[A-Za-z][A-Za-z0-9]*(?:\([^)]*\)){0,2}(?:#[A-Za-z_][A-Za-z0-9_-]*)?(?:\{[^}]*\})?$/', $part) === 1;
        }

        if (!$allClosing && !$allOpening) {
            return false;
        }

        $this->flushParagraph();
        if ($allClosing) {
            foreach ($parts as $part) {
                $this->handleClosingCommand($part, $lineNumber);
            }
            return true;
        }

        foreach ($parts as $part) {
            if (!$this->handleElementCommand($part, $lineNumber)) {
                return false;
            }
        }
        return true;
    }

    private function handleExplicitListItem(string $line): bool
    {
        if (!$this->insideExplicitList()) {
            return false;
        }

        if (preg_match('/^\s*(?:[-+*]|\d+\.)\s+(.+)$/u', $line, $match) !== 1) {
            return false;
        }

        $this->flushParagraph();
        $this->emitListItem($match[1]);
        return true;
    }

    private function insideExplicitList(): bool
    {
        if ($this->stack === []) {
            return false;
        }

        $tag = $this->stack[array_key_last($this->stack)]['tag'];
        return $tag === 'ul' || $tag === 'ol';
    }

    private function emitListItem(string $content): void
    {
        if (preg_match('/^\[([xX ])\]\s+(.+)$/u', $content, $match) === 1) {
            $checked = strtolower($match[1]) === 'x';
            $checkbox = '<input type="checkbox" disabled' . ($checked ? ' checked' : '') . '>';
            $this->emit('<li class="task-list-item">' . $checkbox . ' '
                . $this->inlineParser->parse($match[2]) . '</li>');
            return;
        }

        $this->emit('<li>' . $this->inlineParser->parse($content) . '</li>');
    }

    private function closeAutoLists(): void
    {
        if (!$this->listParser->hasOpenList()) {
            return;
        }

        $list = $this->listParser->close();
        foreach ($list['html'] as $html) {
            $this->emit($html);
        }
        foreach ($list['warnings'] as $warning) {
            $this->warnings[] = $warning;
        }
    }

    private function handleCompactTableRow(string $line): bool
    {
        if (!$this->insideTag('table') || !str_starts_with($line, '|') || !str_ends_with($line, '|')) {
            return false;
        }

        $this->flushParagraph();
        $header = str_starts_with($line, '|:') && str_ends_with($line, ':|');
        $inner = $header ? substr($line, 2, -2) : substr($line, 1, -1);
        $cells = preg_split('/(?<!\\\\)\|/u', $inner) ?: [];
        $tag = $header ? 'th' : 'td';
        $html = '<tr>';

        foreach ($cells as $cell) {
            $cell = str_replace('\\|', '|', trim($cell));
            $html .= '<' . $tag . '>' . $this->inlineParser->parse($cell) . '</' . $tag . '>';
        }

        $html .= '</tr>';
        $this->emit($html);
        return true;
    }

    private function insideTag(string $tag): bool
    {
        foreach (array_reverse($this->stack) as $item) {
            if ($item['tag'] === $tag) {
                return true;
            }
        }
        return false;
    }

    private function appendParagraphLine(string $line): void
    {
        $line = ltrim($line);
        $forcedBreak = false;

        if (($this->options['hard_break_backslash'] ?? true) === true && str_ends_with($line, '\\')) {
            $line = substr($line, 0, -1);
            $forcedBreak = true;
        } elseif (($this->options['hard_break_spaces'] ?? true) === true && preg_match('/ {2,}$/', $line) === 1) {
            $line = rtrim($line);
            $forcedBreak = true;
        }

        $this->paragraph[] = ['text' => $line, 'forced_break' => $forcedBreak];

        if (($this->options['paragraph_mode'] ?? 'blank_lines') === 'line') {
            $this->flushParagraph();
        }
    }

    private function flushParagraph(): void
    {
        if ($this->paragraph === []) {
            return;
        }

        $parts = [];
        foreach ($this->paragraph as $line) {
            $parts[] = $this->inlineParser->parse($line['text']) . ($line['forced_break'] ? '<br>' : '');
        }

        $this->emit('<p>' . implode("\n", $parts) . '</p>');
        $this->paragraph = [];
    }

    /** @param list<string> $lines @param list<string> $classes */
    private function renderCodeFence(array $lines, string $language, array $classes): void
    {
        $preAttributes = [];
        if ($classes !== []) {
            $preAttributes['class'] = implode(' ', $classes);
        }

        $codeAttributes = [];
        if ($language !== '') {
            $codeAttributes['class'] = 'language-' . preg_replace('/[^A-Za-z0-9_+-]/', '', $language);
        }

        $code = htmlspecialchars(implode("\n", $lines), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $this->emit('<pre' . $this->attributeParser->render($preAttributes) . '><code'
            . $this->attributeParser->render($codeAttributes) . '>' . $code . '</code></pre>');
    }

    private function looksLikeRawHtml(string $line): bool
    {
        return preg_match('/^(?:<!--.*-->|<\/?[A-Za-z][^>]*>)$/us', $line) === 1;
    }

    private function closeAllBlocks(): void
    {
        while ($this->stack !== []) {
            $item = array_pop($this->stack);
            if ($item === null) {
                break;
            }
            $this->warnings[] = new Diagnostic(
                'warning',
                sprintf('Unclosed block <%s> was closed at end of document.', $item['tag']),
                $item['line'],
                'unclosed_block',
            );
            $this->emit('</' . $item['tag'] . '>');
        }
    }

    /** @param list<string> $warnings */
    private function addMetadataWarnings(array $warnings, int $line): void
    {
        foreach ($warnings as $warning) {
            $this->warnings[] = new Diagnostic('warning', $warning, $line, 'invalid_metadata');
        }
    }

    private function emit(string $html): void
    {
        $this->output[] = $html;
    }

    private function emitRaw(string $html): void
    {
        foreach (explode("\n", $html) as $line) {
            $this->output[] = $line;
        }
    }
}
