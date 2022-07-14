<?php
declare(strict_types = 1);

namespace Tests\Innmind\IPC\Client;

use Innmind\IPC\{
    Client\Unix,
    Client,
    Protocol,
    Message,
};
use Innmind\Socket\Server\Connection;
use Innmind\Stream\{
    FailedToWriteToStream,
    FailedToCloseStream,
};
use Innmind\MediaType\MediaType;
use Innmind\Immutable\{
    Str,
    Either,
    SideEffect,
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
    }

    public function testClose()
    {
        $client = new Unix(
            $connection = $this->createMock(Connection::class),
            $this->createMock(Protocol::class),
        );
        $connection
            ->expects($this->once())
            ->method('close')
            ->willReturn(Either::right($expected = new SideEffect));

        $this->assertSame($expected, $client->close()->match(
            static fn($sideEffect) => $sideEffect,
            static fn() => null,
        ));
    }

    public function testDoesntSendOnceClosed()
    {
        $client = new Unix(
            $connection = $this->createMock(Connection::class),
            $this->createMock(Protocol::class),
        );
        $connection
            ->expects($this->once())
            ->method('closed')
            ->willReturn(true);
        $connection
            ->expects($this->never())
            ->method('write');

        $this->assertNull($client->send($this->createMock(Message::class))->match(
            static fn($client) => $client,
            static fn() => null,
        ));
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
            ->expects($this->never())
            ->method('encode');
        $connection
            ->expects($this->once())
            ->method('close')
            ->willReturn(Either::left(new FailedToCloseStream));

        $this->assertNull($client->close()->match(
            static fn($sideEffect) => $sideEffect,
            static fn() => null,
        ));
    }
}
