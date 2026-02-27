<?php

declare(strict_types=1);

namespace Tests\Unit;

use Basis\Nats\Client;
use Basis\Nats\Configuration;
use Basis\Nats\Connection;
use Basis\Nats\Message\Info;
use Basis\Nats\Message\Msg;
use Basis\Nats\Message\Pong;
use LogicException;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use ReflectionProperty;
use Tests\TestCase;

/**
 * Unit tests for Connection using in-process socket pairs so no real NATS
 * server is needed.
 */
class ConnectionTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Trivial method tests (no socket needed)
    // -------------------------------------------------------------------------

    public function testSetLogger(): void
    {
        $connection = $this->bareConnection();

        $logger = new Logger('test');
        $connection->setLogger($logger);

        $this->assertSame($logger, $connection->logger);
    }

    public function testSetLoggerAcceptsNull(): void
    {
        $connection = $this->bareConnection();
        $connection->setLogger(null);
        $this->assertNull($connection->logger);
    }

    public function testSetPacketSize(): void
    {
        $connection = $this->bareConnection();
        $connection->setPacketSize(512);

        $prop = new ReflectionProperty(Connection::class, 'packetSize');
        $this->assertSame(512, $prop->getValue($connection));
    }

    // -------------------------------------------------------------------------
    // close()
    // -------------------------------------------------------------------------

    public function testCloseWhenSocketIsNull(): void
    {
        // close() must be a no-op when socket is already null
        $connection = $this->bareConnection();
        $connection->close(); // must not throw
        $this->assertTrue(true);
    }

    public function testCloseNullsTheSocket(): void
    {
        [$connection, $server] = $this->pipeConnection();

        $prop = new ReflectionProperty(Connection::class, 'socket');
        $this->assertNotNull($prop->getValue($connection));

        $connection->close();

        $this->assertNull($prop->getValue($connection));
        fclose($server);
    }

    // -------------------------------------------------------------------------
    // getMessage() – various message types
    // -------------------------------------------------------------------------

    public function testGetMessageThrowsOnEof(): void
    {
        [$connection, $server] = $this->pipeConnection();

        // Close the server-side socket so the client socket sees EOF
        fclose($server);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('supplied resource is not a valid stream resource');
        $connection->getMessage(0);
    }

    public function testGetMessageReturnsNullOnTimeout(): void
    {
        [$connection, $server] = $this->pipeConnection();

        // Nothing written → timeout immediately (timeout=0 means one pass)
        $result = $connection->getMessage(0);
        $this->assertNull($result);

        fclose($server);
        $connection->close();
    }

    public function testGetMessageHandlesOk(): void
    {
        [$connection, $server] = $this->pipeConnection();

        // +OK should be consumed silently; next iteration falls through timeout
        fwrite($server, "+OK\r\n");
        $result = $connection->getMessage(0);

        // getMessage loop exits after one pass with OK consumed; returns null
        $this->assertNull($result);

        fclose($server);
        $connection->close();
    }

    public function testGetMessageHandlesPingSendsPong(): void
    {
        [$connection, $server] = $this->pipeConnection();

        fwrite($server, "PING\r\n");
        $connection->getMessage(0);

        // Connection should have written "PONG \r\n" back on the socket
        stream_set_blocking($server, false);
        $sent = fread($server, 1024);
        $this->assertStringContainsString('PONG', $sent);

        fclose($server);
        $connection->close();
    }

    public function testGetMessageHandlesPong(): void
    {
        [$connection, $server] = $this->pipeConnection();

        fwrite($server, "PONG\r\n");
        $result = $connection->getMessage(0);

        // PONG is handled internally (sets pongAt); getMessage returns null
        $this->assertNull($result);

        fclose($server);
        $connection->close();
    }

    public function testGetMessageReturnsInfo(): void
    {
        [$connection, $server] = $this->pipeConnection();

        $infoData = json_encode([
            'server_id' => 'TEST', 'server_name' => 'test',
            'version' => '2.10.0', 'go' => 'go1.21', 'host' => '0.0.0.0',
            'port' => 4222, 'proto' => 1, 'headers' => true,
        ]);
        fwrite($server, "INFO $infoData\r\n");

        $message = $connection->getMessage(0);

        $this->assertInstanceOf(Info::class, $message);
        $this->assertSame('TEST', $message->server_id);

        fclose($server);
        $connection->close();
    }

    public function testGetMessageReturnsMsg(): void
    {
        [$connection, $server] = $this->pipeConnection();

        $body = 'hello';
        fwrite($server, "MSG mysubject sid1 " . strlen($body) . "\r\n$body");

        $message = $connection->getMessage(0);

        $this->assertInstanceOf(Msg::class, $message);
        $this->assertSame('mysubject', $message->subject);
        $this->assertSame($body, $message->payload->body);

        fclose($server);
        $connection->close();
    }

    // -------------------------------------------------------------------------
    // sendMessage()
    // -------------------------------------------------------------------------

    public function testSendMessageWritesToSocket(): void
    {
        [$connection, $server] = $this->pipeConnection();

        $connection->sendMessage(new Pong([]));

        stream_set_blocking($server, false);
        $data = fread($server, 1024);
        $this->assertStringContainsString('PONG', $data);

        fclose($server);
        $connection->close();
    }

    public function testSendMessageLogsWhenLoggerSet(): void
    {
        [$connection, $server] = $this->pipeConnection();

        $handler = new TestHandler();
        $logger = new Logger('test', [$handler]);
        $connection->setLogger($logger);

        $connection->sendMessage(new Pong([]));

        $this->assertTrue($handler->hasDebugThatContains('PONG'));

        fclose($server);
        $connection->close();
    }

    public function testSendMessageThrowsWhenReconnectFalseAndSocketFails(): void
    {
        [$connection, $server] = $this->pipeConnection(reconnect: false);
        fclose($server);

        $prop = new ReflectionProperty(Connection::class, 'socket');
        $clientSock = $prop->getValue($connection);
        fclose($clientSock);

        // Closed resource: fwrite throws TypeError in PHP 8.x, which
        // processException() re-throws as-is when reconnect=false.
        $this->expectException(\Throwable::class);
        $connection->sendMessage(new Pong([]));
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * A bare Connection with no socket injected (cannot call any network method).
     */
    private function bareConnection(): Connection
    {
        $client = new Client(new Configuration(timeout: 0.1, reconnect: false));
        return new Connection($client);
    }

    /**
     * A Connection with a real in-process socket pair injected so no live NATS
     * server is required.
     *
     * @return array{Connection, resource}  [$connection, $serverSocket]
     */
    private function pipeConnection(bool $reconnect = true): array
    {
        [$serverSock, $clientSock] = stream_socket_pair(
            STREAM_PF_UNIX,
            STREAM_SOCK_STREAM,
            STREAM_IPPROTO_IP
        );

        stream_set_blocking($clientSock, false);
        stream_set_blocking($serverSock, true);

        $config = new Configuration(timeout: 0.05, reconnect: $reconnect);
        $client = new Client($config);
        $connection = new Connection($client);

        // Inject the client-side socket so init() sees it and skips connecting
        $prop = new ReflectionProperty(Connection::class, 'socket');
        $prop->setValue($connection, $clientSock);

        return [$connection, $serverSock];
    }
}
