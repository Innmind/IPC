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
use Innmind\Stream\FailedToWriteToStream;
use Innmind\MediaType\MediaType;
use Innmind\Immutable\{
    Str,
    Either,
    sideEffect,
};
use PHPUnit\Framework\TestCase;

class UnixTest extends TestCase
{
    public function testInterface()
    {
        $this->assertInstanceOf(
            Client::class,
            new Unix(
                $this->createMock(Connection::class),
                $this->createMock(Protocol::class),
            ),
        );
    }

    public function testSend()
    {
        $client = new Unix(
            $connection = $this->createMock(Connection::class),
            $protocol = $this->createMock(Protocol::class),
        );
        $message = $this->createMock(Message::class);
        $connection
            ->expects($this->once())
            ->method('write')
            ->with(Str::of('watev'))
            ->willReturn(Either::right($connection));
        $protocol
            ->expects($this->once())
            ->method('encode')
            ->with($message)
            ->willReturn(Str::of('watev'));

        $this->assertSame($client, $client->send($message)->match(
            static fn($client) => $client,
            static fn() => null,
        ));
        $this->assertFalse($client->closed());
    }

    public function testClose()
    {
        $client = new Unix(
            $connection = $this->createMock(Connection::class),
            $protocol = $this->createMock(Protocol::class),
        );
        $connection
            ->expects($this->once())
            ->method('write')
            ->with(Str::of('watev'))
            ->willReturn(Either::right($connection));
        $protocol
            ->expects($this->once())
            ->method('encode')
            ->with(new ConnectionClose)
            ->willReturn(Str::of('watev'));

        $this->assertFalse($client->closed());
        $this->assertInstanceOf(SideEffect::class, $client->close()->match(
            static fn($sideEffect) => $sideEffect,
            static fn() => null,
        ));
        $this->assertTrue($client->closed());
    }

    public function testDoesntSendOnceClosed()
    {
        $client = new Unix(
            $connection = $this->createMock(Connection::class),
            $protocol = $this->createMock(Protocol::class),
        );
        $message = $this->createMock(Message::class);
        $connection
            ->expects($this->once())
            ->method('write')
            ->with(Str::of('watev'))
            ->willReturn(Either::right($connection));
        $protocol
            ->expects($this->once())
            ->method('encode')
            ->with(new ConnectionClose)
            ->willReturn(Str::of('watev'));

        $this->assertInstanceOf(SideEffect::class, $client->close()->match(
            static fn($sideEffect) => $sideEffect,
            static fn() => null,
        ));
        $this->assertNull($client->send($message)->match(
            static fn($client) => $client,
            static fn() => null,
        ));
        $this->assertTrue($client->closed());
    }

    public function testDoesntReCloseIfAlreadyClosed()
    {
        $client = new Unix(
            $connection = $this->createMock(Connection::class),
            $protocol = $this->createMock(Protocol::class),
        );
        $connection
            ->expects($this->once())
            ->method('write')
            ->with(Str::of('watev'))
            ->willReturn(Either::right($connection));
        $protocol
            ->expects($this->once())
            ->method('encode')
            ->with(new ConnectionClose)
            ->willReturn(Str::of('watev'));

        $this->assertInstanceOf(SideEffect::class, $client->close()->match(
            static fn($sideEffect) => $sideEffect,
            static fn() => null,
        ));
        $this->assertInstanceOf(SideEffect::class, $client->close()->match(
            static fn($sideEffect) => $sideEffect,
            static fn() => null,
        ));
        $this->assertTrue($client->closed());
    }

    public function testConsideredClientClosedWhenConnectionClosed()
    {
        $client = new Unix(
            $connection = $this->createMock(Connection::class),
            $this->createMock(Protocol::class),
        );
        $connection
            ->expects($this->once())
            ->method('closed')
            ->willReturn(true);

        $this->assertTrue($client->closed());
    }

    public function testReturnNothingWhenCantSendMessageDueToSocketError()
    {
        $client = new Unix(
            $connection = $this->createMock(Connection::class),
            $protocol = $this->createMock(Protocol::class),
        );
        $message = new Message\Generic(
            MediaType::of('text/plain'),
            Str::of('watev'),
        );
        $protocol
            ->expects($this->once())
            ->method('encode')
            ->with($message)
            ->willReturn(Str::of('watev'));
        $connection
            ->expects($this->once())
            ->method('write')
            ->willReturn(Either::left(new FailedToWriteToStream));

        $this->assertNull($client->send($message)->match(
            static fn($client) => $client,
            static fn() => null,
        ));
    }

    public function testReturnNothingWhenCantProperlyCloseDueToSocketError()
    {
        $client = new Unix(
            $connection = $this->createMock(Connection::class),
            $protocol = $this->createMock(Protocol::class),
        );
        $protocol
            ->expects($this->once())
            ->method('encode')
            ->willReturn(Str::of('watev'));
        $connection
            ->expects($this->once())
            ->method('write')
            ->willReturn(Either::left(new FailedToWriteToStream));

        $this->assertNull($client->close()->match(
            static fn($sideEffect) => $sideEffect,
            static fn() => null,
        ));
        $this->assertTrue($client->closed());
    }
}
