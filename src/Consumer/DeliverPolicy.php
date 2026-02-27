<?php

declare(strict_types=1);

namespace Basis\Nats\Consumer;

enum DeliverPolicy: string
{
    case ALL = 'all';
    case BY_START_SEQUENCE = 'by_start_sequence';
    case BY_START_TIME = 'by_start_time';
    case LAST = 'last';
    case LAST_PER_SUBJECT = 'last_per_subject';
    case NEW = 'new';
}
