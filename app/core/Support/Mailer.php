<?php

declare(strict_types=1);

namespace Reklamova\Cms\Support;

final class Mailer
{
    private array $mail;
    private array $app;

    public function __construct(private array $container)
    {
        $config = new Config($container);
        $this->mail = $config->load('mail');
        $this->app = $config->load('app');
    }

    public function send(string $to, string $subject, string $message): bool
    {
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        $from = $this->fromAddress();
        $fromName = (string) ($this->mail['from_name'] ?? $this->app['name'] ?? 'Reklamova CMS');
        $headers = [
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'From: ' . $this->encodeHeader($fromName) . ' <' . $from . '>',
            'Reply-To: ' . $from,
            'X-Mailer: Reklamova CMS',
        ];

        return @mail($to, $this->encodeHeader($subject), $message, implode("\r\n", $headers));
    }

    private function fromAddress(): string
    {
        $configured = (string) ($this->mail['from'] ?? '');
        if (filter_var($configured, FILTER_VALIDATE_EMAIL)) {
            return $configured;
        }

        $host = parse_url((string) ($this->app['url'] ?? ''), PHP_URL_HOST);
        $host = is_string($host) && $host !== '' ? preg_replace('/^www\./', '', $host) : 'reklamova.pl';

        return 'noreply@' . $host;
    }

    private function encodeHeader(string $value): string
    {
        if (preg_match('/[^\x20-\x7E]/', $value) !== 1) {
            return '=?UTF-8?B?' . base64_encode($value) . '?=';
        }

        return str_replace(["\r", "\n"], '', $value);
    }
}
