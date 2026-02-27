<?php

declare(strict_types=1);

namespace Basis\Nats\Stream;

enum DiscardPolicy: string
{
    case OLD = 'old';
    case NEW = 'new';
}
