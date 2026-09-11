<?php

declare(strict_types=1);

namespace Reklamova\Cms\Support;

interface EmailSenderInterface
{
    public function send(string $to, string $subject, string $message): bool;
}
