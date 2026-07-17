<?php

declare(strict_types=1);

namespace Reklamova\Cms\Updates;

use RuntimeException;
use ZipArchive;

final class PackageVerifier
{
    public function __construct(private string $publicKey)
    {
    }

    public function verify(string $zipPath, string $expectedSha256, string $signature): array
    {
        if (!is_file($zipPath)) {
            throw new RuntimeException('Update package does not exist.');
        }

        $actualHash = hash_file('sha256', $zipPath);
        if (!hash_equals($expectedSha256, $actualHash)) {
            throw new RuntimeException('Update package checksum mismatch.');
        }

        $manifest = $this->readManifest($zipPath);
        $message = $actualHash . "\n" . json_encode($manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $decodedSignature = base64_decode($signature, true);
        $decodedPublicKey = base64_decode($this->publicKey, true);

        if ($decodedSignature === false || $decodedPublicKey === false || strlen($decodedPublicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            throw new RuntimeException('Update package signing key is not configured correctly.');
        }

        if (!sodium_crypto_sign_verify_detached($decodedSignature, $message, $decodedPublicKey)) {
            throw new RuntimeException('Update package signature is invalid.');
        }

        return $manifest;
    }

    private function readManifest(string $zipPath): array
    {
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException('Cannot open update package.');
        }

        $manifest = $zip->getFromName('manifest.json');
        $zip->close();

        if ($manifest === false) {
            throw new RuntimeException('Update package manifest is missing.');
        }

        return json_decode($manifest, true, 512, JSON_THROW_ON_ERROR);
    }
}
