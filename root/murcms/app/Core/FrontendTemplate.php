<?php
declare(strict_types=1);

namespace Mur\Core;

if (!defined('MUR_CMS_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('No direct script access allowed.');
}

interface FrontendTemplate
{
    public function name(): string;

    public function assetUrl(string $path): string;

    public function documentFrame(Document $document, string $body): string;

    public function renderLayout(
        string $language,
        ?Document $document,
        string $body,
        array $meta,
        ?array $pagination = null,
    ): string;
}
