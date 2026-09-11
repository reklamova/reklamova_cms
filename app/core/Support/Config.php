<?php

declare(strict_types=1);

namespace Reklamova\Cms\Support;

final class Config
{
    public function __construct(private array $container)
    {
    }

    public function get(string $file, string $key, mixed $default = null): mixed
    {
        $environment = getenv($this->environmentKey($file, $key));
        if ($environment !== false) {
            return $this->coerce((string) $environment, $default);
        }

        $config = $this->load($file);
        return $config[$key] ?? $default;
    }

    public function load(string $file): array
    {
        $path = $this->container['config_path'] . '/' . $file . '.php';
        return is_file($path) ? require $path : [];
    }

    private function environmentKey(string $file, string $key): string
    {
        $name = strtoupper($file . '_' . $key);

        return 'REKLAMOVA_' . (preg_replace('/[^A-Z0-9]+/', '_', $name) ?: $name);
    }

    private function coerce(string $value, mixed $default): mixed
    {
        if (is_bool($default)) {
            $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($parsed === null) {
                throw new \InvalidArgumentException('Invalid boolean environment configuration.');
            }

            return $parsed;
        }
        if (is_int($default)) {
            $parsed = filter_var($value, FILTER_VALIDATE_INT);
            if ($parsed === false) {
                throw new \InvalidArgumentException('Invalid integer environment configuration.');
            }

            return $parsed;
        }
        if (is_float($default)) {
            $parsed = filter_var($value, FILTER_VALIDATE_FLOAT);
            if ($parsed === false) {
                throw new \InvalidArgumentException('Invalid numeric environment configuration.');
            }

            return $parsed;
        }
        if (is_array($default)) {
            $decoded = json_decode($value, true);

            return is_array($decoded) ? $decoded : $default;
        }

        return $value;
    }
}

