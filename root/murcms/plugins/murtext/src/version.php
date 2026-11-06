<?php

declare(strict_types=1);

namespace MurCMS\MurTextPlugin;

if (!defined('MUR_CMS_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('No direct script access allowed.');
}

/**
 * Canonical MurText integration metadata.
 */
final class Version
{
    public const PLUGIN = '0.2.4';
    public const DIALECT = 'murtext-1';
}
