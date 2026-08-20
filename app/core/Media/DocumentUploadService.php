<?php

declare(strict_types=1);

namespace Reklamova\Cms\Media;

final class DocumentUploadService
{
    private const MAX_BYTES = 25 * 1024 * 1024;

    public function __construct(private \PDO $pdo, private string $publicPath)
    {
    }

    /** @param array<string, mixed> $file
     *  @return array{path:string,filename:string,mime_type:string,size:int}
     */
    public function store(array $file, string $collection = 'catalog-documents'): array
    {
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            throw new \RuntimeException(match ($error) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'PDF przekracza limit rozmiaru serwera.',
                UPLOAD_ERR_PARTIAL => 'PDF został przesłany tylko częściowo.',
                UPLOAD_ERR_NO_FILE => 'Nie wybrano pliku PDF.',
                default => 'Przesyłanie PDF nie powiodło się.',
            });
        }
        $temporaryPath = (string) ($file['tmp_name'] ?? '');
        $size = (int) ($file['size'] ?? 0);
        if ($temporaryPath === '' || !is_uploaded_file($temporaryPath)) {
            throw new \RuntimeException('Nie udało się potwierdzić przesłanego pliku.');
        }
        if ($size < 1 || $size > self::MAX_BYTES) {
            throw new \RuntimeException('PDF może mieć maksymalnie 25 MB.');
        }
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = strtolower((string) $finfo->file($temporaryPath));
        $signature = (string) file_get_contents($temporaryPath, false, null, 0, 5);
        if ($mime !== 'application/pdf' || $signature !== '%PDF-') {
            throw new \RuntimeException('Dozwolone są wyłącznie prawidłowe pliki PDF.');
        }
        $originalName = basename((string) ($file['name'] ?? 'dokument.pdf'));
        $base = $this->slugify((string) pathinfo($originalName, PATHINFO_FILENAME));
        $relativeDirectory = 'uploads/' . $this->slugify($collection) . '/' . date('Y/m');
        $targetDirectory = rtrim($this->publicPath, '/\\') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativeDirectory);
        if (!is_dir($targetDirectory) && !mkdir($targetDirectory, 0775, true) && !is_dir($targetDirectory)) {
            throw new \RuntimeException('Nie udało się utworzyć katalogu dla dokumentów.');
        }
        $safeName = $base . '-' . bin2hex(random_bytes(6)) . '.pdf';
        $targetPath = $targetDirectory . DIRECTORY_SEPARATOR . $safeName;
        if (!move_uploaded_file($temporaryPath, $targetPath)) {
            throw new \RuntimeException('Nie udało się zapisać PDF.');
        }
        $publicPath = '/' . $relativeDirectory . '/' . $safeName;
        try {
            $statement = $this->pdo->prepare('INSERT INTO cms_media (filename, path, mime_type, size) VALUES (?, ?, ?, ?)');
            $statement->execute([$originalName, $publicPath, 'application/pdf', $size]);
        } catch (\Throwable $exception) {
            @unlink($targetPath);
            throw $exception;
        }
        return ['path' => $publicPath, 'filename' => $originalName, 'mime_type' => 'application/pdf', 'size' => $size];
    }

    private function slugify(string $value): string
    {
        $value = strtr(mb_strtolower(trim($value), 'UTF-8'), ['ą'=>'a','ć'=>'c','ę'=>'e','ł'=>'l','ń'=>'n','ó'=>'o','ś'=>'s','ż'=>'z','ź'=>'z']);
        return trim(preg_replace('/[^a-z0-9]+/i', '-', $value) ?: 'dokument', '-') ?: 'dokument';
    }
}
