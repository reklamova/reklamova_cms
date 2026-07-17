<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

(new Reklamova\Cms\Database\Migrator($container))->runCoreMigrations();
(new Reklamova\Cms\Database\Migrator($container))->runActiveModuleMigrations();

echo "MIGRATED\n";
