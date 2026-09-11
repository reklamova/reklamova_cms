<?php

declare(strict_types=1);

namespace Reklamova\Cms\Commerce\Uploads;

final class OrderFileService
{
    /** @var callable(string,string):bool */
    private $moveFile;
    private string $storageRoot;

    public function __construct(
        private PdoOrderFileRepository $repository,
        string $storageRoot,
        ?callable $moveFile = null,
    ) {
        $storageRoot = rtrim($storageRoot, '/\\');
        if ($storageRoot === '' || (!is_dir($storageRoot) && !mkdir($storageRoot, 0770, true))) {
            throw new \RuntimeException('Order file storage is unavailable.');
        }
        $resolved = realpath($storageRoot);
        if ($resolved === false) {
            throw new \RuntimeException('Order file storage path cannot be resolved.');
        }
        $this->storageRoot = rtrim($resolved, '/\\');
        $this->moveFile = $moveFile ?? static fn (string $source, string $target): bool => move_uploaded_file($source, $target);
    }

    /** @return array<string, mixed> */
    public function upload(
        int $orderId,
        int $orderItemId,
        int $requirementId,
        string $checkoutToken,
        string $storeCode,
        string $temporaryPath,
        string $originalName,
        ?int $customerId = null,
    ): array {
        if ($orderId <= 0 || $orderItemId <= 0 || $requirementId <= 0
            || ($customerId === null && strlen($checkoutToken) < 16)
            || ($customerId !== null && $customerId <= 0)) {
            throw new \InvalidArgumentException('Order file identity is invalid.');
        }
        if ($originalName === '' || $originalName !== basename(str_replace('\\', '/', $originalName)) || strlen($originalName) > 255) {
            throw new \InvalidArgumentException('File name is invalid.');
        }
        if (!is_file($temporaryPath) || !is_readable($temporaryPath)) {
            throw new \InvalidArgumentException('Uploaded file is unavailable.');
        }
        $extension = strtolower((string) pathinfo($originalName, PATHINFO_EXTENSION));
        $size = filesize($temporaryPath);
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = (string) $finfo->file($temporaryPath);
        $checksum = hash_file('sha256', $temporaryPath);
        if ($extension === '' || $size === false || $checksum === false || $mime === '') {
            throw new \InvalidArgumentException('Uploaded file cannot be inspected.');
        }

        $storageKey = bin2hex(random_bytes(16)) . '/' . bin2hex(random_bytes(24)) . '.' . $extension;
        $target = $this->path($storageKey);
        $targetDirectory = dirname($target);
        if (!is_dir($targetDirectory) && !mkdir($targetDirectory, 0770, true)) {
            throw new \RuntimeException('Order file directory cannot be created.');
        }

        $fileId = null;
        try {
            $fileId = $this->repository->transaction(function () use (
                $orderId, $orderItemId, $requirementId, $checkoutToken, $storeCode,
                $extension, $size, $mime, $storageKey, $originalName, $checksum, $temporaryPath, $target,
                $customerId,
            ): int {
                $requirement = $customerId === null
                    ? $this->repository->lockRequirement(
                        $orderId,
                        $orderItemId,
                        $requirementId,
                        hash('sha256', $checkoutToken),
                        $storeCode,
                    )
                    : $this->repository->lockRequirementForCustomer(
                        $orderId,
                        $orderItemId,
                        $requirementId,
                        $customerId,
                        $storeCode,
                    );
                $allowedExtensions = $this->list((string) $requirement['allowed_extensions_json']);
                $allowedMimes = $this->list((string) $requirement['allowed_mime_types_json']);
                if (!in_array($extension, $allowedExtensions, true) || !in_array(strtolower($mime), $allowedMimes, true)) {
                    throw new \DomainException('File format is not allowed for this product.');
                }
                if ($size <= 0 || $size > (int) $requirement['max_bytes']) {
                    throw new \DomainException('File exceeds the allowed size.');
                }
                if ((int) $requirement['current_files'] >= (int) $requirement['max_files']) {
                    throw new \DomainException('File limit for this product has been reached.');
                }
                if (!(($this->moveFile)($temporaryPath, $target))) {
                    throw new \RuntimeException('Uploaded file could not be stored.');
                }

                return $this->repository->insert([
                    'order_id' => $orderId,
                    'order_item_id' => $orderItemId,
                    'requirement_id' => $requirementId,
                    'storage_key' => $storageKey,
                    'original_name' => $originalName,
                    'extension' => $extension,
                    'mime_type' => strtolower($mime),
                    'size_bytes' => $size,
                    'checksum_sha256' => $checksum,
                ]);
            });
        } catch (\Throwable $exception) {
            if (is_file($target)) {
                @unlink($target);
            }
            throw $exception;
        }

        return ['id' => $fileId, 'original_name' => $originalName, 'size_bytes' => $size, 'checksum_sha256' => $checksum];
    }

    /** @return array{path:string,name:string,mime:string,size:int}|null */
    public function download(int $fileId, string $checkoutToken, string $storeCode, ?int $customerId = null): ?array
    {
        if ($fileId <= 0 || ($customerId === null && strlen($checkoutToken) < 16) || ($customerId !== null && $customerId <= 0)) {
            return null;
        }
        $file = $customerId === null
            ? $this->repository->accessibleFile($fileId, hash('sha256', $checkoutToken), $storeCode)
            : $this->repository->accessibleFileForCustomer($fileId, $customerId, $storeCode);
        if (!$file) {
            return null;
        }
        $path = $this->path((string) $file['storage_key']);
        if (!is_file($path) || !hash_equals((string) $file['checksum_sha256'], (string) hash_file('sha256', $path))) {
            return null;
        }

        return ['path' => $path, 'name' => (string) $file['original_name'], 'mime' => (string) $file['mime_type'], 'size' => (int) $file['size_bytes']];
    }

    private function path(string $storageKey): string
    {
        if (preg_match('~^[a-f0-9]{32}/[a-f0-9]{48}\.[a-z0-9]{1,15}$~', $storageKey) !== 1) {
            throw new \DomainException('Order file storage key is invalid.');
        }

        return $this->storageRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $storageKey);
    }

    /** @return array<int, string> */
    private function list(string $json): array
    {
        $values = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        return is_array($values) ? array_values(array_unique(array_map(static fn (mixed $value): string => strtolower((string) $value), $values))) : [];
    }
}
