<?php

declare(strict_types=1);

namespace MurCMS\MurText;

if (!defined('MUR_CMS_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('No direct script access allowed.');
}

/**
 * Represents a parser diagnostic tied to a source line.
 */
final readonly class Diagnostic
{
    public function __construct(
        public string $level,
        public string $message,
        public ?int $line = null,
        public ?string $code = null,
    ) {
    }

    /** @return array{level:string,message:string,line:?int,code:?string} */
    public function toArray(): array
    {
        return [
            'level' => $this->level,
            'message' => $this->message,
            'line' => $this->line,
            'code' => $this->code,
        ];
    }
}

/**
 * Immutable result returned by MurText parsing and validation.
 */
final readonly class ParseResult
{
    /**
     * @param list<Diagnostic> $warnings
     * @param list<Diagnostic> $errors
     */
    public function __construct(
        public string $html,
        public array $warnings = [],
        public array $errors = [],
    ) {
    }

    public function isValid(): bool
    {
        return $this->errors === [];
    }

    /** @return list<array{level:string,message:string,line:?int,code:?string}> */
    public function warningData(): array
    {
        return array_map(
            static fn (Diagnostic $diagnostic): array => $diagnostic->toArray(),
            $this->warnings,
        );
    }

    /** @return list<array{level:string,message:string,line:?int,code:?string}> */
    public function errorData(): array
    {
        return array_map(
            static fn (Diagnostic $diagnostic): array => $diagnostic->toArray(),
            $this->errors,
        );
    }
}
