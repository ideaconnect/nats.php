<?php

declare(strict_types=1);

namespace Basis\Nats\Stream;

enum StorageBackend: string
{
    case FILE = 'file';
    case MEMORY = 'memory';
}
