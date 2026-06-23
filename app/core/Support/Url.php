<?php

declare(strict_types=1);

namespace Reklamova\Cms\Support;

final class Url
{
    public static function path(): string
    {
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        return '/' . trim($uri, '/');
    }

    public static function redirect(string $to): never
    {
        header('Location: ' . $to);
        exit;
    }
}

