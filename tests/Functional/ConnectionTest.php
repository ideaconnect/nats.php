<?php

declare(strict_types=1);

namespace Tests\Functional;

use Basis\Nats\Connection;
use Basis\Nats\Message\Info;
use Basis\Nats\Message\Payload;
use Basis\Nats\Message\Publish;
use Basis\Nats\Message\Subscribe;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use ReflectionProperty;
use Tests\FunctionalTestCase;

class ConnectionTest extends FunctionalTestCase
{
    // -------------------------------------------------------------------------
    // setLogger()
    // -------------------------------------------------------------------------

    public function testSetLogger(): void
    {
        $client = $this->createClient();
        $connection = $client->connection;

        $logger = new Logger('test');
        $connection->setLogger($logger);

        $this->assertSame($logger, $connection->logger);
    }

    public function testSetLoggerAcceptsNull(): void
    {
        $client = $this->createClient();
        $client->connection->setLogger(null);
        $this->assertNull($client->connection->logger);
    }

    public function testLoggerReceivesMessagesOnPing(): void
    {
        $client = $this->createClient();

        $handler = new TestHandler();
        $logger  = new Logger('test', [$handler]);
        $client->connection->setLogger($logger);

        $client->ping();

        // PING was sent and a reply was received – at least debug records emitted
        $this->assertNotEmpty($handler->getRecords());
        $this->assertTrue(
            $handler->hasDebugThatContains('PING') || $handler->hasDebugThatContains('send')
        );
    }

    // -------------------------------------------------------------------------
    // getConnectMessage() / getInfoMessage()
    // -------------------------------------------------------------------------

    public function testGetConnectMessageAfterConnect(): void
    {
        $client = $this->createClient();
        $client->ping(); // forces init()

        $connect = $client->connection->getConnectMessage();

        $this->assertSame('php', $connect->lang);
    }

    public function testGetInfoMessageAfterConnect(): void
    {
        $client = $this->createClient();
        $client->ping();

        $info = $client->connection->getInfoMessage();

        $this->assertInstanceOf(Info::class, $info);
        $this->assertNotEmpty($info->server_id);
        $this->assertNotEmpty($info->version);
    }

    // -------------------------------------------------------------------------
    // ping() directly on the connection
    // -------------------------------------------------------------------------

    public function testConnectionPingReturnsTrueOnLiveServer(): void
    {
        $client = $this->createClient();
        $client->ping(); // init the socket

        $this->assertTrue($client->connection->ping());
    }

    // -------------------------------------------------------------------------
    // setTimeout()
    // -------------------------------------------------------------------------

    public function testSetTimeoutDoesNotThrow(): void
    {
        $client = $this->createClient();
        $client->ping();

        // setTimeout should just configure stream metadata; if it throws the
        // test fails, otherwise it's a pass.
        $client->connection->setTimeout(2.5);
        $this->assertTrue(true);
    }

    // -------------------------------------------------------------------------
    // sendMessage() / getMessage() round-trip
    // -------------------------------------------------------------------------

    public function testSendMessageAndGetMessageRoundTrip(): void
    {
        $client = $this->createClient();
        $client->ping();
        $connection = $client->connection;

        $subject = 'conn.test.' . uniqid();

        // Subscribe on a second client so the message is actually delivered
        $receiver = $this->createClient();
        $receiver->ping();
        $connection2 = $receiver->connection;
        $sid = 'sid1';
        $connection2->sendMessage(new Subscribe([
            'subject' => $subject,
            'sid'     => $sid,
        ]));
        // Flush: NATS processes SUB before returning PONG, ensuring the
        // subscription is registered before we publish.
        $connection2->ping();

        // Publish via the first connection
        $connection->sendMessage(new Publish([
            'subject' => $subject,
            'payload' => Payload::parse('hello-connection'),
        ]));

        $message = $connection2->getMessage(1);

        $this->assertNotNull($message);
        $this->assertSame('hello-connection', (string) $message);
    }

    // -------------------------------------------------------------------------
    // setPacketSize()
    // -------------------------------------------------------------------------

    public function testSetPacketSizeAffectsProperty(): void
    {
        $client = $this->createClient();
        $client->connection->setPacketSize(256);

        $prop = new ReflectionProperty(Connection::class, 'packetSize');
        $this->assertSame(256, $prop->getValue($client->connection));
    }

    public function testSendLargeMessageWithSmallPacketSize(): void
    {
        $client = $this->createClient();
        $client->ping();
        $client->connection->setPacketSize(16);

        $subject = 'conn.largemsg.' . uniqid();
        $body    = str_repeat('x', 512);

        $receiver = $this->createClient();
        $receiver->ping();
        $receiver->connection->sendMessage(new Subscribe([
            'subject' => $subject,
            'sid'     => 's2',
        ]));
        // Flush subscription before publishing
        $receiver->connection->ping();

        $client->connection->sendMessage(new Publish([
            'subject' => $subject,
            'payload' => Payload::parse($body),
        ]));


        $message = $receiver->connection->getMessage(1);

        $this->assertNotNull($message);
        $this->assertSame($body, (string) $message);
    }

    // -------------------------------------------------------------------------
    // close()
    // -------------------------------------------------------------------------

    public function testCloseNullsSocket(): void
    {
        $client = $this->createClient();
        $client->ping();

        $prop = new ReflectionProperty(Connection::class, 'socket');
        $this->assertNotNull($prop->getValue($client->connection));

        $client->connection->close();

        $this->assertNull($prop->getValue($client->connection));
    }

    // -------------------------------------------------------------------------
    // processException / reconnect
    // -------------------------------------------------------------------------

    public function testReconnectAfterSocketClose(): void
    {
        $client = $this->createClient(['reconnect' => true]);
        $client->ping();

        $prop = new ReflectionProperty(Connection::class, 'socket');
        fclose($prop->getValue($client->connection));

        // After socket is closed, next ping should transparently reconnect
        $this->assertTrue($client->ping());
    }

    public function testNoReconnectThrowsOnSocketClose(): void
    {
        $client = $this->createClient(['reconnect' => false]);
        $client->ping();

        $prop = new ReflectionProperty(Connection::class, 'socket');
        fclose($prop->getValue($client->connection));

        $this->expectExceptionMessage('supplied resource is not a valid stream resource');
        $client->process(1);
    }
}
