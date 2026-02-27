<?php

declare(strict_types=1);

namespace Basis\Nats\Stream;

enum RetentionPolicy: string
{
    case INTEREST = 'interest';
    case LIMITS = 'limits';
    case WORK_QUEUE = 'workqueue';
}
