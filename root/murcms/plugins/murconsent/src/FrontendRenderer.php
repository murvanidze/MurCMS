<?php

declare(strict_types=1);

namespace MurCMS\MurConsent;

if (!defined('MUR_CMS_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('No direct script access allowed.');
}

final class FrontendRenderer
{
    public static function render(string $pluginPath, array $runtime, string $language): string
    {
        $language = LanguageResolver::normalize($language);
        $text = Services::translations($pluginPath, $runtime, $language);
        $services = Services::active($runtime, $text, $language);
        $cap = is_array($runtime['capabilities'] ?? null) ? $runtime['capabilities'] : [];
        $policyUrl = (string) ($runtime['policyUrls'][$language] ?? '');
        $policy = $policyUrl !== '' ? '<a href="' . self::e($policyUrl) . '">' . self::e($text['privacy_policy'] ?? 'Privacy policy') . '</a>' : '';
        $width = in_array(($runtime['bannerWidth'] ?? ''), ['container', 'full'], true) ? (string) $runtime['bannerWidth'] : 'container';
        $container = (int) ($runtime['containerWidth'] ?? 1180);

        $html = '<div class="murconsent" id="murconsent-root" data-murconsent-root>';
        $html .= '<section class="murconsent-banner murconsent-banner-' . self::e($width) . '" data-murconsent-banner role="dialog" aria-modal="false" aria-labelledby="murconsent-banner-title" aria-describedby="murconsent-banner-text" hidden>';
        $html .= '<div class="murconsent-banner-inner" style="--murconsent-container-width:' . $container . 'px">';
        $html .= '<div class="murconsent-banner-content"><h2 class="murconsent-title" id="murconsent-banner-title">' . self::e($text['banner_title'] ?? '') . '</h2>';
        $html .= '<p class="murconsent-text" id="murconsent-banner-text">' . self::plain($text['banner_text'] ?? '') . '</p>';
        if ($policy !== '') $html .= '<p class="murconsent-links">' . $policy . '</p>';
        $html .= '</div><div class="murconsent-actions murconsent-actions-banner">';
        $html .= '<button class="murconsent-button murconsent-button-primary" type="button" data-murconsent-accept-all>' . self::e($text['accept_all'] ?? '') . '</button>';
        $html .= '<button class="murconsent-button murconsent-button-primary" type="button" data-murconsent-reject-all>' . self::e($text['reject_all'] ?? '') . '</button>';
        $html .= '<button class="murconsent-button murconsent-button-secondary murconsent-button-settings" type="button" data-murconsent-customize aria-label="' . self::e($text['customize'] ?? '') . '" title="' . self::e($text['customize'] ?? '') . '">' . self::gear() . '<span class="murconsent-sr-only">' . self::e($text['customize'] ?? '') . '</span></button>';
        $html .= '</div></div></section>';

        $html .= '<div class="murconsent-overlay" data-murconsent-overlay hidden><section class="murconsent-dialog" data-murconsent-dialog role="dialog" aria-modal="true" aria-labelledby="murconsent-dialog-title" aria-describedby="murconsent-dialog-intro" tabindex="-1">';
        $html .= '<header class="murconsent-dialog-header"><h2 class="murconsent-dialog-title" id="murconsent-dialog-title">' . self::e($text['settings_title'] ?? '') . '</h2><button class="murconsent-close" type="button" data-murconsent-close aria-label="' . self::e($text['close'] ?? '') . '">&times;</button></header>';
        $html .= '<div class="murconsent-dialog-scroll"><p class="murconsent-dialog-intro" id="murconsent-dialog-intro">' . self::e($text['settings_intro'] ?? '') . '</p>';
        $html .= self::necessary($services['necessary'], $text);
        if (($cap['analytics'] ?? false) === true) $html .= self::toggle('analytics', $text['analytics_title'] ?? '', $text['analytics_toggle'] ?? '', $text['analytics_desc'] ?? '', $services['analytics'], $text);
        if (($cap['marketing'] ?? false) === true) $html .= self::toggle('marketing', $text['marketing_title'] ?? '', $text['marketing_toggle'] ?? '', $text['marketing_desc'] ?? '', $services['marketing'], $text);
        if (($cap['flagCounter'] ?? false) === true) $html .= self::toggle('flag-counter', $text['flag_title'] ?? '', $text['flag_toggle'] ?? '', $text['flag_desc'] ?? '', $services['flag_counter'], $text);
        if ($policy !== '') $html .= '<p class="murconsent-links murconsent-links-dialog">' . $policy . '</p>';
        $html .= '<p class="murconsent-status" data-murconsent-status data-murconsent-saved-message="' . self::e($text['saved'] ?? '') . '" aria-live="polite"></p></div>';
        $html .= '<footer class="murconsent-actions murconsent-actions-dialog"><button class="murconsent-button murconsent-button-primary" type="button" data-murconsent-save>' . self::e($text['save'] ?? '') . '</button><button class="murconsent-button murconsent-button-secondary" type="button" data-murconsent-accept-all>' . self::e($text['accept_all'] ?? '') . '</button><button class="murconsent-button murconsent-button-secondary" type="button" data-murconsent-reject-all-dialog>' . self::e($text['reject_all'] ?? '') . '</button></footer>';
        $html .= '</section></div></div>';
        return $html;
    }

    /** @param list<array<string,string>> $services */
    private static function necessary(array $services, array $text): string
    {
        return '<section class="murconsent-category murconsent-category-necessary" aria-labelledby="murconsent-necessary-title"><div class="murconsent-category-heading"><div class="murconsent-category-copy"><h3 class="murconsent-category-title" id="murconsent-necessary-title">' . self::e($text['necessary_title'] ?? '') . '</h3><p class="murconsent-category-description">' . self::plain($text['necessary_desc'] ?? '') . '</p></div><span class="murconsent-always-on">' . self::e($text['always_on'] ?? '') . '</span></div>' . self::details($services, $text) . '</section>';
    }

    /** @param list<array<string,string>> $services */
    private static function toggle(string $id, string $title, string $label, string $description, array $services, array $text): string
    {
        $safe = self::e($id);
        return '<section class="murconsent-category" aria-labelledby="murconsent-' . $safe . '-title"><div class="murconsent-category-heading"><div class="murconsent-category-copy"><h3 class="murconsent-category-title" id="murconsent-' . $safe . '-title">' . self::e($title) . '</h3><p class="murconsent-category-description" id="murconsent-' . $safe . '-description">' . self::e($description) . '</p></div><label class="murconsent-switch"><span class="murconsent-sr-only">' . self::e($label) . '</span><input class="murconsent-switch-input" type="checkbox" data-murconsent-choice="' . $safe . '" aria-describedby="murconsent-' . $safe . '-description"><span class="murconsent-switch-visual" aria-hidden="true"></span></label></div>' . self::details($services, $text) . '</section>';
    }

    /** @param list<array<string,string>> $services */
    private static function details(array $services, array $text): string
    {
        $html = '';
        foreach ($services as $service) {
            $html .= '<details class="murconsent-service"><summary class="murconsent-service-summary">' . self::e($text['service_details'] ?? '') . ': ' . self::e($service['name'] ?? '') . '</summary><dl class="murconsent-service-list">';
            $rows = [
                ($text['provider_label'] ?? 'Provider') => ($service['provider'] ?? ''),
                ($text['purpose_label'] ?? 'Purpose') => ($service['purpose'] ?? ''),
                ($text['domains_label'] ?? 'Domains') => ($service['domains'] ?? ''),
                ($text['storage_label'] ?? 'Storage') => ($service['storage'] ?? ''),
                ($text['retention_label'] ?? 'Retention') => ($service['retention'] ?? ''),
            ];
            foreach ($rows as $label => $value) {
                if ((string) $value === '') continue;
                $html .= '<div class="murconsent-service-row"><dt>' . self::e((string) $label) . '</dt><dd>' . self::plain((string) $value) . '</dd></div>';
            }
            $html .= '</dl>';
            if (($service['policy_url'] ?? '') !== '') $html .= '<p class="murconsent-service-policy"><a href="' . self::e($service['policy_url']) . '" target="_blank" rel="noopener noreferrer">' . self::e($text['provider_policy'] ?? '') . '</a></p>';
            $html .= '</details>';
        }
        return $html;
    }

    private static function gear(): string
    {
        return '<svg class="murconsent-settings-icon" viewBox="0 0 24 24" width="22" height="22" aria-hidden="true" focusable="false">'
            . '<g fill="currentColor">'
            . '<rect x="10.35" y="1.15" width="3.3" height="5.2" rx=".75"/>'
            . '<rect x="10.35" y="17.65" width="3.3" height="5.2" rx=".75"/>'
            . '<rect x="17.65" y="10.35" width="5.2" height="3.3" rx=".75"/>'
            . '<rect x="1.15" y="10.35" width="5.2" height="3.3" rx=".75"/>'
            . '<g transform="rotate(45 12 12)">'
            . '<rect x="10.35" y="1.15" width="3.3" height="5.2" rx=".75"/>'
            . '<rect x="10.35" y="17.65" width="3.3" height="5.2" rx=".75"/>'
            . '<rect x="17.65" y="10.35" width="5.2" height="3.3" rx=".75"/>'
            . '<rect x="1.15" y="10.35" width="5.2" height="3.3" rx=".75"/>'
            . '</g>'
            . '<path fill-rule="evenodd" d="M12 4.4a7.6 7.6 0 1 1 0 15.2 7.6 7.6 0 0 1 0-15.2Zm0 4.55a3.05 3.05 0 1 0 0 6.1 3.05 3.05 0 0 0 0-6.1Z"/>'
            . '</g></svg>';
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function plain(string $value): string
    {
        return nl2br(self::e($value), false);
    }
}
