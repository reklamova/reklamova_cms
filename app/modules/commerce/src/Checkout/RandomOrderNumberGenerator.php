<?php

declare(strict_types=1);

namespace Reklamova\Cms\Commerce\Checkout;

final class RandomOrderNumberGenerator implements OrderNumberGeneratorInterface
{
    public function generate(string $storeCode): string
    {
        $prefix = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $storeCode));
        $prefix = substr($prefix !== '' ? $prefix : 'ORDER', 0, 8);

        return $prefix . '-' . gmdate('Ymd') . '-' . strtoupper(bin2hex(random_bytes(4)));
    }
}
