<?php

declare(strict_types=1);

namespace Tests\Unit\Message;

use Basis\Nats\Client;
use Basis\Nats\Connection;
use Basis\Nats\Message\Ack;
use Basis\Nats\Message\Msg;
use Basis\Nats\Message\Payload;
use Exception;
use LogicException;
use Tests\TestCase;

class MsgTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Msg::create() – argument-count branches
    // -------------------------------------------------------------------------

    public function testCreate3Args(): void
    {
        $msg = Msg::create('subject sid 42');
        $this->assertSame('subject', $msg->subject);
        $this->assertSame('sid', $msg->sid);
        $this->assertSame(42, $msg->length);
        $this->assertNull($msg->replyTo);
        $this->assertNull($msg->hlength);
    }

    public function testCreate4ArgsWithStringReplyTo(): void
    {
        $msg = Msg::create('subject sid reply.here 42');
        $this->assertSame('reply.here', $msg->replyTo);
        $this->assertNull($msg->hlength);
        $this->assertSame(42, $msg->length);
    }

    public function testCreate4ArgsWithNumericReplyToDeclaredAsHlength(): void
    {
        // When the 3rd token is numeric it is treated as hlength, not replyTo
        $msg = Msg::create('subject sid 16 42');
        $this->assertNull($msg->replyTo);
        $this->assertSame(16, $msg->hlength);
        $this->assertSame(42, $msg->length);
    }

    public function testCreate5Args(): void
    {
        $msg = Msg::create('subject sid reply.here 16 42');
        $this->assertSame('reply.here', $msg->replyTo);
        $this->assertSame(16, $msg->hlength);
        $this->assertSame(42, $msg->length);
    }

    public function testCreateThrowsOnTooFewArgs(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessageMatches('/Invalid Msg/');
        Msg::create('subject sid');
    }

    // -------------------------------------------------------------------------
    // Msg::parse() – payload and header parsing
    // -------------------------------------------------------------------------

    public function testParseWithoutHeaders(): void
    {
        $msg = Msg::create('subject sid 5');
        $msg->parse('hello');

        $this->assertSame('hello', $msg->payload->body);
        $this->assertEmpty($msg->payload->headers);
    }

    public function testParseWithStatusHeader(): void
    {
        $headerString = "NATS/1.0 404 Not Found\r\n\r\n";
        $body = 'body';
        $payload = $headerString . $body;

        $msg = Msg::create('subject sid reply.here ' . strlen($headerString) . ' ' . strlen($payload));
        $msg->parse($payload);

        $this->assertSame('404', $msg->payload->headers['Status-Code']);
        $this->assertSame('Not Found', $msg->payload->headers['Status-Message']);
        $this->assertSame($body, $msg->payload->body);
    }

    public function testParseWithKeyValueHeader(): void
    {
        $headerString = "NATS/1.0\r\nFoo: Bar\r\n\r\n";
        $body = 'content';
        $payload = $headerString . $body;

        $msg = Msg::create('subject sid reply.here ' . strlen($headerString) . ' ' . strlen($payload));
        $msg->parse($payload);

        $this->assertSame('Bar', $msg->payload->headers['Foo']);
        $this->assertSame($body, $msg->payload->body);
    }

    public function testParseThrowsOnInvalidHeaderRow(): void
    {
        $headerString = "NATS/1.0\r\nInvalidHeader\r\n\r\n";
        $payload = $headerString . 'body';

        $msg = Msg::create('subject sid reply.here ' . strlen($headerString) . ' ' . strlen($payload));

        $this->expectException(Exception::class);
        $this->expectExceptionMessageMatches('/Invalid header row/');
        $msg->parse($payload);
    }

    public function testParseEmptyNATSHeaderLineContinues(): void
    {
        // A NATS/ line with only one token (no status code) is skipped gracefully
        $headerString = "NATS/1.0\r\n\r\n";
        $body = 'ok';
        $payload = $headerString . $body;

        $msg = Msg::create('subject sid reply.here ' . strlen($headerString) . ' ' . strlen($payload));
        $msg->parse($payload);

        $this->assertSame($body, $msg->payload->body);
        $this->assertArrayNotHasKey('Status-Code', $msg->payload->headers);
    }

    // -------------------------------------------------------------------------
    // tryParseMessageTime() via Msg::create() – JetStream reply-to subjects
    // -------------------------------------------------------------------------

    public function testTimestampParsedFromOldJsAckFormat(): void
    {
        // Old format – 9 tokens: $JS.ACK.<stream>.<consumer>.<del>.<sSeq>.<dSeq>.<ts>.<pending>
        $replyTo = '$JS.ACK.mystream.myconsumer.1.3.18.1719992702186105579.0';
        $msg = Msg::create("subject sid $replyTo 0");
        $this->assertSame(1719992702186105579, $msg->timestampNanos);
    }

    public function testTimestampParsedFromNewJsAckFormat(): void
    {
        // New format – 12 tokens: $JS.ACK.<domain>.<hash>.<stream>.<consumer>.<del>.<sSeq>.<dSeq>.<ts>.<pending>.<random>
        $replyTo = '$JS.ACK.domain.ACCHASH.mystream.myconsumer.1.3.18.1719992702186105579.0.abc123';
        $msg = Msg::create("subject sid $replyTo 0");
        $this->assertSame(1719992702186105579, $msg->timestampNanos);
    }

    public function testTimestampNullForInvalidJsAckTokenCount(): void
    {
        // Fewer than 9 and not 9: timestamp stays null
        $replyTo = '$JS.ACK.tooshort.format';
        $msg = Msg::create("subject sid $replyTo 0");
        $this->assertNull($msg->timestampNanos);
    }

    public function testTimestampNullForNonJsAckReplyTo(): void
    {
        $msg = Msg::create('subject sid regular.reply.subject 0');
        $this->assertNull($msg->timestampNanos);
    }

    // -------------------------------------------------------------------------
    // reply() / ack() / nack() / progress() / term() – require a client
    // -------------------------------------------------------------------------

    public function testReplyThrowsWithoutReplyTo(): void
    {
        $msg = Msg::create('subject sid 0');
        $msg->parse('');
        $msg->setClient($this->createMock(Client::class));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Invalid replyTo property');
        // Call reply() directly: ack/nack/etc. would throw TypeError earlier
        // because they construct the message with null subject before reaching reply().
        $msg->reply('payload');
    }

    public function testAckSendsAckMessage(): void
    {
        $msg = $this->msgWithClientMock(expectedSendMessage: true);
        $msg->ack(); // no exception = pass
        $this->assertTrue(true);
    }

    public function testNackSendsNakMessage(): void
    {
        $msg = $this->msgWithClientMock(expectedSendMessage: true);
        $msg->nack(0.5);
        $this->assertTrue(true);
    }

    public function testProgressSendsProgressMessage(): void
    {
        $msg = $this->msgWithClientMock(expectedSendMessage: true);
        $msg->progress();
        $this->assertTrue(true);
    }

    public function testTermSendsTermMessage(): void
    {
        $msg = $this->msgWithClientMock(expectedSendMessage: true);
        $msg->term('bad payload');
        $this->assertTrue(true);
    }

    public function testReplyWithNonPrototypeCallsPublish(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->never())->method('sendMessage');

        $client = $this->createMock(Client::class);
        $client->connection = $connection;
        $client->expects($this->once())
            ->method('publish')
            ->with('reply.to', 'raw string');

        $msg = Msg::create('subject sid reply.to 0');
        $msg->parse('');
        $msg->setClient($client);
        $msg->reply('raw string');
    }

    // -------------------------------------------------------------------------
    // __toString()
    // -------------------------------------------------------------------------

    public function testToString(): void
    {
        $msg = Msg::create('subject sid 5');
        $msg->parse('hello');
        $this->assertSame('hello', (string) $msg);
    }

    // -------------------------------------------------------------------------
    // render()
    // -------------------------------------------------------------------------

    public function testRender(): void
    {
        $msg = Msg::create('subject sid 3');
        $rendered = $msg->render();
        $this->assertStringStartsWith('MSG ', $rendered);
        $this->assertStringContainsString('"subject":"subject"', $rendered);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Build a Msg with a replyTo set and a mock Client whose Connection
     * expects sendMessage() to be called once.
     */
    private function msgWithClientMock(bool $expectedSendMessage = false): Msg
    {
        $connection = $this->createMock(Connection::class);
        if ($expectedSendMessage) {
            $connection->expects($this->once())->method('sendMessage');
        }

        $client = $this->createMock(Client::class);
        $client->connection = $connection;

        $msg = Msg::create('subject sid reply.to 0');
        $msg->parse('');
        $msg->setClient($client);

        return $msg;
    }
}
