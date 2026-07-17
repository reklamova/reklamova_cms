<?php

declare(strict_types=1);

use Reklamova\Cms\Modules\Leads\LeadRepository;

require_once __DIR__ . '/src/LeadRepository.php';

return static function (array $container, PDO $pdo, array $module): array {
    $respond = static function (bool $wantsJson, int $status, array $payload): void {
        if ($wantsJson) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode($payload, JSON_UNESCAPED_SLASHES);
            return;
        }

        $target = (string) ($_SERVER['HTTP_REFERER'] ?? '/');
        $separator = str_contains($target, '?') ? '&' : '?';
        header('Location: ' . $target . $separator . ($status >= 400 ? 'form_error=1' : 'form_sent=1'));
    };

    $submit = static function () use ($pdo, $respond): void {
        $wantsJson = str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')
            || strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            $respond($wantsJson, 405, ['ok' => false, 'error' => 'method_not_allowed']);
            return;
        }

        $email = trim((string) ($_POST['email'] ?? ''));
        $message = trim((string) ($_POST['message'] ?? ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $message === '') {
            $respond($wantsJson, 422, ['ok' => false, 'error' => 'invalid_form']);
            return;
        }

        $payload = $_POST;
        $payload['source'] = 'page_studio_form';
        $payload['page_url'] = (string) ($_SERVER['HTTP_REFERER'] ?? '');
        $payload['consent'] = [
            'marketing_consent' => !empty($_POST['marketing_consent']),
            'recorded_at' => date(DATE_ATOM),
        ];

        $publicId = (new LeadRepository($pdo))->create($payload, $_SERVER);
        $respond($wantsJson, 200, ['ok' => true, 'id' => $publicId]);
    };

    return [
        'routes' => ['/api/forms/submit' => $submit],
    ];
};
