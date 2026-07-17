<?php

declare(strict_types=1);

$config = require dirname(__DIR__) . '/app/config/license.php';

foreach ($config as $key => $value) {
    if ($key === 'site_key') {
        $value = '***';
    } elseif (!is_scalar($value)) {
        $value = gettype($value);
    }

    echo $key . '=' . $value . PHP_EOL;
}
