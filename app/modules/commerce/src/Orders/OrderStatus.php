<?php

declare(strict_types=1);

namespace Reklamova\Cms\Commerce\Orders;

enum OrderStatus: string
{
    case New = 'new';
    case AwaitingFiles = 'awaiting_files';
    case FilesReceived = 'files_received';
    case InProduction = 'in_production';
    case Ready = 'ready';
    case Shipped = 'shipped';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
}
