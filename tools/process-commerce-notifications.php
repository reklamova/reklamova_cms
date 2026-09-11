<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use Reklamova\Cms\Commerce\Notifications\NotificationWorker;
use Reklamova\Cms\Database\ConnectionFactory;
use Reklamova\Cms\Support\Config;
use Reklamova\Cms\Support\Mailer;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$options = getopt('', ['limit::']);
$limit = filter_var($options['limit'] ?? 50, FILTER_VALIDATE_INT);
$limit = $limit === false ? 50 : max(1, min(500, $limit));
$config = new Config($container);
$worker = new NotificationWorker(
    (new ConnectionFactory($container))->make(),
    new Mailer($container),
    (string) $config->get('app', 'url', ''),
    (string) $config->get('app', 'name', 'Sklep Reklamova'),
);
$counts = ['sent' => 0, 'failed' => 0, 'ignored' => 0];
for ($index = 0; $index < $limit; $index++) {
    $result = $worker->processNext();
    if ($result === 'empty') {
        break;
    }
    $counts[$result] = ($counts[$result] ?? 0) + 1;
}

echo json_encode($counts, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($counts['failed'] > 0 ? 1 : 0);
