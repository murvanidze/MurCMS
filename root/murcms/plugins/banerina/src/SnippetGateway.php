<?php
declare(strict_types=1);

namespace MurCMS\BanerinaPlugin;

if (!defined('MUR_CMS_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('No direct script access allowed.');
}

use Mur\Core\PluginContext;

final class SnippetGateway
{
    public function __construct(private readonly PluginContext $context)
    {
    }

    public function available(): bool
    {
        return $this->context->hooks->has('snippets.render');
    }

    public function registryAvailable(): bool
    {
        return $this->context->hooks->has('snippets.registry');
    }

    public function render(string $id, string $language): string
    {
        if (!$this->available() || preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]*$/', $id) !== 1) {
            return '';
        }

        $result = $this->context->hooks->filter('snippets.render', '', [
            'id' => $id,
            'language' => $language,
        ]);

        return is_string($result) ? $result : '';
    }

    public function registry(): ?array
    {
        if (!$this->registryAvailable()) {
            return null;
        }

        $result = $this->context->hooks->filter('snippets.registry', [], []);
        return is_array($result) ? $result : null;
    }
}
