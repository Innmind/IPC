<?php
declare(strict_types = 1);

namespace Tests\Innmind\IPC\Process;

use Innmind\IPC\{
    Process\Unix,
    Process\Name,
    Process,
    Protocol,
    Message,
    Message\ConnectionStart,
    Message\ConnectionStartOk,
    Message\ConnectionClose,
    Message\ConnectionCloseOk,
    Message\Heartbeat,
    Message\MessageReceived,
};
use Innmind\OperatingSystem\{
    Factory,
    Sockets,
};
use Innmind\Server\Control\Server\{
    Command,
    Signal,
};
use Innmind\Socket\{
    Address\Unix as Address,
    Client,
};
use Innmind\Stream\{
    Watch\Select,
    FailedToWriteToStream,
};
use Innmind\TimeContinuum\{
    Clock,
    Earth\ElapsedPeriod as Timeout,
};
use Innmind\Immutable\{
    Str,
    Maybe,
    Either,
    SideEffect,
    Sequence,
};
use PHPUnit\Framework\TestCase;

class UnixTest extends TestCase
{
    public function testInterface()
    {
        $timeout = new Timeout(1000);
        $sockets = $this->createMock(Sockets::class);
        $protocol = $this->createMock(Protocol::class);
        $address = Address::of('/tmp/foo');
        $sockets
            ->expects($this->once())
            ->method('connectTo')
            ->with($address)
            ->willReturn(Maybe::just($socket = $this->createMock(Client::class)));
        $sockets
            ->expects($this->once())
            ->method('watch')
            ->with($timeout)
            ->willReturn(Select::timeoutAfter($timeout));
        $resource = \tmpfile();
        $socket
            ->expects($this->any())
            ->method('resource')
            ->willReturn($resource);
        $protocol
            ->expects($this->once())
            ->method('decode')
            ->with($socket)
            ->willReturn(Maybe::just(new ConnectionStart));
        $protocol
            ->expects($this->once())
            ->method('encode')
            ->with(new ConnectionStartOk)
            ->willReturn(Str::of('start-ok'));
        $socket
            ->method('write')
            ->with(Str::of('start-ok'))
            ->willReturn(Either::right($socket));

        $process = Unix::of(
            $sockets,
            $protocol,
            $this->createMock(Clock::class),
            $address,
            $name = Name::of('foo'),
            $timeout,
        )->match(
            static fn($process) => $process,
            static fn() => null,
        );

        $this->assertInstanceOf(Process::class, $process);
        $this->assertSame($name, $process->name());
    }

    public function testReturnNothingWhenFailedConnectionStart()
    {
        $timeout = new Timeout(1000);
        $sockets = $this->createMock(Sockets::class);
        $protocol = $this->createMock(Protocol::class);
        $address = Address::of('/tmp/foo');
        $sockets
            ->expects($this->once())
            ->method('connectTo')
            ->with($address)
            ->willReturn(Maybe::just($socket = $this->createMock(Client::class)));
        $sockets
            ->expects($this->once())
            ->method('watch')
            ->with($timeout)
            ->willReturn(Select::timeoutAfter($timeout));
        $resource = \tmpfile();
        $socket
            ->expects($this->any())
            ->method('resource')
            ->willReturn($resource);
        $protocol
            ->expects($this->once())
            ->method('decode')
            ->with($socket)
            ->willReturn(Maybe::just($this->createMock(Message::class)));
        $socket
            ->method('write')
            ->with(Str::of('start-ok'))
            ->willReturn(Either::right($socket));

        $process = Unix::of(
            $sockets,
            $protocol,
            $this->createMock(Clock::class),
            $address,
            Name::of('foo'),
            $timeout,
        )->match(
            static fn($process) => $process,
            static fn() => null,
        );

        $this->assertNull($process);
    }

    public function testSend()
    {
        $message = $this->createMock(Message::class);

        $timeout = new Timeout(1000);
        $sockets = $this->createMock(Sockets::class);
        $protocol = $this->createMock(Protocol::class);
        $address = Address::of('/tmp/foo');
        $sockets
            ->expects($this->once())
            ->method('connectTo')
            ->with($address)
            ->willReturn(Maybe::just($socket = $this->createMock(Client::class)));
        $sockets
            ->expects($this->once())
            ->method('watch')
            ->with($timeout)
            ->willReturn(Select::timeoutAfter($timeout));
        $resource = \tmpfile();
        $socket
            ->expects($this->any())
            ->method('resource')
            ->willReturn($resource);
        $protocol
            ->expects($this->atLeast(1))
            ->method('decode')
            ->with($socket)
            ->will($this->onConsecutiveCalls(
                Maybe::just(new ConnectionStart),
                Maybe::just(new MessageReceived),
            ));
        $protocol
            ->expects($this->exactly(2))
            ->method('encode')
            ->withConsecutive([new ConnectionStartOk], [$message])
            ->will($this->onConsecutiveCalls(
                Str::of('start-ok'),
                Str::of('message-to-send'),
            ));
        $socket
            ->method('write')
            ->withConsecutive(
                [Str::of('start-ok')],
                [Str::of('message-to-send')],
            )
            ->willReturn(Either::right($socket));

        $process = Unix::of(
            $sockets,
            $protocol,
            $this->createMock(Clock::class),
            $address,
            $name = Name::of('foo'),
            $timeout,
        )->match(
            static fn($process) => $process,
            static fn() => null,
        );

        $this->assertSame($process, $process->send(Sequence::of($message))->match(
            static fn($process) => $process,
            static fn() => null,
        ));
    }

    public function testReturnNothingWhenErrorAtSent()
    {
        $message = $this->createMock(Message::class);

        $timeout = new Timeout(1000);
        $sockets = $this->createMock(Sockets::class);
        $protocol = $this->createMock(Protocol::class);
        $address = Address::of('/tmp/foo');
        $sockets
            ->expects($this->once())
            ->method('connectTo')
            ->with($address)
            ->willReturn(Maybe::just($socket = $this->createMock(Client::class)));
        $sockets
            ->expects($this->once())
            ->method('watch')
            ->with($timeout)
            ->willReturn(Select::timeoutAfter($timeout));
        $resource = \tmpfile();
        $socket
            ->expects($this->any())
            ->method('resource')
            ->willReturn($resource);
        $protocol
            ->expects($this->once())
            ->method('decode')
            ->with($socket)
            ->willReturn(Maybe::just(new ConnectionStart));
        $protocol
            ->expects($this->exactly(2))
            ->method('encode')
            ->withConsecutive([new ConnectionStartOk], [$message])
            ->will($this->onConsecutiveCalls(
                Str::of('start-ok'),
                Str::of('message content'),
            ));
        $socket
            ->method('write')
            ->withConsecutive(
                [Str::of('start-ok')],
                [Str::of('message content')],
            )
            ->will($this->onConsecutiveCalls(
                Either::right($socket),
                Either::left(new FailedToWriteToStream),
            ));

        $process = Unix::of(
            $sockets,
            $protocol,
            $this->createMock(Clock::class),
            $address,
            $name = Name::of('foo'),
            $timeout,
        )->match(
            static fn($process) => $process,
            static fn() => null,
        );

        $this->assertNull($process->send(Sequence::of($message))->match(
            static fn($process) => $process,
            static fn() => null,
        ));
    }

    public function testReturnNothingWhenTryingToSendOnClosedSocket()
    {
        $timeout = new Timeout(1000);
        $sockets = $this->createMock(Sockets::class);
        $protocol = $this->createMock(Protocol::class);
        $address = Address::of('/tmp/foo');
        $sockets
            ->expects($this->once())
            ->method('connectTo')
            ->with($address)
            ->willReturn(Maybe::just($socket = $this->createMock(Client::class)));
        $sockets
            ->expects($this->once())
            ->method('watch')
            ->with($timeout)
            ->willReturn(Select::timeoutAfter($timeout));
        $resource = \tmpfile();
        $socket
            ->expects($this->any())
            ->method('resource')
            ->willReturn($resource);
        $protocol
            ->expects($this->once())
            ->method('decode')
            ->with($socket)
            ->willReturn(Maybe::just(new ConnectionStart));
        $protocol
            ->expects($this->once())
            ->method('encode')
            ->with(new ConnectionStartOk)
            ->willReturn(Str::of('start-ok'));
        $socket
            ->expects($this->once())
            ->method('write')
            ->with(Str::of('start-ok'))
            ->willReturn(Either::right($socket));
        $socket
            ->expects($this->exactly(3))
            ->method('closed')
            ->will($this->onConsecutiveCalls(
                false,
                false,
                true,
            ));

        $process = Unix::of(
            $sockets,
            $protocol,
            $this->createMock(Clock::class),
            $address,
            $name = Name::of('foo'),
            $timeout,
        )->match(
            static fn($process) => $process,
            static fn() => null,
        );

        $this->assertNull($process->send(Sequence::of($this->createMock(Message::class)))->match(
            static fn($process) => $process,
            static fn() => null,
        ));
    }

    public function testThrowWhenWaitingOnClosedSocket()
    {
        $timeout = new Timeout(1000);
        $sockets = $this->createMock(Sockets::class);
        $protocol = $this->createMock(Protocol::class);
        $address = Address::of('/tmp/foo');
        $sockets
            ->expects($this->once())
            ->method('connectTo')
            ->with($address)
            ->willReturn(Maybe::just($socket = $this->createMock(Client::class)));
        $sockets
            ->expects($this->once())
            ->method('watch')
            ->with($timeout)
            ->willReturn(Select::timeoutAfter($timeout));
        $resource = \tmpfile();
        $socket
            ->expects($this->any())
            ->method('resource')
            ->willReturn($resource);
        $protocol
            ->expects($this->once())
            ->method('decode')
            ->with($socket)
            ->willReturn(Maybe::just(new ConnectionStart));
        $protocol
            ->expects($this->once())
            ->method('encode')
            ->with(new ConnectionStartOk)
            ->willReturn(Str::of('start-ok'));
        $socket
            ->method('write')
            ->with(Str::of('start-ok'))
            ->willReturn(Either::right($socket));
        $socket
            ->method('closed')
            ->will($this->onConsecutiveCalls(
                false,
                false,
                true,
            ));
        $socket
            ->method('close')
            ->willReturn(Either::right(new SideEffect));

        $process = Unix::of(
            $sockets,
            $protocol,
            $this->createMock(Clock::class),
            $address,
            $name = Name::of('foo'),
            $timeout,
        )->match(
            static fn($process) => $process,
            static fn() => null,
        );

        $this->assertNull($process->wait()->match(
            static fn($message) => $message,
            static fn() => null,
        ));
        $this->assertTrue($process->closed());
    }

    public function testWait()
    {
        $timeout = new Timeout(1000);
        $sockets = $this->createMock(Sockets::class);
        $protocol = $this->createMock(Protocol::class);
        $address = Address::of('/tmp/foo');
        $sockets
            ->expects($this->once())
            ->method('connectTo')
            ->with($address)
            ->willReturn(Maybe::just($socket = $this->createMock(Client::class)));
        $sockets
            ->expects($this->once())
            ->method('watch')
            ->with($timeout)
            ->willReturn(Select::timeoutAfter($timeout));
        $resource = \tmpfile();
        $socket
            ->expects($this->any())
            ->method('resource')
            ->willReturn($resource);
        $protocol
            ->expects($this->once())
            ->method('encode')
            ->with(new ConnectionStartOk)
            ->willReturn(Str::of('start-ok'));
        $protocol
            ->expects($this->exactly(2))
            ->method('decode')
            ->with($socket)
            ->will($this->onConsecutiveCalls(
                Maybe::just(new ConnectionStart),
                Maybe::just($message = $this->createMock(Message::class)),
            ));
        $socket
            ->method('write')
            ->with(Str::of('start-ok'))
            ->willReturn(Either::right($socket));

        $process = Unix::of(
            $sockets,
            $protocol,
            $this->createMock(Clock::class),
            $address,
            $name = Name::of('foo'),
            $timeout,
        )->match(
            static fn($process) => $process,
            static fn() => null,
        );

        $this->assertSame($message, $process->wait()->match(
            static fn($message) => $message,
            static fn() => null,
        ));
    }

    public function testDiscardHeartbeatWhenWaiting()
    {
        $timeout = new Timeout(1000);
        $sockets = $this->createMock(Sockets::class);
        $protocol = $this->createMock(Protocol::class);
        $address = Address::of('/tmp/foo');
        $sockets
            ->expects($this->once())
            ->method('connectTo')
            ->with($address)
            ->willReturn(Maybe::just($socket = $this->createMock(Client::class)));
        $sockets
            ->expects($this->once())
            ->method('watch')
            ->with($timeout)
            ->willReturn(Select::timeoutAfter($timeout));
        $resource = \tmpfile();
        $socket
            ->expects($this->any())
            ->method('resource')
            ->willReturn($resource);
        $protocol
            ->expects($this->once())
            ->method('encode')
            ->with(new ConnectionStartOk)
            ->willReturn(Str::of('start-ok'));
        $protocol
            ->expects($this->exactly(3))
            ->method('decode')
            ->with($socket)
            ->will($this->onConsecutiveCalls(
                Maybe::just(new ConnectionStart),
                Maybe::just(new Heartbeat),
                Maybe::just($message = $this->createMock(Message::class)),
            ));
        $socket
            ->method('write')
            ->with(Str::of('start-ok'))
            ->willReturn(Either::right($socket));

        $process = Unix::of(
            $sockets,
            $protocol,
            $this->createMock(Clock::class),
            $address,
            $name = Name::of('foo'),
            $timeout,
        )->match(
            static fn($process) => $process,
            static fn() => null,
        );

        $this->assertSame($message, $process->wait()->match(
            static fn($message) => $message,
            static fn() => null,
        ));
    }

    public function testConfirmCloseWhenWaiting()
    {
        $timeout = new Timeout(1000);
        $sockets = $this->createMock(Sockets::class);
        $protocol = $this->createMock(Protocol::class);
        $address = Address::of('/tmp/foo');
        $sockets
            ->expects($this->once())
            ->method('connectTo')
            ->with($address)
            ->willReturn(Maybe::just($socket = $this->createMock(Client::class)));
        $sockets
            ->expects($this->once())
            ->method('watch')
            ->with($timeout)
            ->willReturn(Select::timeoutAfter($timeout));
        $resource = \tmpfile();
        $socket
            ->expects($this->any())
            ->method('resource')
            ->willReturn($resource);
        $protocol
            ->expects($this->exactly(2))
            ->method('decode')
            ->with($socket)
            ->will($this->onConsecutiveCalls(
                Maybe::just(new ConnectionStart),
                Maybe::just(new ConnectionClose),
            ));
        $protocol
            ->expects($this->exactly(2))
            ->method('encode')
            ->withConsecutive(
                [new ConnectionStartOk],
                [new ConnectionCloseOk],
            )
            ->will($this->onConsecutiveCalls(
                Str::of('start-ok'),
                Str::of('close-ok'),
            ));
        $socket
            ->method('write')
            ->withConsecutive(
                [Str::of('start-ok')],
                [Str::of('close-ok')],
            )
            ->willReturn(Either::right($socket));
        $socket
            ->method('close')
            ->willReturn(Either::right(new SideEffect));

        $process = Unix::of(
            $sockets,
            $protocol,
            $this->createMock(Clock::class),
            $address,
            $name = Name::of('foo'),
            $timeout,
        )->match(
            static fn($process) => $process,
            static fn() => null,
        );

        $this->assertNull($process->wait()->match(
            static fn($message) => $message,
            static fn() => null,
        ));
        $this->assertTrue($process->closed());
    }

    public function testReturnNothingWhenNoMessageDecoded()
    {
        $timeout = new Timeout(1000);
        $sockets = $this->createMock(Sockets::class);
        $protocol = $this->createMock(Protocol::class);
        $address = Address::of('/tmp/foo');
        $sockets
            ->expects($this->once())
            ->method('connectTo')
            ->with($address)
            ->willReturn(Maybe::just($socket = $this->createMock(Client::class)));
        $sockets
            ->expects($this->once())
            ->method('watch')
            ->with($timeout)
            ->willReturn(Select::timeoutAfter($timeout));
        $resource = \tmpfile();
        $socket
            ->expects($this->any())
            ->method('resource')
            ->willReturn($resource);
        $protocol
            ->expects($this->exactly(2))
            ->method('decode')
            ->with($socket)
            ->will($this->onConsecutiveCalls(
                Maybe::just(new ConnectionStart),
                Maybe::nothing(),
            ));
        $protocol
            ->expects($this->once())
            ->method('encode')
            ->with(new ConnectionStartOk)
            ->willReturn(Str::of('start-ok'));
        $socket
            ->method('write')
            ->with(Str::of('start-ok'))
            ->willReturn(Either::right($socket));

        $process = Unix::of(
            $sockets,
            $protocol,
            $this->createMock(Clock::class),
            $address,
            $name = Name::of('foo'),
            $timeout,
        )->match(
            static fn($process) => $process,
            static fn() => null,
        );

        $this->assertNull($process->wait()->match(
            static fn($message) => $message,
            static fn() => null,
        ));
    }

    public function testClose()
    {
        $timeout = new Timeout(1000);
        $sockets = $this->createMock(Sockets::class);
        $protocol = $this->createMock(Protocol::class);
        $address = Address::of('/tmp/foo');
        $sockets
            ->expects($this->once())
            ->method('connectTo')
            ->with($address)
            ->willReturn(Maybe::just($socket = $this->createMock(Client::class)));
        $sockets
            ->expects($this->once())
            ->method('watch')
            ->with($timeout)
            ->willReturn(Select::timeoutAfter($timeout));
        $resource = \tmpfile();
        $socket
            ->expects($this->any())
            ->method('resource')
            ->willReturn($resource);
        $protocol
            ->expects($this->exactly(2))
            ->method('decode')
            ->with($socket)
            ->will($this->onConsecutiveCalls(
                Maybe::just(new ConnectionStart),
                Maybe::just(new ConnectionCloseOk),
            ));
        $protocol
            ->expects($this->exactly(2))
            ->method('encode')
            ->withConsecutive([new ConnectionStartOk], [new ConnectionClose])
            ->will($this->onConsecutiveCalls(
                Str::of('start-ok'),
                Str::of('close'),
            ));
        $socket
            ->method('write')
            ->withConsecutive([Str::of('start-ok')], [Str::of('close')])
            ->willReturn(Either::right($socket));
        $socket
            ->method('close')
            ->willReturn(Either::right(new SideEffect));

        $process = Unix::of(
            $sockets,
            $protocol,
            $this->createMock(Clock::class),
            $address,
            $name = Name::of('foo'),
            $timeout,
        )->match(
            static fn($process) => $process,
            static fn() => null,
        );

        $this->assertInstanceOf(SideEffect::class, $process->close()->match(
            static fn($sideEffect) => $sideEffect,
            static fn() => null,
        ));
        $this->assertTrue($process->closed());
    }

    public function testThrowWhenInvalidCloseConfirmation()
    {
        $timeout = new Timeout(1000);
        $sockets = $this->createMock(Sockets::class);
        $protocol = $this->createMock(Protocol::class);
        $address = Address::of('/tmp/foo');
        $sockets
            ->expects($this->once())
            ->method('connectTo')
            ->with($address)
            ->willReturn(Maybe::just($socket = $this->createMock(Client::class)));
        $sockets
            ->expects($this->once())
            ->method('watch')
            ->with($timeout)
            ->willReturn(Select::timeoutAfter($timeout));
        $resource = \tmpfile();
        $socket
            ->expects($this->any())
            ->method('resource')
            ->willReturn($resource);
        $protocol
            ->expects($this->exactly(2))
            ->method('decode')
            ->with($socket)
            ->will($this->onConsecutiveCalls(
                Maybe::just(new ConnectionStart),
                Maybe::just($this->createMock(Message::class)),
            ));
        $protocol
            ->expects($this->exactly(2))
            ->method('encode')
            ->withConsecutive([new ConnectionStartOk], [new ConnectionClose])
            ->will($this->onConsecutiveCalls(
                Str::of('start-ok'),
                Str::of('close'),
            ));
        $socket
            ->method('write')
            ->withConsecutive([Str::of('start-ok')], [Str::of('close')])
            ->willReturn(Either::right($socket));
        $socket
            ->method('close')
            ->willReturn(Either::right(new SideEffect));

        $process = Unix::of(
            $sockets,
            $protocol,
            $this->createMock(Clock::class),
            $address,
            $name = Name::of('foo'),
            $timeout,
        )->match(
            static fn($process) => $process,
            static fn() => null,
        );

        $this->assertNull($process->close()->match(
            static fn($sideEffect) => $sideEffect,
            static fn() => null,
        ));
        $this->assertTrue($process->closed());
    }

    public function testStopWaitingAfterTimeout()
    {
        $os = Factory::build();
        @\unlink($os->status()->tmp()->toString().'/innmind/ipc/server.sock');
        $processes = $os->control()->processes();
        $server = $processes->execute(
            Command::foreground('php')
                ->withArgument('fixtures/eternal-server.php')
                ->withEnvironment('TMPDIR', $os->status()->tmp()->toString())
                ->withEnvironment('PATH', $_SERVER['PATH']),
        );

        \sleep(1);

        $process = Unix::of(
            $os->sockets(),
            new Protocol\Binary,
            $os->clock(),
            Address::of($os->status()->tmp()->toString().'/innmind/ipc/server'),
            $name = Name::of('server'),
            new Timeout(1000),
        )->match(
            static fn($process) => $process,
            static fn() => null,
        );

        try {
            $this->assertNull($process->wait(new Timeout(100))->match(
                static fn($message) => $message,
                static fn() => null,
            ));
        } finally {
            $processes->kill(
                $server->pid()->match(
                    static fn($pid) => $pid,
                    static fn() => null,
                ),
                Signal::terminate,
            );
        }
    }

    public function testReturnNothingWhenSocketErrorWhenConnecting()
    {
        $sockets = $this->createMock(Sockets::class);
        $protocol = $this->createMock(Protocol::class);
        $address = Address::of('/tmp/foo');
        $sockets
            ->expects($this->once())
            ->method('connectTo')
            ->with($address)
            ->willReturn(Maybe::nothing());

        $process = Unix::of(
            $sockets,
            $protocol,
            $this->createMock(Clock::class),
            $address,
            Name::of('foo'),
            new Timeout(1000),
        )->match(
            static fn($process) => $process,
            static fn() => null,
        );

        $this->assertNull($process);
    }
}
