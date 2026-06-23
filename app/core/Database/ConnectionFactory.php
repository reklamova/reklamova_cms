<?php

declare(strict_types=1);

namespace Reklamova\Cms\Database;

use PDO;
use RuntimeException;

final class ConnectionFactory
{
    public function __construct(private array $container)
    {
    }

    public function make(): PDO
    {
        $path = $this->container['config_path'] . '/database.php';
        if (!is_file($path)) {
            throw new RuntimeException('Database config is missing.');
        }

        $config = require $path;
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $config['host'],
            $config['port'] ?? 3306,
            $config['database'],
            $config['charset'] ?? 'utf8mb4'
        );

        return new PDO($dsn, $config['username'], $config['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }
}

