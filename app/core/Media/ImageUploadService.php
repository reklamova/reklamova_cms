<?php

declare(strict_types=1);

namespace Reklamova\Cms\Media;

final class ImageUploadService
{
    private const MAX_BYTES = 12 * 1024 * 1024;
    private const MAX_PIXELS = 60_000_000;

    /** @var array<string, string> */
    private const EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
        'image/avif' => 'avif',
    ];

    public function __construct(private \PDO $pdo, private string $publicPath)
    {
    }

    /**
     * @param array<string, mixed> $file
     * @return array{path: string, filename: string, mime_type: string, size: int, width: int, height: int}
     */
    public function store(array $file, string $collection = 'catalog'): array
    {
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            throw new \RuntimeException($this->uploadError($error));
        }

        $temporaryPath = (string) ($file['tmp_name'] ?? '');
        $size = (int) ($file['size'] ?? 0);
        if ($temporaryPath === '' || !is_uploaded_file($temporaryPath)) {
            throw new \RuntimeException('Nie udało się potwierdzić przesłanego pliku.');
        }
        if ($size < 1 || $size > self::MAX_BYTES) {
            throw new \RuntimeException('Zdjęcie może mieć maksymalnie 12 MB.');
        }

        $mime = $this->mimeType($temporaryPath);
        $extension = self::EXTENSIONS[$mime] ?? null;
        if ($extension === null) {
            throw new \RuntimeException('Dozwolone formaty zdjęć: JPG, PNG, WebP, GIF i AVIF.');
        }

        $dimensions = @getimagesize($temporaryPath);
        $width = (int) ($dimensions[0] ?? 0);
        $height = (int) ($dimensions[1] ?? 0);
        if ($width < 1 || $height < 1 || $width * $height > self::MAX_PIXELS) {
            throw new \RuntimeException('Plik nie jest poprawnym zdjęciem albo ma zbyt dużą rozdzielczość.');
        }

        $originalName = basename((string) ($file['name'] ?? 'zdjecie'));
        $baseName = $this->slugify((string) pathinfo($originalName, PATHINFO_FILENAME));
        $collection = $this->slugify($collection);
        $relativeDirectory = 'uploads/' . $collection . '/' . date('Y/m');
        $targetDirectory = rtrim($this->publicPath, '/\\') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativeDirectory);
        if (!is_dir($targetDirectory) && !mkdir($targetDirectory, 0775, true) && !is_dir($targetDirectory)) {
            throw new \RuntimeException('Nie udało się utworzyć katalogu dla zdjęć.');
        }

        $safeName = $baseName . '-' . bin2hex(random_bytes(6)) . '.' . $extension;
        $targetPath = $targetDirectory . DIRECTORY_SEPARATOR . $safeName;
        if (!move_uploaded_file($temporaryPath, $targetPath)) {
            throw new \RuntimeException('Nie udało się zapisać zdjęcia.');
        }

        $publicPath = '/' . $relativeDirectory . '/' . $safeName;
        try {
            $statement = $this->pdo->prepare('INSERT INTO cms_media (filename, path, mime_type, size) VALUES (?, ?, ?, ?)');
            $statement->execute([$originalName, $publicPath, $mime, $size]);
        } catch (\Throwable $exception) {
            @unlink($targetPath);
            throw $exception;
        }

        return [
            'path' => $publicPath,
            'filename' => $originalName,
            'mime_type' => $mime,
            'size' => $size,
            'width' => $width,
            'height' => $height,
        ];
    }

    private function mimeType(string $path): string
    {
        if (class_exists(\finfo::class)) {
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file($path);
            if (is_string($mime) && $mime !== '') {
                return strtolower($mime);
            }
        }

        return strtolower((string) (mime_content_type($path) ?: ''));
    }

    private function slugify(string $value): string
    {
        $value = strtr(mb_strtolower(trim($value), 'UTF-8'), [
            'ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n',
            'ó' => 'o', 'ś' => 's', 'ż' => 'z', 'ź' => 'z',
        ]);
        $value = preg_replace('/[^a-z0-9]+/i', '-', $value) ?: 'zdjecie';

        return trim($value, '-') ?: 'zdjecie';
    }

    private function uploadError(int $error): string
    {
        return match ($error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Zdjęcie przekracza limit rozmiaru serwera.',
            UPLOAD_ERR_PARTIAL => 'Zdjęcie zostało przesłane tylko częściowo.',
            UPLOAD_ERR_NO_FILE => 'Nie wybrano żadnego zdjęcia.',
            default => 'Przesyłanie zdjęcia nie powiodło się.',
        };
    }
}
