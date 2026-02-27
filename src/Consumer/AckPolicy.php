<?php

declare(strict_types=1);

namespace Basis\Nats\Consumer;

enum AckPolicy: string
{
    case ALL = 'all';
    case EXPLICIT = 'explicit';
    case NONE = 'none';
}
