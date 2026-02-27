<?php

declare(strict_types=1);

namespace Tests\Functional;

use Basis\Nats\Consumer\AckPolicy;
use Basis\Nats\Consumer\Configuration;
use Basis\Nats\Consumer\Consumer;
use Basis\Nats\Consumer\DeliverPolicy;
use Basis\Nats\Consumer\ReplayPolicy;
use Basis\Nats\Message\Payload;
use Basis\Nats\Stream\ConsumerLimits;
use Basis\Nats\Stream\RetentionPolicy;
use Basis\Nats\Stream\StorageBackend;
use Exception;
use Tests\FunctionalTestCase;

class StreamTest extends FunctionalTestCase
{
    private const CONSUMER_BATCH_SIZE = 50;

    private mixed $called;

    private bool $empty;

    public function testInvalidAckPolicy()
    {
        $this->expectException(\ValueError::class);
        AckPolicy::from('fast');
    }

    public function testInvalidDeliverPolicy()
    {
        $this->expectException(\ValueError::class);
        DeliverPolicy::from('turtle');
    }

    public function testInvalidReplayPolicy()
    {
        $this->expectException(\ValueError::class);
        ReplayPolicy::from('fast');
    }

    public function testNack()
    {
        $client = $this->createClient();
        $stream = $client->getApi()->getStream('nacks');
        $stream->getConfiguration()->setSubjects(['nacks'])->setRetentionPolicy(RetentionPolicy::INTEREST);
        $stream->create();

        $consumer = $stream->getConsumer('nacks');
        $consumer->setExpires(5);
        $consumer->getConfiguration()
            ->setSubjectFilter('nacks')
            ->setReplayPolicy(ReplayPolicy::INSTANT)
            ->setAckPolicy(AckPolicy::EXPLICIT);

        $consumer->create();

        $stream->publish('nacks', 'first');
        $stream->publish('nacks', 'second');

        $this->assertSame(2, $consumer->info()->num_pending);

        $queue = $consumer->getQueue();
        $message = $queue->fetch();
        $this->assertNotNull($message);
        $this->assertSame((string) $message->payload, 'first');
        $message->nack(0.5);

        $this->assertSame(1, $consumer->info()->num_ack_pending);
        $this->assertSame(1, $consumer->info()->num_pending);

        $queue->setTimeout(0.1);
        $messages = $queue->fetchAll();
        $this->assertCount(1, $messages);
        [$message] = $messages;
        $this->assertSame((string) $message->payload, 'second');
        $message->progress();
        $message->ack();

        usleep(100_000);
        $messages = $queue->fetchAll();
        $this->assertCount(0, $messages);
        $this->assertSame(1, $consumer->info()->num_ack_pending);
        $this->assertSame(0, $consumer->info()->num_pending);

        usleep(500_000);
        $messages = $queue->fetchAll();
        $this->assertCount(1, $messages);
        [$message] = $messages;
        $message->ack();

        usleep(100_000);
        $this->assertSame(0, $consumer->info()->num_ack_pending);
        $this->assertSame(0, $consumer->info()->num_pending);
    }

    public function testPurge()
    {
        $client = $this->createClient();
        $stream = $client->getApi()->getStream('purge');
        $stream->getConfiguration()->setSubjects(['purge'])->setRetentionPolicy(RetentionPolicy::WORK_QUEUE);
        $stream->create();

        $consumer = $stream->getConsumer('purge');
        $consumer->setExpires(5);
        $consumer->getConfiguration()
            ->setSubjectFilter('purge')
            ->setReplayPolicy(ReplayPolicy::INSTANT)
            ->setAckPolicy(AckPolicy::EXPLICIT);

        $consumer->create();

        $stream->publish('purge', 'first');
        $stream->publish('purge', 'second');

        $this->assertSame(2, $consumer->info()->num_pending);

        $stream->purge();

        $this->assertSame(0, $consumer->info()->num_pending);
    }

    public function testConsumerExpiration()
    {
        $client = $this->createClient(['timeout' => 0.2, 'delay' => 0.1]);
        $stream = $client->getApi()->getStream('empty');
        $stream->getConfiguration()
            ->setSubjects(['empty']);

        $stream->create();
        $consumer = $stream->getConsumer('empty')->create();
        $consumer->getConfiguration()->setSubjectFilter('empty');

        $info = $client->connection->getInfoMessage();

        $consumer->setIterations(1)->setExpires(3)->handle(function () {
        });
        $this->assertSame($info, $client->connection->getInfoMessage());
    }

    public function testSetConfigRetentionPolicyToMaxAge(): void
    {
        $api = $this->createClient()
            ->getApi();
        $stream = $api->getStream('tester');

        $stream->getConfiguration()
            ->setRetentionPolicy(RetentionPolicy::LIMITS)
            ->setMaxAge(3_600_000_000_000)
            ->setStorageBackend(StorageBackend::MEMORY)
            ->setSubjects(['tester.greet', 'tester.bye']);

        $stream->create();

        $config = $stream->info()->config;

        self::assertSame($config->retention, 'limits');
        self::assertSame($config->storage, 'memory');
        self::assertSame($config->subjects, ['tester.greet', 'tester.bye']);
        self::assertSame($config->max_age, 3_600_000_000_000);

        $stream->getConfiguration()
            ->setSubjects(['tester.greet']);
        $stream->update();

        self::assertSame($stream->info()->config->subjects, ['tester.greet']);

        $stream->getConfiguration()
            ->setMaxAge(3_600_000_000_001)
            ->setSubjects(['tester.greet', 'tester.bye']);
        $stream->update();

        $api = $this->createClient()
            ->getApi();

        $api->getStream('tester')
            ->getConfiguration()
            ->fromArray(
                $stream->getConfiguration()
                    ->toArray()
            );

        $configuration = $api->getStream('tester')
            ->getConfiguration();
        self::assertSame($configuration->getRetentionPolicy(), RetentionPolicy::LIMITS);
        self::assertSame($configuration->getStorageBackend(), StorageBackend::MEMORY);
        self::assertSame($configuration->getSubjects(), ['tester.greet', 'tester.bye']);
    }

    public function testDeduplication()
    {
        $stream = $this->getClient()->getApi()->getStream('tester');
        $stream->getConfiguration()
            ->setSubjects(['tester'])
            ->setDuplicateWindow(0.5); // 500ms windows duplicate

        $stream->createIfNotExists();
        $stream->createIfNotExists(); // should not provide an error

        // windows value using nanoseconds
        $this->assertEquals(0.5 * 1_000_000_000, $stream->info()->getValue('config.duplicate_window'));

        $stream->put('tester', new Payload("hello", [
            'Nats-Msg-Id' => 'the-message'
        ]));

        $stream->put('tester', new Payload("hello", [
            'Nats-Msg-Id' => 'the-message'
        ]));

        $consumer = $stream->getConsumer('tester')
            ->setIterations(1)
            ->create();

        $this->called = null;
        $this->assertWrongNumPending($consumer, 1);

        $consumer->handle($this->persistMessage(...));

        $this->assertNotNull($this->called);

        $this->assertWrongNumPending($consumer);

        $stream->put('tester', new Payload("hello", [
            'Nats-Msg-Id' => 'the-message'
        ]));
        $this->assertWrongNumPending($consumer);

        // 500ms sleep
        usleep(500 * 1_000);

        $stream->put('tester', new Payload("hello", [
            'Nats-Msg-Id' => 'the-message'
        ]));
        $this->assertWrongNumPending($consumer, 1);

        usleep(500 * 1_000);

        $stream->put('tester', new Payload("hello", [
            'Nats-Msg-Id' => 'the-message'
        ]));
        $this->assertWrongNumPending($consumer, 2);

        $consumer->handle(function ($msg) {
            $this->assertSame($msg->getHeader('Nats-Msg-Id'), 'the-message');
        });

        $this->assertWrongNumPending($consumer, 1);
    }

    public function testInterrupt()
    {
        $stream = $this->getClient()->getApi()->getStream('no_messages');
        $stream->getConfiguration()->setSubjects(['cucumber']);
        $stream->create();

        $consumer = $stream->getConsumer('test_consumer');
        $consumer->getConfiguration()->setSubjectFilter('cucumber')->setMaxAckPending(2);
        $consumer->setDelay(0)->create();

        $this->assertSame(2, $consumer->info()->getValue('config.max_ack_pending'));

        $this->getClient()->publish('cucumber', 'message1');
        $this->getClient()->publish('cucumber', 'message2');
        $this->getClient()->publish('cucumber', 'message3');
        $this->getClient()->publish('cucumber', 'message4');

        $this->assertWrongNumPending($consumer, 4);

        $consumer->setBatching(1)->setIterations(2)
            ->handle(function ($response) use ($consumer) {
                $consumer->interrupt();
                $this->logger?->info('interrupt!!');
            });

        $this->assertWrongNumPending($consumer, 3);

        $consumer->setBatching(2)->setIterations(1)
            ->handle(function ($response) use ($consumer) {
                $consumer->interrupt();
                $this->logger?->info('interrupt!!');
            });

        $this->assertWrongNumPending($consumer, 1);
    }

    public function testNoMessages()
    {
        $this->called = false;
        $this->empty = false;

        $stream = $this->createClient(['reconnect' => false])->getApi()->getStream('no_messages');
        $stream->getConfiguration()->setSubjects(['cucumber']);
        $stream->create();

        $consumer = $stream->getConsumer('test_consumer');
        $consumer->getConfiguration()->setSubjectFilter('cucumber');

        $consumer->create()
            ->setDelay(0)
            ->setIterations(1)
            ->setExpires(1)
            ->handle(function ($response) {
                $this->called = $response;
            }, function () {
                $this->empty = true;
            });

        $this->assertSame($consumer->getDelay(), floatval(0));
        $this->assertFalse($this->called);
        $this->assertTrue($this->empty);
    }

    public function testSingletons()
    {
        $api = $this->getClient()->getApi();
        $this->assertSame($api, $this->getClient()->getApi());

        $stream = $api->getStream('tester')->createIfNotExists();
        $this->assertSame($stream, $api->getStream('tester'));

        $consumer = $stream->getConsumer('worker');
        $this->assertSame($consumer, $stream->getConsumer('worker'));

        $this->assertCount(1, $api->getStreamList());
    }

    public function testConfguration()
    {
        $api = $this->createClient()->getApi();
        $stream = $api->getStream('tester');

        $stream->getConfiguration()
            ->setRetentionPolicy(RetentionPolicy::WORK_QUEUE)
            ->setStorageBackend(StorageBackend::MEMORY)
            ->setSubjects(['tester.greet', 'tester.bye']);

        $stream->create();

        $config = $stream->info()->config;
        $this->assertSame($config->retention, 'workqueue');
        $this->assertSame($config->storage, 'memory');
        $this->assertSame($config->subjects, ['tester.greet', 'tester.bye']);

        $stream->getConfiguration()->setSubjects(['tester.greet']);
        $stream->update();

        $this->assertSame($stream->info()->config->subjects, ['tester.greet']);

        $stream->getConfiguration()->setSubjects(['tester.greet', 'tester.bye']);
        $stream->update();

        $api = $this->createClient()->getApi();

        $api->getStream('tester')
            ->getConfiguration()
            ->fromArray($stream->getConfiguration()->toArray());

        $configuration = $api->getStream('tester')->getConfiguration();
        $this->assertSame($configuration->getRetentionPolicy(), RetentionPolicy::WORK_QUEUE);
        $this->assertSame($configuration->getStorageBackend(), StorageBackend::MEMORY);
        $this->assertSame($configuration->getSubjects(), ['tester.greet', 'tester.bye']);
    }

    public function testConsumer()
    {
        $api = $this->getClient()
            ->skipInvalidMessages(true)
            ->getApi();

        $this->assertSame($api->getInfo()->streams, 0);

        $stream = $api->getStream('my_stream');

        $stream->getConfiguration()
            ->setRetentionPolicy(RetentionPolicy::WORK_QUEUE)
            ->setSubjects(['tester.greet', 'tester.bye']);

        $stream->create();

        $this->called = null;
        $consumer = $stream->getConsumer('greet_consumer');
        $consumer->getConfiguration()->setSubjectFilter('tester.greet');
        $consumer->create();

        $this->assertNull($this->called);
        $consumer->setIterations(1);
        $consumer->handle($this->persistMessage(...));

        $this->assertNull($this->called);
        $stream->put('tester.greet', [ 'name' => 'nekufa' ]);
        $consumer->setIterations(1)->setExpires(1);
        $consumer->handle($this->persistMessage(...));

        $this->assertNotNull($this->called);
        $this->assertSame($this->called->name, 'nekufa');
        $this->assertNotNull($this->called->timestampNanos);

        $this->called = null;
        $consumer = $stream->getConsumer('bye');
        $consumer->getConfiguration()->setSubjectFilter('tester.bye');
        $consumer->getConfiguration()->setAckPolicy(AckPolicy::EXPLICIT);
        $consumer->create();

        $stream->put('tester.greet', [ 'name' => 'nekufa' ]);
        $consumer->setIterations(1)->setDelay(0);
        $consumer->handle($this->persistMessage(...));

        $this->assertNull($this->called);
    }

    public function testConsumerInactiveThreshold()
    {
        $api = $this->getClient()
            ->skipInvalidMessages(true)
            ->getApi();

        $this->assertSame($api->getInfo()->streams, 0);

        $stream = $api->getStream('my_stream_with_threshold');

        $stream->getConfiguration()
            ->setRetentionPolicy(RetentionPolicy::WORK_QUEUE)
            ->setSubjects(['tester.greet', 'tester.bye']);

        $stream->create();

        $this->called = null;
        $consumer = $stream->getConsumer('greet_consumer');

        $oneSecondInNanoseconds = 1000000000;
        $consumer->getConfiguration()->setSubjectFilter('tester.greet')->setInactiveThreshold($oneSecondInNanoseconds);
        $consumer->create();

        $this->assertCount(1, $stream->getConsumerNames());

        sleep(2);

        $this->assertCount(0, $stream->getConsumerNames());
    }

    public function testStreamInactiveThreshold()
    {
        $api = $this->getClient()
            ->skipInvalidMessages(true)
            ->getApi();

        $this->assertSame($api->getInfo()->streams, 0);

        $stream = $api->getStream('my_stream_with_threshold');

        $oneSecondInNanoseconds = 1000000000;
        $stream->getConfiguration()
            ->setRetentionPolicy(RetentionPolicy::WORK_QUEUE)
            ->setSubjects(['tester.greet', 'tester.bye'])
            ->setConsumerLimits(
                [
                    ConsumerLimits::INACTIVE_THRESHOLD => $oneSecondInNanoseconds,
                ]
            );

        $stream->create();

        $this->called = null;
        $consumer = $stream->getConsumer('greet_consumer');

        $consumer->getConfiguration()->setSubjectFilter('tester.greet');
        $consumer->create();

        $this->assertCount(1, $stream->getConsumerNames());

        sleep(2);

        $this->assertCount(0, $stream->getConsumerNames());
    }

    public function testEphemeralConsumer()
    {
        $api = $this->getClient()
            ->skipInvalidMessages(true)
            ->getApi();

        $this->assertSame($api->getInfo()->streams, 0);

        $stream = $api->getStream('my_stream');

        $stream->getConfiguration()
            ->setRetentionPolicy(RetentionPolicy::WORK_QUEUE)
            ->setSubjects(['tester.greet', 'tester.bye']);

        $stream->create();

        $this->called = null;

        $configuration = new Configuration('my_stream');
        $configuration->setSubjectFilter('tester.greet');
        $consumer1 = $stream->createEphemeralConsumer($configuration);
        $this->assertNull($this->called);

        // check that consumer can be received by name after creation
        $this->assertSame($consumer1, $stream->getConsumer($consumer1->getName()));

        $consumer1->setIterations(1);
        $consumer1->handle($this->persistMessage(...));

        $this->assertNull($this->called);
        $stream->put('tester.greet', [ 'name' => 'oxidmod' ]);
        $consumer1->setIterations(1)->setExpires(1);
        $consumer1->handle($this->persistMessage(...));

        $this->assertNotNull($this->called);
        $this->assertSame($this->called->name, 'oxidmod');

        $this->called = null;
        $configuration = new Configuration('my_stream');
        $configuration->setSubjectFilter('tester.bye')->setAckPolicy(AckPolicy::EXPLICIT);
        $consumer2 = $stream->createEphemeralConsumer($configuration);

        $stream->put('tester.greet', [ 'name' => 'oxidmod' ]);
        $consumer2->setIterations(1)->setDelay(0);
        $consumer2->handle($this->persistMessage(...));

        $this->assertNull($this->called);

        $this->assertCount(2, $stream->getConsumerNames());

        $this->client = null;

        # consumers removing process takes some time
        for ($i = 1; $i <= 30; $i++) {
            sleep(1);

            $stream = $this->getClient()->getApi()->getStream('my_stream');
            if (count($stream->getConsumerNames()) === 0) {
                break;
            }

            $this->client = null;
        }

        $stream = $this->getClient()->getApi()->getStream('my_stream');
        $this->assertCount(0, $stream->getConsumerNames());
    }

    public function persistMessage(Payload $message)
    {
        $this->called = $message->isEmpty() ? null : $message;
    }

    public function testBatching()
    {
        $client = $this->createClient();
        $stream = $client->getApi()->getStream('tester_' . rand(111, 999));
        $name = $stream->getConfiguration()->name;
        $stream->getConfiguration()->setSubjects([$name]);
        $stream->create();

        foreach (range(1, 10) as $tid) {
            $stream->put($name, ['tid' => $tid]);
        }

        $consumer = $stream->getConsumer('test');
        $consumer->getConfiguration()->setSubjectFilter($name);
        $consumer->setExpires(0.5);

        // [1] using 1 iteration
        $consumer->setIterations(1);
        $this->assertSame(1, $consumer->handle($this->persistMessage(...)));
        $this->assertNotNull($this->called);
        $this->assertSame($this->called->tid, 1);

        // [2], [3] using 2 iterations
        $consumer->setIterations(2);
        $this->assertSame(2, $consumer->handle($this->persistMessage(...)));
        $this->assertNotNull($this->called);
        $this->assertSame($this->called->tid, 3);

        // [4, 5] using 1 iteration
        $consumer->setBatching(2)->setIterations(1);
        $this->assertSame(2, $consumer->handle($this->persistMessage(...)));
        $this->assertNotNull($this->called);
        $this->assertSame($this->called->tid, 5);

        // [6, 7], [8, 9] using 2 iterations
        $consumer->setBatching(2)->setIterations(2);
        $this->assertSame(4, $consumer->handle($this->persistMessage(...)));

        // [10] using 1 iteration
        $consumer->setBatching(1)->setIterations(1);
        $this->assertSame(1, $consumer->handle($this->persistMessage(...)));

        // no more messages
        $consumer->setIterations(1);
        $this->assertSame(0, $consumer->handle($this->persistMessage(...)));
    }

    private function assertWrongNumPending(Consumer $consumer, ?int $expected = null, int $loops = 100): void
    {
        for ($i = 1; $i <= $loops; $i++) {
            $actual = $consumer->info()->getValue('num_pending');

            if ($actual === $expected) {
                break;
            }

            if ($i == $loops) {
                $this->assertSame($expected, $actual);
            }
        }
    }

    public function testFetchLessThanBatch()
    {
        $client = $this->createClient(['timeout' => 10])->setDelay(0);
        $stream = $client->getApi()->getStream('test_fetch_no_wait');
        $stream
            ->getConfiguration()
            ->setRetentionPolicy(RetentionPolicy::INTEREST)
            ->setStorageBackend(StorageBackend::MEMORY)
            ->setSubjects(['test']);

        $stream->create();

        $consumer = $stream->getConsumer('fetch_no_waiter');
        $consumer->getConfiguration()
            ->setSubjectFilter('test')
            ->setDeliverPolicy(DeliverPolicy::NEW);
        $consumer->create();
        $consumer
            ->setBatching(self::CONSUMER_BATCH_SIZE)
            ->setExpires(0);

        foreach (range(1, 10) as $n) {
            $stream->publish('test', 'Hello, NATS JetStream '.$n.'!');
        }

        $fetching = microtime(true);
        // fetch more than available messages to test no-wait behavior
        $messages = $consumer->getQueue()->fetchAll($consumer->getBatching());
        $fetching = microtime(true) - $fetching;

        $this->logger?->info('fetched with no-wait', [
            'length' => count($messages),
            'time' => $fetching,
        ]);

        // 10 messages were published + 1 404 message to signal the empty stream
        $this->assertCount(11, $messages);
        $last = end($messages);

        try {
            $this->assertEquals('404', $last->payload->getHeader('Status-Code'), 'Last message should be 404');
        } catch (Exception) {
            // https://github.com/nats-io/nats-server/pull/7466
            $this->assertEquals('408', $last->payload->getHeader('Status-Code'), 'Last message should be 408 for legacy nats installations');
        }
    }

    /**
     * Test that the allow_msg_schedules stream configuration option is correctly
     * sent to the NATS server and reflected in the stream info response.
     *
     * This test verifies:
     * 1. A stream can be created with allowMsgSchedules=true
     * 2. The NATS server acknowledges the setting in its STREAM.INFO response
     * 3. The configuration round-trips correctly: toArray() produces the right
     *    key, and fromArray() on a fresh Configuration object restores it
     *
     * Requires NATS Server >= 2.12.0 (skipped on older versions).
     *
     * @see https://github.com/nats-io/nats-architecture-and-design/blob/main/adr/ADR-51.md
     */
    public function testAllowMsgSchedulesStreamConfig(): void
    {
        // Skip this test if the NATS server is older than 2.12.0,
        // which does not support the allow_msg_schedules config option.
        $this->requireMinNatsVersion('2.12.0');

        $client = $this->createClient();
        $stream = $client->getApi()->getStream('schedtest');

        // Configure the stream with scheduling enabled.
        // The wildcard subject 'schedtest.*' allows both schedule trigger
        // subjects and target subjects to live within the same stream.
        $stream->getConfiguration()
            ->setSubjects(['schedtest.*'])
            ->setAllowMsgSchedules(true);

        $stream->create();

        // Verify the server reports allow_msg_schedules=true in the stream info.
        $config = $stream->info()->config;
        $this->assertTrue($config->allow_msg_schedules);

        // Verify the configuration round-trips correctly:
        // export via toArray() and restore via fromArray() on a new instance.
        $exported = $stream->getConfiguration()->toArray();
        $this->assertTrue($exported['allow_msg_schedules']);

        $client2 = $this->createClient();
        $restored = $client2->getApi()->getStream('schedtest');
        $restored->getConfiguration()->fromArray($exported);
        $this->assertTrue($restored->getConfiguration()->getAllowMsgSchedules());
    }

    /**
     * Test that a message scheduled for future delivery via the @at directive
     * is delivered to the target subject only after the scheduled time.
     *
     * How message scheduling works (ADR-51):
     * - A message is published to a "schedule" subject within the stream,
     *   with two special headers:
     *   - Nats-Schedule: "@at <RFC3339 timestamp>" — when to deliver
     *   - Nats-Schedule-Target: the subject to deliver to (must be in same stream)
     * - The NATS server holds the message and publishes it to the target
     *   subject at the specified time.
     * - The produced message includes a server-set "Nats-Scheduler" header
     *   identifying the source schedule subject.
     *
     * This test verifies:
     * 1. The message is NOT available on the target subject before the scheduled time
     * 2. The message IS delivered after the scheduled time passes
     * 3. The message body is preserved
     * 4. The server sets the Nats-Scheduler response header
     *
     * Requires NATS Server >= 2.12.0.
     *
     * @see https://github.com/nats-io/nats-architecture-and-design/blob/main/adr/ADR-51.md
     */
    public function testScheduledMessageDelivery(): void
    {
        $this->requireMinNatsVersion('2.12.0');

        // Use a longer timeout since we need to wait for the scheduled delivery.
        $client = $this->createClient(['timeout' => 5]);
        $stream = $client->getApi()->getStream('sched');

        // Create a stream with scheduling enabled and a wildcard subject
        // that covers both the schedule trigger and the delivery target.
        $stream->getConfiguration()
            ->setSubjects(['sched.*'])
            ->setAllowMsgSchedules(true);

        $stream->create();

        // Schedule a message for 4 seconds in the future.
        // 4 seconds gives enough headroom so that the immediate early-fetch
        // (which blocks for 1 second) cannot accidentally overlap with the
        // delivery window even if stream/consumer setup takes some time.
        $deliverAt = gmdate('Y-m-d\TH:i:s\Z', time() + 4);

        // Publish to the schedule trigger subject (sched.schedule).
        // The Nats-Schedule header tells the server WHEN to deliver.
        // The Nats-Schedule-Target header tells the server WHERE to deliver.
        $stream->put('sched.schedule', new Payload('scheduled-body', [
            'Nats-Schedule' => '@at ' . $deliverAt,
            'Nats-Schedule-Target' => 'sched.target',
        ]));

        // Create a consumer listening on the target subject (sched.target)
        // where the scheduled message will eventually be delivered.
        $consumer = $stream->getConsumer('sched_consumer');
        $consumer->getConfiguration()
            ->setSubjectFilter('sched.target')
            ->setAckPolicy(AckPolicy::EXPLICIT);
        $consumer->create();

        // Attempt to fetch immediately — the message should NOT be available yet
        // because the scheduled delivery time hasn't passed.
        $consumer->setExpires(1)->setIterations(1);
        $earlyMessage = null;
        $consumer->handle(function ($msg) use (&$earlyMessage) {
            $earlyMessage = $msg;
        });

        $this->assertNull($earlyMessage, 'Message should not be available before the scheduled time');

        // Wait for the scheduled time to pass (3 seconds is enough: delivery is
        // at t+4 and at least 1 second has already elapsed during setup+fetch).
        sleep(3);

        // Now fetch again — the message should have been delivered by the server
        // to the target subject.
        $consumer->setExpires(3)->setIterations(1);
        $received = null;
        $consumer->handle(function ($msg) use (&$received) {
            $received = $msg;
        });

        // Verify the message was delivered with the correct body.
        $this->assertNotNull($received, 'Scheduled message should be delivered after the delay');
        $this->assertSame('scheduled-body', (string) $received);

        // Verify the server set the Nats-Scheduler header on the produced message,
        // which identifies the schedule trigger subject that originated this delivery.
        $this->assertNotNull($received->getHeader('Nats-Scheduler'), 'Server should set Nats-Scheduler header');
    }

    /**
     * Test that a message scheduled with a timestamp in the past is delivered
     * immediately by the NATS server.
     *
     * Per ADR-51: "If a @at time is in the past, the message is sent immediately."
     * This is also the behavior when a server recovers after downtime — any
     * past-due schedules fire immediately upon recovery.
     *
     * This test verifies:
     * 1. A past-timestamp schedule doesn't cause an error
     * 2. The message is delivered to the target subject without waiting
     * 3. The message body is preserved
     *
     * Requires NATS Server >= 2.12.0.
     *
     * @see https://github.com/nats-io/nats-architecture-and-design/blob/main/adr/ADR-51.md
     */
    public function testScheduledMessagePastTimestamp(): void
    {
        $this->requireMinNatsVersion('2.12.0');

        $client = $this->createClient(['timeout' => 5]);
        $stream = $client->getApi()->getStream('schedpast');

        // Create a schedule-enabled stream.
        $stream->getConfiguration()
            ->setSubjects(['schedpast.*'])
            ->setAllowMsgSchedules(true);

        $stream->create();

        // Schedule a message with a timestamp 60 seconds in the past.
        // The server should deliver it to the target subject immediately.
        $pastTime = gmdate('Y-m-d\TH:i:s\Z', time() - 60);

        $stream->put('schedpast.trigger', new Payload('past-msg', [
            'Nats-Schedule' => '@at ' . $pastTime,
            'Nats-Schedule-Target' => 'schedpast.target',
        ]));

        // Give the server a brief moment to process the schedule and produce
        // the message on the target subject (should be near-instant).
        usleep(500_000);

        // Create a consumer on the target subject and attempt to fetch.
        $consumer = $stream->getConsumer('past_consumer');
        $consumer->getConfiguration()
            ->setSubjectFilter('schedpast.target')
            ->setAckPolicy(AckPolicy::EXPLICIT);
        $consumer->create();

        $consumer->setExpires(2)->setIterations(1);
        $received = null;
        $consumer->handle(function ($msg) use (&$received) {
            $received = $msg;
        });

        // The message should already be available — no waiting for a future time.
        $this->assertNotNull($received, 'Message with past timestamp should be delivered immediately');
        $this->assertSame('past-msg', (string) $received);
    }

    /**
     * Test the behavior when publishing a message with scheduling headers to a
     * stream that does NOT have allow_msg_schedules enabled.
     *
     * The NATS server may handle this in one of two ways:
     * - Reject the publish with a JetStream error (exception thrown)
     * - Accept the message but ignore the scheduling headers entirely,
     *   storing it as a regular message without producing to the target
     *
     * This test handles both cases: if an exception is thrown, the test passes.
     * If no exception, it verifies that nothing was delivered to the target
     * subject, confirming scheduling did not take place.
     *
     * Requires NATS Server >= 2.12.0.
     *
     * @see https://github.com/nats-io/nats-architecture-and-design/blob/main/adr/ADR-51.md
     */
    public function testScheduleRejectedWithoutAllowMsgSchedules(): void
    {
        $this->requireMinNatsVersion('2.12.0');

        $client = $this->createClient(['timeout' => 3]);
        $stream = $client->getApi()->getStream('nosched');

        // Intentionally create the stream WITHOUT setAllowMsgSchedules(true).
        // This means the stream should not process scheduling headers.
        $stream->getConfiguration()
            ->setSubjects(['nosched.*']);

        $stream->create();

        // Confirm the server reports that scheduling is not enabled.
        $config = $stream->info()->config;
        $this->assertFalse(
            $config->allow_msg_schedules ?? false,
            'allow_msg_schedules should not be set'
        );

        // Attempt to publish a message with scheduling headers.
        // Using publish() (JetStream-acked) to detect potential server errors.
        $futureTime = gmdate('Y-m-d\TH:i:s\Z', time() + 60);

        $rejected = false;
        try {
            $stream->publish('nosched.trigger', new Payload('no-schedule-body', [
                'Nats-Schedule' => '@at ' . $futureTime,
                'Nats-Schedule-Target' => 'nosched.target',
            ]));
        } catch (Exception $e) {
            // Server rejected the publish — this is a valid outcome.
            $rejected = true;
        }

        if ($rejected) {
            // The server refused to store a scheduled message on a stream
            // without allow_msg_schedules — test passes.
            $this->assertTrue(true);
            return;
        }

        // If the server accepted the message without error, verify that no
        // message was scheduled for delivery to the target subject.
        // The scheduling headers should have been ignored.
        $targetConsumer = $stream->getConsumer('nosched_target_consumer');
        $targetConsumer->getConfiguration()
            ->setSubjectFilter('nosched.target')
            ->setAckPolicy(AckPolicy::EXPLICIT);
        $targetConsumer->create();

        $targetConsumer->setExpires(1)->setIterations(1);
        $targetReceived = null;
        $targetConsumer->handle(function ($msg) use (&$targetReceived) {
            $targetReceived = $msg;
        });

        // No message should appear on the target subject — scheduling was not active.
        $this->assertNull($targetReceived, 'No message should be delivered to target without allow_msg_schedules');
    }

    /**
     * Test message delivery ordering with multiple scheduled and immediate messages,
     * using longer delays (15s and 30s) to verify that the NATS scheduler correctly
     * interleaves scheduled and regular messages based on their delivery times.
     *
     * Timeline of this test:
     *
     *   T+0s:  Schedule message A for T+30s ("delay-30s")
     *   T+0s:  Schedule message B for T+15s ("delay-15s")
     *   T+0s:  Publish immediate-1 and immediate-2 directly to target
     *   T+0s:  Consume → expect immediate-1, immediate-2
     *   T+16s: Wait for 15s schedule to fire
     *   T+16s: Consume → expect delay-15s
     *   T+16s: Publish between-1 and between-2 directly to target
     *   T+16s: Consume → expect between-1, between-2
     *   T+20s: Verify the 30s message has NOT arrived yet
     *   T+31s: Wait for 30s schedule to fire
     *   T+31s: Consume → expect delay-30s
     *
     * Expected delivery order:
     *   1. immediate-1  (regular, available at T+0)
     *   2. immediate-2  (regular, available at T+0)
     *   3. delay-15s    (scheduled, delivered at ~T+15)
     *   4. between-1    (regular, published at ~T+16)
     *   5. between-2    (regular, published at ~T+16)
     *   6. delay-30s    (scheduled, delivered at ~T+30)
     *
     * Key behaviors verified:
     * - Immediate messages are available instantly, before any scheduled delivery
     * - Scheduled messages are held until their @at time, then produced to target
     * - Regular messages published between two scheduled deliveries appear in
     *   their correct chronological position
     * - A scheduled message does not "leak" early (verified by explicit check
     *   before the 30s mark)
     * - Each schedule subject holds one schedule (sched30 and sched15 are separate)
     *
     * Requires NATS Server >= 2.12.0. Runtime: ~33 seconds.
     *
     * @see https://github.com/nats-io/nats-architecture-and-design/blob/main/adr/ADR-51.md
     */
    public function testScheduledMessageOrderingWithDelays(): void
    {
        // Skip on NATS servers older than 2.12.0 which lack scheduling support.
        $this->requireMinNatsVersion('2.12.0');

        // Use a generous timeout (40s) since this test spans ~33 seconds of
        // real time waiting for scheduled deliveries.
        $client = $this->createClient(['timeout' => 40]);
        $stream = $client->getApi()->getStream('schedorder');

        // Create a stream with scheduling enabled.
        // The wildcard 'schedorder.*' covers all subjects used in this test:
        //   - schedorder.sched30  (schedule trigger for the 30s delayed message)
        //   - schedorder.sched15  (schedule trigger for the 15s delayed message)
        //   - schedorder.target   (delivery target for scheduled + regular messages)
        $stream->getConfiguration()
            ->setSubjects(['schedorder.*'])
            ->setAllowMsgSchedules(true);

        $stream->create();

        // Record the start time to calculate precise sleep durations later.
        $now = time();

        // --- Phase 1: Publish all messages at T+0 ---

        // Schedule a message for 30 seconds from now.
        // Published to 'schedorder.sched30' — the server holds it and will
        // produce it to 'schedorder.target' at the @at time.
        // Note: each unique subject can hold only one schedule (ADR-51).
        $at30 = gmdate('Y-m-d\TH:i:s\Z', $now + 30);
        $stream->put('schedorder.sched30', new Payload('delay-30s', [
            'Nats-Schedule' => '@at ' . $at30,
            'Nats-Schedule-Target' => 'schedorder.target',
        ]));

        // Schedule a message for 15 seconds from now.
        // Published to a different trigger subject 'schedorder.sched15'.
        $at15 = gmdate('Y-m-d\TH:i:s\Z', $now + 15);
        $stream->put('schedorder.sched15', new Payload('delay-15s', [
            'Nats-Schedule' => '@at ' . $at15,
            'Nats-Schedule-Target' => 'schedorder.target',
        ]));

        // Publish two regular (non-scheduled) messages directly to the target
        // subject. These should be available for consumption immediately.
        $stream->put('schedorder.target', new Payload('immediate-1'));
        $stream->put('schedorder.target', new Payload('immediate-2'));

        // --- Phase 2: Set up consumer and collection helper ---

        // Create a consumer filtered to the target subject where all messages
        // (both regular and scheduled deliveries) will appear.
        $consumer = $stream->getConsumer('order_consumer');
        $consumer->getConfiguration()
            ->setSubjectFilter('schedorder.target')
            ->setAckPolicy(AckPolicy::EXPLICIT);
        $consumer->create();

        // Track all delivered messages in order, along with their delivery times.
        $deliveredMessages = [];
        $deliveredTimes = [];

        // Helper closure that fetches one iteration of messages from the consumer.
        // Each call to $collect() will attempt to receive messages for up to 2s.
        $collect = function () use ($consumer, &$deliveredMessages, &$deliveredTimes) {
            $consumer->setExpires(2)->setIterations(1);
            $consumer->handle(function ($msg) use (&$deliveredMessages, &$deliveredTimes) {
                $deliveredMessages[] = (string) $msg;
                $deliveredTimes[] = time();
            });
        };

        // --- Phase 3: Consume immediate messages (T+0) ---

        // Fetch the two regular messages that were published directly to target.
        // They should already be available since they're not scheduled.
        $collect();
        $collect();

        $this->assertCount(2, $deliveredMessages, 'Both immediate messages should arrive before any delay');
        $this->assertSame('immediate-1', $deliveredMessages[0]);
        $this->assertSame('immediate-2', $deliveredMessages[1]);

        // --- Phase 4: Wait for the 15s schedule, then consume it (T+16) ---

        // Sleep until just past the 15-second mark to ensure the scheduled
        // message has been produced to the target subject by the server.
        $elapsed = time() - $now;
        if ($elapsed < 16) {
            sleep(16 - $elapsed);
        }

        // The 15s scheduled message should now have been delivered by the server.
        $collect();

        $this->assertCount(3, $deliveredMessages, 'The 15s scheduled message should have arrived');
        $this->assertSame('delay-15s', $deliveredMessages[2]);

        // --- Phase 5: Publish "between" messages in the 15s-30s window ---

        // Send two more regular messages to the target subject.
        // These arrive after the 15s schedule but before the 30s schedule,
        // demonstrating that regular and scheduled messages interleave correctly.
        $stream->put('schedorder.target', new Payload('between-1'));
        $stream->put('schedorder.target', new Payload('between-2'));

        // Consume the two "between" messages.
        $collect();
        $collect();

        $this->assertCount(5, $deliveredMessages);
        $this->assertSame('between-1', $deliveredMessages[3]);
        $this->assertSame('between-2', $deliveredMessages[4]);

        // --- Phase 6: Verify the 30s message has NOT arrived early ---

        // Attempt a fetch now (around T+18-20). The 30s scheduled message
        // should not yet be available — the server holds it until T+30.
        $consumer->setExpires(1)->setIterations(1);
        $premature = null;
        $consumer->handle(function ($msg) use (&$premature) {
            $premature = (string) $msg;
        });
        $this->assertNull($premature, 'The 30s scheduled message should not arrive early');

        // --- Phase 7: Wait for the 30s schedule, then consume it (T+31) ---

        // Sleep until just past the 30-second mark.
        $elapsed = time() - $now;
        if ($elapsed < 31) {
            sleep(31 - $elapsed);
        }

        // The 30s scheduled message should now have been delivered.
        $collect();

        $this->assertCount(6, $deliveredMessages, 'The 30s scheduled message should have arrived');
        $this->assertSame('delay-30s', $deliveredMessages[5]);

        // --- Phase 8: Verify the complete delivery order ---

        // Assert that all 6 messages arrived in the expected chronological order:
        //   1-2: Regular messages (instant)
        //   3:   Scheduled at +15s
        //   4-5: Regular messages (published at ~+16s)
        //   6:   Scheduled at +30s
        $this->assertSame([
            'immediate-1',
            'immediate-2',
            'delay-15s',
            'between-1',
            'between-2',
            'delay-30s',
        ], $deliveredMessages);
    }
}
