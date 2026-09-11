<?php

declare(strict_types=1);

namespace Reklamova\Cms\Database;

use PDO;
use PDOException;
use Reklamova\Cms\Support\Config;
use RuntimeException;

final class ConnectionFactory
{
    public function __construct(private array $container)
    {
    }

    public function make(): PDO
    {
        $path = $this->container['config_path'] . '/database.php';
        $fileConfig = is_file($path) ? require $path : [];
        $configReader = new Config($this->container);
        $config = [
            'host' => $configReader->get('database', 'host', $fileConfig['host'] ?? ''),
            'port' => $configReader->get('database', 'port', $fileConfig['port'] ?? 3306),
            'database' => $configReader->get('database', 'database', $fileConfig['database'] ?? ''),
            'username' => $configReader->get('database', 'username', $fileConfig['username'] ?? ''),
            'password' => $configReader->get('database', 'password', $fileConfig['password'] ?? ''),
            'charset' => $configReader->get('database', 'charset', $fileConfig['charset'] ?? 'utf8mb4'),
        ];
        if ($config['host'] === '' || $config['database'] === '' || $config['username'] === '') {
            throw new RuntimeException('Database config is missing.');
        }
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $config['host'],
            $config['port'],
            $config['database'],
            $config['charset']
        );

        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ];

        for ($attempt = 1; $attempt <= 2; $attempt++) {
            try {
                return new PDO($dsn, $config['username'], $config['password'], $options);
            } catch (PDOException $exception) {
                if ($attempt === 2 || !$this->isTransientConnectionFailure($exception)) {
                    throw $exception;
                }

                usleep(200_000);
            }
        }

        throw new RuntimeException('Database connection could not be established.');
    }

    private function isTransientConnectionFailure(PDOException $exception): bool
    {
        $message = strtolower($exception->getMessage());

        return (string) $exception->getCode() === '2002'
            || str_contains($message, 'php_network_getaddresses')
            || str_contains($message, 'getaddrinfo')
            || str_contains($message, 'connection refused')
            || str_contains($message, 'connection timed out');
    }
}

