<?php
declare(strict_types=1);

namespace MurCMS\BanerinaPlugin;

if (!defined('MUR_CMS_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('No direct script access allowed.');
}

final class Translator
{
    private array $fallback = [];
    private array $messages = [];

    public function __construct(private readonly string $path)
    {
        $this->fallback = $this->load('ru');
        $this->messages = $this->fallback;
    }

    public function setLanguage(string $language): void
    {
        $language = preg_match('/^[a-z]{2}$/', $language) === 1 ? $language : 'ru';
        $messages = $this->load($language);
        $this->messages = $messages !== [] ? $messages : $this->fallback;
    }

    public function t(string $key, array $replace = []): string
    {
        $text = (string) ($this->messages[$key] ?? $this->fallback[$key] ?? $key);
        foreach ($replace as $name => $value) {
            $text = str_replace('{' . $name . '}', (string) $value, $text);
        }
        return $text;
    }

    private function load(string $language): array
    {
        $file = $this->path . '/' . $language . '.php';
        if (!is_file($file)) {
            return [];
        }
        $messages = require $file;
        return is_array($messages) ? $messages : [];
    }
}
