<?php

declare(strict_types=1);

namespace Reklamova\Cms\Commerce\Checkout;

interface OrderNumberGeneratorInterface
{
    public function generate(string $storeCode): string;
}
