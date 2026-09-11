<?php

declare(strict_types=1);

namespace Reklamova\Cms\Media;

final class MediaUploadPolicy
{
    private const ALLOWED_MIME_TYPES = [
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
        'gif' => ['image/gif'],
        'webp' => ['image/webp'],
        'avif' => ['image/avif'],
        'pdf' => ['application/pdf'],
    ];

    public function __construct(private int $maxBytes = 26214400)
    {
        if ($this->maxBytes <= 0) {
            throw new \InvalidArgumentException('Limit uploadu musi być większy od zera.');
        }
    }

    /**
     * @param array<string, mixed> $file
     * @return array{original_name: string, extension: string, mime_type: string, size: int}
     */
    public function validate(array $file): array
    {
        if ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new \RuntimeException('Upload pliku nie powiódł się.');
        }

        $temporaryPath = (string) ($file['tmp_name'] ?? '');
        if ($temporaryPath === '' || !is_file($temporaryPath)) {
            throw new \RuntimeException('Nie znaleziono przesłanego pliku.');
        }

        $originalName = basename(str_replace('\\', '/', (string) ($file['name'] ?? '')));
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if ($originalName === '' || !isset(self::ALLOWED_MIME_TYPES[$extension])) {
            throw new \RuntimeException('Ten typ pliku nie jest dozwolony.');
        }

        $size = filesize($temporaryPath);
        if (!is_int($size) || $size <= 0 || $size > $this->maxBytes) {
            throw new \RuntimeException('Plik jest pusty albo przekracza dozwolony rozmiar.');
        }

        $mimeType = mime_content_type($temporaryPath);
        if (!is_string($mimeType) || !in_array(strtolower($mimeType), self::ALLOWED_MIME_TYPES[$extension], true)) {
            throw new \RuntimeException('Zawartość pliku nie zgadza się z jego rozszerzeniem.');
        }

        return [
            'original_name' => $originalName,
            'extension' => $extension,
            'mime_type' => strtolower($mimeType),
            'size' => $size,
        ];
    }
}
