<?php

declare(strict_types=1);

namespace Basis\Nats\Stream;

/**
 * Stream compression algorithm.
 *
 * Controls whether message data is compressed on disk by the NATS server.
 * Requires NATS Server >= 2.10.
 *
 * When omitted from the stream configuration the server defaults to no
 * compression (equivalent to {@see Compression::None}).
 *
 * @see https://github.com/nats-io/nats-architecture-and-design/blob/main/adr/ADR-8.md
 */
enum Compression: string
{
    /** No compression (server default). */
    case None = 'none';

    /** S2 (Snappy-based) compression. */
    case S2 = 's2';
}
