<?php

declare(strict_types=1);

namespace Tests\Functional;

use Basis\Nats\Stream\DiscardPolicy;
use Basis\Nats\Stream\RetentionPolicy;
use Basis\Nats\Stream\StorageBackend;
use Tests\FunctionalTestCase;

class ConfigurationTest extends FunctionalTestCase
{
    public function testClientDelayConfiguration()
    {
        $client = $this->getClient();

        $delay = floatval(rand(1, 100));
        $client->setDelay($delay);
        $this->assertSame($delay, $client->configuration->getDelay());
    }

    public function testClientConfigurationOverride()
    {
        $this->assertSame($this->getConfiguration()->host, getenv('NATS_HOST'));
        $this->assertEquals($this->getConfiguration()->port, getenv('NATS_PORT'));
    }

    public function testStreamConfgurationInvalidStorageBackend()
    {
        $this->expectException(\ValueError::class);
        StorageBackend::from('s3');
    }

    public function testStreamConfgurationInvalidRetentionPolicy()
    {
        $this->expectException(\ValueError::class);
        RetentionPolicy::from('lucky');
    }

    public function testStreamConfgurationInvalidDiscardPolicy()
    {
        $this->expectException(\ValueError::class);
        DiscardPolicy::from('lucky');
    }
}
