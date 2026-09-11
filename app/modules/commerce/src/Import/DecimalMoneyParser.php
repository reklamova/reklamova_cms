<?php

declare(strict_types=1);

namespace Reklamova\Cms\Commerce\Import;

final class DecimalMoneyParser
{
    public function parse(mixed $value, int $scale = 2): ?int
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }
        if ($scale < 0 || $scale > 6) {
            throw new \InvalidArgumentException('Unsupported money scale.');
        }

        $normalized = str_replace(',', '.', trim((string) $value));
        if (preg_match('/^(-?)(\d+)(?:\.(\d+))?$/', $normalized, $matches) !== 1) {
            throw new \InvalidArgumentException('Invalid decimal money value.');
        }

        $negative = ($matches[1] ?? '') === '-';
        $whole = ltrim($matches[2], '0');
        $whole = $whole === '' ? '0' : $whole;
        $fraction = $matches[3] ?? '';
        $fraction = substr($fraction . str_repeat('0', $scale), 0, $scale);
        $nextDigit = (int) substr(($matches[3] ?? '') . '0', $scale, 1);
        $multiplier = 10 ** $scale;
        $minor = ((int) $whole * $multiplier) + (int) ($fraction === '' ? 0 : $fraction);
        if ($nextDigit >= 5) {
            $minor++;
        }

        return $negative ? -$minor : $minor;
    }
}
