<?php

declare(strict_types=1);

namespace Tests\Unit\Stream;

use Basis\Nats\Stream\Configuration;
use Tests\TestCase;

/**
 * Unit tests for the Stream\Configuration class, specifically covering the
 * allowMsgSchedules property introduced for NATS Server 2.12 message
 * scheduling support (ADR-51).
 *
 * These tests verify the getter/setter behavior, serialization via toArray(),
 * deserialization via fromArray(), and round-trip consistency — all without
 * requiring a running NATS server.
 *
 * @see https://github.com/nats-io/nats-architecture-and-design/blob/main/adr/ADR-51.md
 * @see https://docs.nats.io/nats-concepts/jetstream/streams
 */
class ConfigurationTest extends TestCase
{
    /**
     * Verify the default value of allowMsgSchedules is null (not set).
     * When null, the property is omitted from the serialized config array,
     * meaning the NATS server will use its default (scheduling disabled).
     */
    public function testAllowMsgSchedulesDefaultIsNull(): void
    {
        $config = new Configuration('test_stream');
        $this->assertNull($config->getAllowMsgSchedules());
    }

    /**
     * Verify setAllowMsgSchedules() returns $this for fluent method chaining,
     * consistent with all other setters in the Configuration class.
     */
    public function testSetAllowMsgSchedulesReturnsSelf(): void
    {
        $config = new Configuration('test_stream');
        $result = $config->setAllowMsgSchedules(true);
        $this->assertSame($config, $result);
    }

    /**
     * Verify that setting allowMsgSchedules to true is correctly stored
     * and retrievable via the getter.
     */
    public function testSetAllowMsgSchedulesTrue(): void
    {
        $config = new Configuration('test_stream');
        $config->setAllowMsgSchedules(true);
        $this->assertTrue($config->getAllowMsgSchedules());
    }

    /**
     * Verify that setting allowMsgSchedules to false is correctly stored.
     * Note: per NATS ADR-51, once enabled on a live stream it cannot be
     * disabled — but the Configuration object itself allows any value.
     */
    public function testSetAllowMsgSchedulesFalse(): void
    {
        $config = new Configuration('test_stream');
        $config->setAllowMsgSchedules(false);
        $this->assertFalse($config->getAllowMsgSchedules());
    }

    /**
     * Verify that allowMsgSchedules can be reset back to null after being set.
     * Setting to null means the property will be omitted from the serialized
     * config, leaving the server to use its default.
     */
    public function testSetAllowMsgSchedulesNull(): void
    {
        $config = new Configuration('test_stream');
        $config->setAllowMsgSchedules(true);
        $config->setAllowMsgSchedules(null);
        $this->assertNull($config->getAllowMsgSchedules());
    }

    /**
     * Verify that toArray() includes the 'allow_msg_schedules' key with the
     * correct boolean value when the property is explicitly set to true.
     * The NATS JetStream API expects the JSON key "allow_msg_schedules".
     */
    public function testToArrayIncludesAllowMsgSchedulesWhenSet(): void
    {
        $config = new Configuration('test_stream');
        $config->setSubjects(['test'])
            ->setAllowMsgSchedules(true);

        $array = $config->toArray();
        $this->assertArrayHasKey('allow_msg_schedules', $array);
        $this->assertTrue($array['allow_msg_schedules']);
    }

    /**
     * Verify that toArray() omits 'allow_msg_schedules' entirely when the
     * property is null. The Configuration class strips null values from the
     * serialized array to avoid sending unnecessary fields to the server.
     */
    public function testToArrayOmitsAllowMsgSchedulesWhenNull(): void
    {
        $config = new Configuration('test_stream');
        $config->setSubjects(['test']);

        $array = $config->toArray();
        $this->assertArrayNotHasKey('allow_msg_schedules', $array);
    }

    /**
     * Verify that fromArray() correctly parses the 'allow_msg_schedules' key
     * from a config array (as would be received from the NATS server's
     * STREAM.INFO response) and sets the property on the Configuration object.
     */
    public function testFromArrayParsesAllowMsgSchedules(): void
    {
        $config = new Configuration('test_stream');
        $config->fromArray([
            'discard' => 'old',
            'max_consumers' => -1,
            'num_replicas' => 1,
            'retention' => 'limits',
            'storage' => 'file',
            'subjects' => ['test'],
            'allow_msg_schedules' => true,
        ]);

        $this->assertTrue($config->getAllowMsgSchedules());
    }

    /**
     * Verify that fromArray() leaves allowMsgSchedules as null when the key
     * is absent from the input array. This simulates receiving config from a
     * NATS server older than 2.12, which wouldn't include this field.
     */
    public function testFromArrayWithoutAllowMsgSchedulesKeepsNull(): void
    {
        $config = new Configuration('test_stream');
        $config->fromArray([
            'discard' => 'old',
            'max_consumers' => -1,
            'num_replicas' => 1,
            'retention' => 'limits',
            'storage' => 'file',
            'subjects' => ['test'],
        ]);

        $this->assertNull($config->getAllowMsgSchedules());
    }

    /**
     * Verify full round-trip: toArray() -> fromArray() preserves the
     * allow_msg_schedules=true setting and produces identical output.
     * This ensures the property survives serialization and deserialization,
     * which is critical for stream updates (read config, modify, write back).
     */
    public function testRoundTripWithAllowMsgSchedules(): void
    {
        $original = new Configuration('test_stream');
        $original->setSubjects(['test'])
            ->setAllowMsgSchedules(true);

        $exported = $original->toArray();

        $restored = new Configuration('test_stream');
        $restored->fromArray($exported);

        $this->assertTrue($restored->getAllowMsgSchedules());
        $this->assertSame(
            $original->toArray(),
            $restored->toArray()
        );
    }

    /**
     * Verify round-trip when allowMsgSchedules is not set (null).
     * The property should remain null after deserialization, confirming
     * that omitted fields are not accidentally set to a default value.
     */
    public function testRoundTripWithoutAllowMsgSchedules(): void
    {
        $original = new Configuration('test_stream');
        $original->setSubjects(['test']);

        $exported = $original->toArray();

        $restored = new Configuration('test_stream');
        $restored->fromArray($exported);

        $this->assertNull($restored->getAllowMsgSchedules());
    }

    /**
     * Verify that setAllowMsgSchedules() integrates correctly with fluent
     * method chaining alongside other setters, returning the same
     * Configuration instance throughout the chain.
     */
    public function testFluentChaining(): void
    {
        $config = new Configuration('test_stream');
        $result = $config
            ->setSubjects(['test'])
            ->setAllowMsgSchedules(true)
            ->setDenyDelete(false);

        $this->assertSame($config, $result);
        $this->assertTrue($config->getAllowMsgSchedules());
        $this->assertFalse($config->getDenyDelete());
    }
}
