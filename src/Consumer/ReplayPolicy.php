<?php

declare(strict_types=1);

namespace Basis\Nats\Consumer;

enum ReplayPolicy: string
{
    case INSTANT = 'instant';
    case ORIGINAL = 'original';
}
