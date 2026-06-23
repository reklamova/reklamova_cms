<?php

declare(strict_types=1);

use Reklamova\Cms\Http\Application;

require dirname(__DIR__, 2) . '/app/bootstrap.php';

(new Application($container))->handleAdmin();

