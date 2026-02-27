<?php
declare(strict_types=1);

namespace Basis\Nats\Service;

use Basis\Nats\Message\Payload;

interface EndpointHandler
{
    public function handle(Payload $payload): array;
}
