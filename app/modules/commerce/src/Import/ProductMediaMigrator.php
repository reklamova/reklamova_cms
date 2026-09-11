<?php

declare(strict_types=1);

namespace Reklamova\Cms\Commerce\Import;

final class ProductMediaMigrator
{
    private const ALLOWED_MIME_PREFIX = 'image/';
    private const ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif'];
    private string $sourceRoot;
    private string $targetRoot;

    public function __construct(string $sourceRoot, string $publicRoot)
    {
        $source = realpath($sourceRoot);
        if ($source === false || !is_dir($source)) {
            throw new \InvalidArgumentException('WordPress uploads path does not exist.');
        }
        $this->sourceRoot = rtrim(str_replace('\\', '/', $source), '/');
        $this->targetRoot = rtrim(str_replace('\\', '/', $publicRoot), '/') . '/uploads/commerce';
    }

    /**
     * @return array{url: string, copied: bool, checksum: string}
     */
    public function migrate(string $relativePath): array
    {
        $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');
        if ($relativePath === '' || str_contains($relativePath, '..') || str_contains($relativePath, "\0")) {
            throw new \InvalidArgumentException('Unsafe media path.');
        }
        $extension = strtolower(pathinfo($relativePath, PATHINFO_EXTENSION));
        if (!in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            throw new \RuntimeException("Unsupported product media extension: {$relativePath}");
        }

        $source = realpath($this->sourceRoot . '/' . $relativePath);
        if ($source === false || !is_file($source)) {
            throw new \RuntimeException("Source media is missing: {$relativePath}");
        }
        $normalizedSource = str_replace('\\', '/', $source);
        if (!str_starts_with($normalizedSource, $this->sourceRoot . '/')) {
            throw new \RuntimeException('Source media escaped the uploads directory.');
        }

        $mime = mime_content_type($source);
        if (!is_string($mime) || !str_starts_with(strtolower($mime), self::ALLOWED_MIME_PREFIX) || $mime === 'image/svg+xml') {
            throw new \RuntimeException("Unsupported product media MIME: {$relativePath}");
        }

        $target = $this->targetRoot . '/' . $relativePath;
        $targetDirectory = dirname($target);
        if (!is_dir($targetDirectory) && !mkdir($targetDirectory, 0775, true) && !is_dir($targetDirectory)) {
            throw new \RuntimeException('Cannot create commerce media directory.');
        }

        $sourceHash = hash_file('sha256', $source);
        if (!is_string($sourceHash)) {
            throw new \RuntimeException('Cannot checksum source media.');
        }
        if (is_file($target) && hash_equals($sourceHash, (string) hash_file('sha256', $target))) {
            return ['url' => '/uploads/commerce/' . $relativePath, 'copied' => false, 'checksum' => $sourceHash];
        }

        $temporary = $target . '.tmp-' . bin2hex(random_bytes(6));
        if (!copy($source, $temporary)) {
            throw new \RuntimeException('Cannot copy product media.');
        }
        @chmod($temporary, 0644);
        if (!hash_equals($sourceHash, (string) hash_file('sha256', $temporary))) {
            @unlink($temporary);
            throw new \RuntimeException('Copied product media checksum mismatch.');
        }
        if (!rename($temporary, $target)) {
            @unlink($temporary);
            throw new \RuntimeException('Cannot publish copied product media.');
        }

        return ['url' => '/uploads/commerce/' . $relativePath, 'copied' => true, 'checksum' => $sourceHash];
    }
}
