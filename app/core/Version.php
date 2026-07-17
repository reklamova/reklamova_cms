<?php

declare(strict_types=1);

namespace Reklamova\Cms;

final class Version
{
    public const VERSION = '0.7.0';

    public static function current(): string
    {
        return self::VERSION;
    }
}
