<?php

return [
    'source' => [
        'driver' => 'mysql',
        'host' => 'localhost',
        'port' => 3306,
        'database' => 'wordpress_database',
        'username' => 'readonly_wordpress_user',
        'password' => '',
        'charset' => 'utf8mb4',
        'table_prefix' => 'wp_',
        'uploads_path' => '/absolute/path/to/wp-content/uploads',
    ],
    'store' => [
        'code' => 'default',
        'name' => 'Sklep',
        'currency' => 'PLN',
        'country_code' => 'PL',
        'prices_include_tax' => true,
    ],
];
