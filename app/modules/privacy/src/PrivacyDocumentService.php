<?php

declare(strict_types=1);

namespace Reklamova\Cms\Modules\Privacy;

final class PrivacyDocumentService
{
    public function renderTemplate(string $content, array $variables): string
    {
        foreach ($variables as $key => $value) {
            $content = str_replace('{{' . $key . '}}', (string) $value, $content);
        }

        return $content;
    }

    public function baseVariables(PrivacyRepository $repository): array
    {
        $tools = array_map(
            static fn (array $script): string => trim(($script['provider'] ?: 'Custom') . ' - ' . $script['name']),
            $repository->scripts(true)
        );
        $cookies = array_map(
            static fn (array $cookie): string => trim($cookie['name'] . ' (' . ($cookie['provider'] ?: 'wlasne') . ')'),
            $repository->cookies()
        );
        $categories = array_map(
            static fn (array $category): string => $category['name'],
            $repository->categories(true)
        );

        return [
            'administrator_name' => (string) $repository->setting('administrator_name', '[uzupelnij administratora danych]'),
            'administrator_address' => (string) $repository->setting('administrator_address', '[uzupelnij adres]'),
            'administrator_nip' => (string) $repository->setting('administrator_nip', '[uzupelnij NIP]'),
            'privacy_email' => (string) $repository->setting('privacy_email', '[uzupelnij e-mail]'),
            'privacy_phone' => (string) $repository->setting('privacy_phone', '[uzupelnij telefon]'),
            'iod' => (string) $repository->setting('iod', 'nie wyznaczono'),
            'external_tools' => implode(', ', $tools) ?: 'brak aktywnych narzędzi zewnętrznych',
            'cookies' => implode(', ', $cookies) ?: 'brak wpisow w rejestrże cookies',
            'consent_categories' => implode(', ', $categories),
            'last_update' => date('Y-m-d'),
        ];
    }
}
