<?php

declare(strict_types=1);

if (!function_exists('privacy_head')) {
    function privacy_head(): string
    {
        return '<link rel="stylesheet" href="/assets/core/privacy/consent-manager.css"><script src="/assets/core/privacy/consent-manager.js" defer></script>';
    }
}

if (!function_exists('privacy_body_start')) {
    function privacy_body_start(): string
    {
        return '<div id="reklamova-privacy-root"></div>';
    }
}

if (!function_exists('privacy_body_end')) {
    function privacy_body_end(): string
    {
        return '';
    }
}

if (!function_exists('privacy_footer_link')) {
    function privacy_footer_link(string $label = 'Ustawienia prywatności'): string
    {
        return '<a href="/ustawienia-prywatności" data-reklamova-privacy-open>' . htmlspecialchars($label, ENT_QUOTES) . '</a>';
    }
}
