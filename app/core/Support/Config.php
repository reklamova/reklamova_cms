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
        $config = $this->load($file);
        return $config[$key] ?? $default;
    }

    public function load(string $file): array
    {
        $path = $this->container['config_path'] . '/' . $file . '.php';
        return is_file($path) ? require $path : [];
    }
}

