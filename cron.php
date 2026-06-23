<?php

declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

file_put_contents($container['storage_path'] . '/cron.last', date(DATE_ATOM));

echo "Reklamova CMS cron OK\n";

