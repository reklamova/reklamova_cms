<?php

declare(strict_types=1);

namespace Reklamova\Cms\Modules\Privacy;

final class ScriptManager
{
    public const PLACEMENTS = ['head', 'body_start', 'body_end'];
    public const SCOPE_TYPES = ['global', 'selected_pages', 'content_types', 'blog', 'products', 'cart', 'checkout', 'thank_you', 'urls'];

    public function __construct(private PrivacyRepository $repository)
    {
    }

    public function publicScripts(string $path): array
    {
        if ((bool) $this->repository->setting('emergency_disable_external_scripts', false)) {
            return [];
        }

        $scripts = [];
        foreach ($this->repository->scripts(true) as $script) {
            if ($this->matchesPath($script, $path)) {
                $scripts[] = $this->publicPayload($script);
            }
        }

        return $scripts;
    }

    public function publicPayload(array $script): array
    {
        return [
            'id' => (int) $script['id'],
            'name' => (string) $script['name'],
            'type' => (string) $script['type'],
            'provider' => (string) ($script['provider'] ?? ''),
            'category' => (string) ($script['category_slug'] ?? ''),
            'placement' => (string) $script['placement'],
            'code' => (string) ($script['code'] ?? ''),
            'externalUrl' => (string) ($script['external_url'] ?? ''),
            'async' => (bool) $script['async_enabled'],
            'defer' => (bool) $script['defer_enabled'],
            'priority' => (int) $script['priority'],
            'fingerprint' => hash('sha256', (string) ($script['code'] ?? '') . '|' . (string) ($script['external_url'] ?? '')),
        ];
    }

    public function validate(array $data): array
    {
        $errors = [];
        if (trim((string) ($data['name'] ?? '')) === '') {
            $errors[] = 'Podaj nazwe skryptu.';
        }
        if (!in_array((string) ($data['placement'] ?? ''), self::PLACEMENTS, true)) {
            $errors[] = 'Nieprawidlowe miejsce ładowania.';
        }
        if (!in_array((string) ($data['scope_type'] ?? ''), self::SCOPE_TYPES, true)) {
            $errors[] = 'Nieprawidlowy zakres działania.';
        }
        $code = (string) ($data['code'] ?? '');
        if (preg_match('/<\?(php|=)?/i', $code)) {
            $errors[] = 'Kod PHP jest zabroniony w Privacy Center.';
        }
        if (($data['type'] ?? 'custom') === 'custom' && empty($data['risk_acknowledged']) && empty($data['risk_acknowledged_at'])) {
            $errors[] = 'Potwierdź ostrzeżenie dotyczące custom script.';
        }

        return $errors;
    }

    public function riskWarnings(array $data): array
    {
        $warnings = [];
        $code = strtolower((string) ($data['code'] ?? '') . ' ' . (string) ($data['external_url'] ?? ''));
        foreach (['eval(', 'document.write', 'innerhtml', 'iframe', 'pixel', 'track', 'hotjar', 'clarity'] as $needle) {
            if (str_contains($code, $needle)) {
                $warnings[] = 'Wykryto potencjalnie ryzykowny fragment: ' . $needle;
            }
        }
        if (($data['category_slug'] ?? '') === 'necessary' && !empty($data['external_url'])) {
            $warnings[] = 'Zewnętrzny skrypt w kategorii niezbędnej wymaga szczególnej weryfikacji.';
        }

        return array_values(array_unique($warnings));
    }

    public function normalizeScriptInput(array $input, ?array $existing = null): array
    {
        $scopeRules = $this->linesToJson((string) ($input['scope_rules'] ?? ''));
        $excludedRules = $this->linesToJson((string) ($input['excluded_rules'] ?? ''));

        return [
            'id' => $existing['id'] ?? ($input['id'] ?? null),
            'name' => trim((string) ($input['name'] ?? '')),
            'type' => (string) ($input['type'] ?? 'custom'),
            'provider' => trim((string) ($input['provider'] ?? '')),
            'category_id' => (int) ($input['category_id'] ?? 0),
            'placement' => (string) ($input['placement'] ?? 'body_end'),
            'scope_type' => (string) ($input['scope_type'] ?? 'global'),
            'scope_rules_json' => $scopeRules,
            'excluded_rules_json' => $excludedRules,
            'code' => (string) ($input['code'] ?? ''),
            'external_url' => trim((string) ($input['external_url'] ?? '')) ?: null,
            'async_enabled' => !empty($input['async_enabled']) ? 1 : 0,
            'defer_enabled' => !empty($input['defer_enabled']) ? 1 : 0,
            'priority' => (int) ($input['priority'] ?? 100),
            'is_active' => !empty($input['is_active']) ? 1 : 0,
            'is_test_mode' => !empty($input['is_test_mode']) ? 1 : 0,
            'risk_acknowledged_at' => !empty($input['risk_acknowledged']) ? date('Y-m-d H:i:s') : ($existing['risk_acknowledged_at'] ?? null),
            'settings' => [
                'purpose' => trim((string) ($input['purpose'] ?? '')),
                'expected_cookies' => trim((string) ($input['expected_cookies'] ?? '')),
                'retention' => trim((string) ($input['retention'] ?? '')),
                'third_country_transfer' => !empty($input['third_country_transfer']),
                'admin_note' => trim((string) ($input['admin_note'] ?? '')),
            ],
            'risk_acknowledged' => !empty($input['risk_acknowledged']) || !empty($existing['risk_acknowledged_at']),
        ];
    }

    public function presets(): array
    {
        return [
            'gtm' => ['name' => 'Google Tag Manager', 'provider' => 'Google', 'category' => 'analytics', 'placement' => 'head', 'requires_consent_mode' => true],
            'ga4' => ['name' => 'Google Analytics 4', 'provider' => 'Google', 'category' => 'analytics', 'placement' => 'head', 'requires_consent_mode' => true],
            'google_ads' => ['name' => 'Google Ads', 'provider' => 'Google', 'category' => 'marketing', 'placement' => 'head', 'requires_consent_mode' => true],
            'meta_pixel' => ['name' => 'Meta Pixel', 'provider' => 'Meta', 'category' => 'marketing', 'placement' => 'head', 'requires_consent_mode' => false],
            'clarity' => ['name' => 'Microsoft Clarity', 'provider' => 'Microsoft', 'category' => 'analytics', 'placement' => 'head', 'requires_consent_mode' => false],
            'hotjar' => ['name' => 'Hotjar', 'provider' => 'Hotjar', 'category' => 'analytics', 'placement' => 'head', 'requires_consent_mode' => false],
            'tiktok' => ['name' => 'TikTok Pixel', 'provider' => 'TikTok', 'category' => 'marketing', 'placement' => 'head', 'requires_consent_mode' => false],
            'linkedin' => ['name' => 'LinkedIn Insight Tag', 'provider' => 'LinkedIn', 'category' => 'marketing', 'placement' => 'head', 'requires_consent_mode' => false],
            'google_maps' => ['name' => 'Google Maps embed control', 'provider' => 'Google', 'category' => 'functional', 'placement' => 'body_end', 'requires_consent_mode' => false],
            'youtube' => ['name' => 'YouTube embed control', 'provider' => 'Google', 'category' => 'functional', 'placement' => 'body_end', 'requires_consent_mode' => false],
            'custom' => ['name' => 'Custom script', 'provider' => '', 'category' => 'marketing', 'placement' => 'body_end', 'requires_consent_mode' => false],
        ];
    }

    private function matchesPath(array $script, string $path): bool
    {
        $excluded = json_decode((string) ($script['excluded_rules_json'] ?? '[]'), true) ?: [];
        foreach ($excluded as $rule) {
            if ($this->pathMatchesRule($path, (string) $rule)) {
                return false;
            }
        }

        $scopeType = (string) ($script['scope_type'] ?? 'global');
        if ($scopeType === 'global') {
            return true;
        }

        $rules = json_decode((string) ($script['scope_rules_json'] ?? '[]'), true) ?: [];
        foreach ($rules as $rule) {
            if ($this->pathMatchesRule($path, (string) $rule)) {
                return true;
            }
        }

        return match ($scopeType) {
            'blog' => str_starts_with(trim($path, '/'), 'poradnik') || str_starts_with(trim($path, '/'), 'blog'),
            'cart' => trim($path, '/') === 'koszyk',
            'checkout' => trim($path, '/') === 'checkout' || trim($path, '/') === 'zamówienie',
            'thank_you' => str_contains(trim($path, '/'), 'dziekujemy') || str_contains(trim($path, '/'), 'thank'),
            default => false,
        };
    }

    private function pathMatchesRule(string $path, string $rule): bool
    {
        $path = '/' . trim($path, '/');
        $rule = '/' . trim($rule, '/');
        if ($rule === '/') {
            return $path === '/';
        }
        if (str_ends_with($rule, '*')) {
            return str_starts_with($path, rtrim($rule, '*'));
        }

        return $path === $rule;
    }

    private function linesToJson(string $value): string
    {
        $lines = array_filter(array_map('trim', preg_split('/\R/', $value) ?: []));

        return json_encode(array_values($lines), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
