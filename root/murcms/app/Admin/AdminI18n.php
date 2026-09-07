<?php
declare(strict_types=1);

namespace Mur\Admin;

if (!defined('MUR_CMS_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('No direct script access allowed.');
}

final class AdminI18n
{
    private string $language;
    private array $messages = [];
    private array $fallback = [];

    public function __construct(
        private readonly string $langPath,
        private readonly array $config,
    ) {
        $this->fallback = $this->load('ru');
        $this->setLanguage((string) ($config['ui']['default_language'] ?? 'ru'));
    }

    public function setLanguage(string $language): void
    {
        $language = $this->normalizeLanguage($language);
        $available = $this->availableLanguages();

        if (!in_array($language, $available, true)) {
            $language = (string) ($this->config['ui']['default_language'] ?? 'ru');
        }

        $this->language = $this->normalizeLanguage($language);
        $this->messages = $this->load($this->language);
    }

    public function language(): string
    {
        return $this->language;
    }

    public function availableLanguages(): array
    {
        $languages = array_values(array_filter(
            (array) ($this->config['ui']['available_languages'] ?? ['ru']),
            static fn (mixed $value): bool => is_string($value) && preg_match('/^[a-z]{2}$/', $value)
        ));

        return $languages !== [] ? $languages : ['ru'];
    }

    public function t(string $key, array $replace = []): string
    {
        $message = (string) ($this->messages[$key] ?? $this->fallback[$key] ?? $key);

        foreach ($replace as $name => $value) {
            $message = str_replace('{' . $name . '}', (string) $value, $message);
        }

        return $message;
    }

    private function load(string $language): array
    {
        $file = $this->langPath . '/' . $this->normalizeLanguage($language) . '.php';

        if (!is_file($file)) {
            return [];
        }

        $messages = require $file;

        return is_array($messages) ? $messages : [];
    }

    private function normalizeLanguage(string $language): string
    {
        return preg_match('/^[a-z]{2}$/', $language) ? $language : 'ru';
    }
}
