<?php

declare(strict_types=1);

namespace MurCMS\MurConsent;

use Mur\Core\PluginContext;

if (!defined('MUR_CMS_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('No direct script access allowed.');
}

final class Plugin
{
    public const VERSION = '1.0.3';

    /** @var array<string,mixed> */
    private array $runtime;

    public function __construct(
        private readonly PluginContext $context,
        private readonly string $pluginPath,
        private readonly string $configPath,
        private readonly string $defaultsPath,
        private readonly string $legacyPath,
    ) {
        $this->runtime = Config::load($configPath, $defaultsPath, $legacyPath)['runtime'];
    }

    public function registerFrontend(): void
    {
        if (!Config::isReady($this->runtime) && ($this->runtime['testMode'] ?? false) !== true) return;
        $this->context->hooks->add('layout.head', [$this, 'head']);
        $this->context->hooks->add('layout.footer', [$this, 'footer']);
        $this->context->hooks->add('layout.body_end', [$this, 'bodyEnd']);
    }

    public function head(string $html): string
    {
        return $html . "\n" . '<link rel="stylesheet" href="' . mur_escape(mur_asset_url('plugins/murconsent/murconsent.css')) . '">';
    }

    public function footer(string $html, array $layoutContext = []): string
    {
        if (($this->runtime['autoFooterLink'] ?? true) !== true) return $html;
        $language = LanguageResolver::normalize($layoutContext['language'] ?? null);
        $text = Services::translations($this->pluginPath, $this->runtime, $language);
        $label = (string) ($text['settings_title'] ?? 'Privacy settings');
        $control = '<div class="murconsent-footer-control"><button type="button" class="murconsent-settings-link" data-murconsent-open>' . mur_escape($label) . '</button></div>';
        $position = strripos($html, '</footer>');
        if ($position === false) return $html . $control;
        return substr($html, 0, $position) . $control . substr($html, $position);
    }

    public function bodyEnd(string $html, array $layoutContext = []): string
    {
        $language = LanguageResolver::normalize($layoutContext['language'] ?? null);
        $preview = $this->isAuthorizedPreview();
        $payload = [
            'pluginVersion' => self::VERSION,
            'schemaVersion' => (string) ($this->runtime['schemaVersion'] ?? '1.0'),
            'cookieName' => (string) ($this->runtime['cookieName'] ?? 'murconsent_preferences'),
            'days' => (int) ($this->runtime['days'] ?? 365),
            'language' => $language,
            'ga4Enabled' => (bool) ($this->runtime['ga4Enabled'] ?? false),
            'ga4Id' => (string) ($this->runtime['ga4Id'] ?? ''),
            'adsEnabled' => (bool) ($this->runtime['adsEnabled'] ?? false),
            'adsId' => (string) ($this->runtime['adsId'] ?? ''),
            'adsLabel' => (string) ($this->runtime['adsLabel'] ?? ''),
            'gtmEnabled' => (bool) ($this->runtime['gtmEnabled'] ?? false),
            'gtmId' => (string) ($this->runtime['gtmId'] ?? ''),
            'gtmAnalyticsEnabled' => (bool) ($this->runtime['gtmAnalyticsEnabled'] ?? false),
            'gtmMarketingEnabled' => (bool) ($this->runtime['gtmMarketingEnabled'] ?? false),
            'capabilities' => $this->runtime['capabilities'] ?? [],
            'flagCounterHtml' => (string) ($this->runtime['flagCounterHtml'] ?? ''),
            'previewMode' => $preview || (bool) ($this->runtime['testMode'] ?? false),
            'reloadOnWithdraw' => (bool) ($this->runtime['reloadOnWithdraw'] ?? true),
        ];
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        if (!is_string($json)) return $html;
        $encoded = base64_encode($json);
        return $html
            . FrontendRenderer::render($this->pluginPath, $this->runtime, $language)
            . '<script defer src="' . mur_escape(mur_asset_url('plugins/murconsent/murconsent.js')) . '" data-murconsent-config="' . mur_escape($encoded) . '"></script>';
    }

    private function isAuthorizedPreview(): bool
    {
        if ((string) ($_GET['murconsent_preview'] ?? '') !== '1') return false;
        if (!class_exists(\Mur\Admin\AdminAuth::class)) return false;
        $adminConfigPath = $this->context->path('config/admin.php');
        if (!is_file($adminConfigPath)) return false;
        $config = require $adminConfigPath;
        if (!is_array($config)) return false;
        try {
            $auth = new \Mur\Admin\AdminAuth($config);
            return $auth->hasSessionCookie() && $auth->isLoggedIn();
        } catch (\Throwable) {
            return false;
        }
    }
}
