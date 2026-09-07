<?php
declare(strict_types=1);

namespace Mur\Core;

if (!defined('MUR_CMS_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('No direct script access allowed.');
}

final class Hooks
{
    private array $listeners = [];
    private array $definitions = [];
    private int $sequence = 0;

    public function add(string $name, callable $callback, int $priority = 10): void
    {
        $this->listeners[$name][] = [
            'callback' => $callback,
            'priority' => $priority,
            'sequence' => $this->sequence++,
        ];

        usort(
            $this->listeners[$name],
            static fn (array $a, array $b): int => [$a['priority'], $a['sequence']] <=> [$b['priority'], $b['sequence']],
        );
    }

    public function has(string $name): bool
    {
        return ($this->listeners[$name] ?? []) !== [];
    }

    public function register(string $name, array $definition): void
    {
        $this->definitions[$name][] = $definition;
    }

    public function definitions(string $name): array
    {
        return $this->definitions[$name] ?? [];
    }

    public function filter(string $name, mixed $value, array $context = []): mixed
    {
        foreach ($this->listeners[$name] ?? [] as $listener) {
            $value = ($listener['callback'])($value, $context, $this);
        }

        return $value;
    }

    public function render(string $name, array $context = []): string
    {
        return (string) $this->filter($name, '', $context);
    }
}
