<?php
declare(strict_types = 1);

namespace Tests\Innmind\IPC\Client;

use Innmind\IPC\{
    Client\Unix,
    Client,
    Protocol,
    Message,
    Message\ConnectionClose,
};
use Innmind\Socket\Server\Connection;
use Innmind\Immutable\Str;
use PHPUnit\Framework\TestCase;

class UnixTest extends TestCase
{
    public function testInterface()
    {
        $this->assertInstanceOf(
            Client::class,
            new Unix(
                $this->createMock(Connection::class),
                $this->createMock(Protocol::class)
            )
        );
    }

    public function testSend()
    {
        $client = new Unix(
            $connection = $this->createMock(Connection::class),
            $protocol = $this->createMock(Protocol::class)
        );
        $message = $this->createMock(Message::class);
        $connection
            ->expects($this->once())
            ->method('write')
            ->with(Str::of('watev'));
        $protocol
            ->expects($this->once())
            ->method('encode')
            ->with($message)
            ->willReturn(Str::of('watev'));

        $this->assertNull($client->send($message));
        $this->assertFalse($client->closed());
    }

    public function testClose()
    {
        $client = new Unix(
            $connection = $this->createMock(Connection::class),
            $protocol = $this->createMock(Protocol::class)
        );
        $connection
            ->expects($this->once())
            ->method('write')
            ->with(Str::of('watev'));
        $protocol
            ->expects($this->once())
            ->method('encode')
            ->with(new ConnectionClose)
            ->willReturn(Str::of('watev'));

        $this->assertFalse($client->closed());
        $this->assertNull($client->close());
        $this->assertTrue($client->closed());
    }

    public function testDoesntSendOnceClosed()
    {
        $client = new Unix(
            $connection = $this->createMock(Connection::class),
            $protocol = $this->createMock(Protocol::class)
        );
        $message = $this->createMock(Message::class);
        $connection
            ->expects($this->once())
            ->method('write')
            ->with(Str::of('watev'));
        $protocol
            ->expects($this->once())
            ->method('encode')
            ->with(new ConnectionClose)
            ->willReturn(Str::of('watev'));

        $client->close();
        $this->assertNull($client->send($message));
        $this->assertTrue($client->closed());
    }

    public function testDoesntReCloseIfAlreadyClosed()
    {
        $client = new Unix(
            $connection = $this->createMock(Connection::class),
            $protocol = $this->createMock(Protocol::class)
        );
        $connection
            ->expects($this->once())
            ->method('write')
            ->with(Str::of('watev'));
        $protocol
            ->expects($this->once())
            ->method('encode')
            ->with(new ConnectionClose)
            ->willReturn(Str::of('watev'));

        $client->close();
        $this->assertNull($client->close());
        $this->assertTrue($client->closed());
    }

    public function testConsideredClientClosedWhenConnectionClosed()
    {
        $client = new Unix(
            $connection = $this->createMock(Connection::class),
            $this->createMock(Protocol::class)
        );
        $connection
            ->expects($this->once())
            ->method('closed')
            ->willReturn(true);

        $this->assertTrue($client->closed());
    }
}
