<?php

$required = ['pdo_mysql', 'openssl', 'curl', 'zip', 'mbstring', 'json', 'fileinfo', 'sodium'];
$missing = array_values(array_filter($required, static fn (string $extension): bool => !extension_loaded($extension)));

if (version_compare(PHP_VERSION, '8.3.0', '<')) {
    fwrite(STDERR, "PHP 8.3+ is required. Current: " . PHP_VERSION . PHP_EOL);
    exit(1);
}

if ($missing !== []) {
    fwrite(STDERR, "Missing PHP extensions: " . implode(', ', $missing) . PHP_EOL);
    exit(1);
}

echo "Reklamova CMS requirements OK." . PHP_EOL;
