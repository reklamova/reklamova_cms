<?php

declare(strict_types=1);

namespace Reklamova\Cms\Modules\Privacy\Integrations;

abstract class AbstractIntegration
{
    abstract public function key(): string;

    abstract public function name(): string;

    abstract public function provider(): string;

    abstract public function defaultCategory(): string;

    public function description(): string
    {
        return '';
    }

    public function fields(): array
    {
        return [];
    }

    public function defaultPlacement(): string
    {
        return 'head';
    }

    public function requiresConsentMode(): bool
    {
        return false;
    }

    public function cookies(): array
    {
        return [];
    }

    public function generate(array $settings): string
    {
        return '';
    }

    protected function cleanId(string $value): string
    {
        return preg_replace('/[^A-Za-z0-9_\-]/', '', trim($value)) ?: '';
    }
}
