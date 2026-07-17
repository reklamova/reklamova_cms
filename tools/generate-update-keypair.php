<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

if (!extension_loaded('sodium')) {
    fwrite(STDERR, "sodium extension is required.\n");
    exit(1);
}

$pair = sodium_crypto_sign_keypair();
$secret = sodium_crypto_sign_secretkey($pair);
$public = sodium_crypto_sign_publickey($pair);

echo "REKLAMOVA_UPDATE_PRIVATE_KEY_B64=" . base64_encode($secret) . PHP_EOL;
echo "TRUSTED_PUBLIC_KEY_B64=" . base64_encode($public) . PHP_EOL;
