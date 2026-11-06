<?php
declare(strict_types=1);

namespace Mur\Core;

if (!defined('MUR_CMS_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('No direct script access allowed.');
}

final class DocumentParser
{
    public function parse(string $filePath, string $collection, string $language): Document
    {
        $raw = file_get_contents($filePath);

        if ($raw === false) {
            throw new \RuntimeException('Unable to read file: ' . $filePath);
        }

        $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw) ?? $raw;
        [$header, $body] = $this->split($raw);

        return new Document(
            collection: $collection,
            language: $language,
            filePath: $filePath,
            fileName: basename($filePath),
            meta: $this->parseHeader($header),
            body: ltrim($body),
        );
    }

    private function split(string $raw): array
    {
        $parts = preg_split('/^\s*---\s*$/m', $raw, 2);

        if (!is_array($parts) || count($parts) < 2) {
            return [$raw, ''];
        }

        return [$parts[0], $parts[1]];
    }

    public function parseHeader(string $header): array
    {
        $meta = [];

        foreach (preg_split('/\R/u', trim($header)) ?: [] as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (!preg_match('/^([A-Za-z0-9_.-]+):\s*(.*)$/u', $line, $match)) {
                continue;
            }

            $meta[$match[1]] = trim($match[2]);
        }

        return $meta;
    }

    public function serialize(array $meta, string $body): string
    {
        $header = '';
        foreach ($meta as $key => $value) {
            if (preg_match('/^[A-Za-z0-9_.-]+$/D', (string) $key) && is_scalar($value)) {
                $value = trim((string) $value);
                if (preg_match('/[\r\n\x00]/', $value)) {
                    throw new \InvalidArgumentException('Metadata values must occupy one line.');
                }
                $header .= $key . ': ' . $value . "\n";
            }
        }
        return rtrim($header) . "\n---\n\n" . rtrim($body) . "\n";
    }
}
