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
    Exception\FailedToConnect,
    Exception\ConnectionClosed,
    Exception\InvalidConnectionClose,
    Exception\RuntimeException,
    Exception\Timedout,
    Exception\MessageNotSent,
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
    Exception\Exception as SocketException,
};
use Innmind\Stream\{
    Watch\Select,
    Exception\Exception as StreamException,
};
use Innmind\TimeContinuum\{
    Clock,
    ElapsedPeriod,
    Earth\ElapsedPeriod as Timeout
};
use Innmind\Immutable\{
    Str,
    Maybe,
    Either,
    SideEffect,
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
            ->willReturn(new ConnectionStart);
        $protocol
            ->expects($this->once())
            ->method('encode')
            ->with(new ConnectionStartOk)
            ->willReturn(Str::of('start-ok'));
        $socket
            ->method('write')
            ->with(Str::of('start-ok'))
            ->willReturn(Either::right($socket));

        $process = new Unix(
            $sockets,
            $protocol,
            $this->createMock(Clock::class),
            $address,
            $name = new Name('foo'),
            $timeout,
        );

        $this->assertInstanceOf(Process::class, $process);
        $this->assertSame($name, $process->name());
    }

    public function testThrowWhenFailedConnectionStart()
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
            ->willReturn($this->createMock(Message::class));
        $socket
            ->method('write')
            ->with(Str::of('start-ok'))
            ->willReturn(Either::right($socket));

        $this->expectException(FailedToConnect::class);
        $this->expectExceptionMessage('foo');

        new Unix(
            $sockets,
            $protocol,
            $this->createMock(Clock::class),
            $address,
            new Name('foo'),
            $timeout,
        );
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
            ->willReturn(new ConnectionStart);
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

        $process = new Unix(
            $sockets,
            $protocol,
            $this->createMock(Clock::class),
            $address,
            $name = new Name('foo'),
            $timeout,
        );

        $this->assertNull($process->send($message));
    }

    public function testWrapStreamExceptionWhenErrorAtSent()
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
            ->willReturn(new ConnectionStart);
        $protocol
            ->expects($this->exactly(2))
            ->method('encode')
            ->withConsecutive([new ConnectionStartOk], [$message])
            ->will($this->onConsecutiveCalls(
                Str::of('start-ok'),
                $this->throwException($this->createMock(StreamException::class)),
            ));
        $socket
            ->method('write')
            ->with(Str::of('start-ok'))
            ->willReturn(Either::right($socket));

        $process = new Unix(
            $sockets,
            $protocol,
            $this->createMock(Clock::class),
            $address,
            $name = new Name('foo'),
            $timeout,
        );

        $this->expectException(RuntimeException::class);

        $process->send($message);
    }

    public function testWrapSocketExceptionWhenErrorAtSent()
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
            ->willReturn(new ConnectionStart);
        $protocol
            ->expects($this->exactly(2))
            ->method('encode')
            ->withConsecutive([new ConnectionStartOk], [$message])
            ->will($this->onConsecutiveCalls(
                Str::of('start-ok'),
                $this->throwException($this->createMock(SocketException::class)),
            ));
        $socket
            ->method('write')
            ->with(Str::of('start-ok'))
            ->willReturn(Either::right($socket));

        $process = new Unix(
            $sockets,
            $protocol,
            $this->createMock(Clock::class),
            $address,
            $name = new Name('foo'),
            $timeout,
        );

        $this->expectException(RuntimeException::class);

        $process->send($message);
    }

    public function testDoNothingWhenTryingToSendOnClosedSocket()
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
            ->willReturn(new ConnectionStart);
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

        $process = new Unix(
            $sockets,
            $protocol,
            $this->createMock(Clock::class),
            $address,
            $name = new Name('foo'),
            $timeout,
        );

        $this->assertNull($process->send($this->createMock(Message::class)));
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
            ->willReturn(new ConnectionStart);
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

        $process = new Unix(
            $sockets,
            $protocol,
            $this->createMock(Clock::class),
            $address,
            $name = new Name('foo'),
            $timeout,
        );

        try {
            $process->wait();

            $this->fail('it should throw');
        } catch (ConnectionClosed $e) {
            $this->assertTrue($process->closed());
        }
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
                new ConnectionStart,
                $message = $this->createMock(Message::class),
            ));
        $socket
            ->method('write')
            ->with(Str::of('start-ok'))
            ->willReturn(Either::right($socket));

        $process = new Unix(
            $sockets,
            $protocol,
            $this->createMock(Clock::class),
            $address,
            $name = new Name('foo'),
            $timeout,
        );

        $this->assertSame($message, $process->wait());
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
                new ConnectionStart,
                new Heartbeat,
                $message = $this->createMock(Message::class),
            ));
        $socket
            ->method('write')
            ->with(Str::of('start-ok'))
            ->willReturn(Either::right($socket));

        $process = new Unix(
            $sockets,
            $protocol,
            $this->createMock(Clock::class),
            $address,
            $name = new Name('foo'),
            $timeout,
        );

        $this->assertSame($message, $process->wait());
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
                new ConnectionStart,
                new ConnectionClose,
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

        $process = new Unix(
            $sockets,
            $protocol,
            $this->createMock(Clock::class),
            $address,
            $name = new Name('foo'),
            $timeout,
        );

        try {
            $process->wait();

            $this->fail('it should throw');
        } catch (ConnectionClosed $e) {
            $this->assertTrue($process->closed());
        }
    }

    public function testWrapStreamExceptionWhenErrorAtWait()
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
                new ConnectionStart,
                $this->throwException($this->createMock(StreamException::class)),
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

        $process = new Unix(
            $sockets,
            $protocol,
            $this->createMock(Clock::class),
            $address,
            $name = new Name('foo'),
            $timeout,
        );

        $this->expectException(RuntimeException::class);

        $process->wait();
    }

    public function testWrapSocketExceptionWhenErrorAtWait()
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
                new ConnectionStart,
                $this->throwException($this->createMock(SocketException::class)),
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

        $process = new Unix(
            $sockets,
            $protocol,
            $this->createMock(Clock::class),
            $address,
            $name = new Name('foo'),
            $timeout,
        );

        $this->expectException(RuntimeException::class);

        $process->wait();
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
                new ConnectionStart,
                new ConnectionCloseOk,
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

        $process = new Unix(
            $sockets,
            $protocol,
            $this->createMock(Clock::class),
            $address,
            $name = new Name('foo'),
            $timeout,
        );

        $this->assertNull($process->close());
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
                new ConnectionStart,
                $this->createMock(Message::class),
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

        $process = new Unix(
            $sockets,
            $protocol,
            $this->createMock(Clock::class),
            $address,
            $name = new Name('foo'),
            $timeout,
        );

        try {
            $process->close();

            $this->fail('it should throw');
        } catch (InvalidConnectionClose $e) {
            $this->assertTrue($process->closed());
        }
    }

    public function testStopWaitingAfterTimeout()
    {
        $os = Factory::build();
        @\unlink($os->status()->tmp()->toString().'/innmind/ipc/server.sock');
        $processes = $os->control()->processes();
        $server = $processes->execute(
            Command::foreground('php')
                ->withArgument('fixtures/eternal-server.php')
                ->withEnvironment('TMPDIR', $os->status()->tmp()->toString()),
        );

        \sleep(1);

        $process = new Unix(
            $os->sockets(),
            new Protocol\Binary,
            $os->clock(),
            Address::of($os->status()->tmp()->toString().'/innmind/ipc/server'),
            $name = new Name('server'),
            new Timeout(1000),
        );

        try {
            $process->wait(new Timeout(100));

            $this->fail('it should throw');
        } catch (Timedout $e) {
            $processes->kill(
                $server->pid()->match(
                    static fn($pid) => $pid,
                    static fn() => null,
                ),
                Signal::terminate,
            );
            $this->assertTrue(true);
        }
    }

    public function testThrowWhenStreamErrorWhenConnecting()
    {
        $sockets = $this->createMock(Sockets::class);
        $protocol = $this->createMock(Protocol::class);
        $address = Address::of('/tmp/foo');
        $sockets
            ->expects($this->once())
            ->method('connectTo')
            ->with($address)
            ->willReturn(Maybe::nothing());

        try {
            new Unix(
                $sockets,
                $protocol,
                $this->createMock(Clock::class),
                $address,
                new Name('foo'),
                new Timeout(1000),
            );

            $this->fail('it should throw');
        } catch (\Throwable $e) {
            $this->assertInstanceOf(FailedToConnect::class, $e);
        }
    }

    public function testThrowWhenSocketErrorWhenConnecting()
    {
        $sockets = $this->createMock(Sockets::class);
        $protocol = $this->createMock(Protocol::class);
        $address = Address::of('/tmp/foo');
        $sockets
            ->expects($this->once())
            ->method('connectTo')
            ->with($address)
            ->willReturn(Maybe::nothing());

        try {
            new Unix(
                $sockets,
                $protocol,
                $this->createMock(Clock::class),
                $address,
                new Name('foo'),
                new Timeout(1000),
            );

            $this->fail('it should throw');
        } catch (\Throwable $e) {
            $this->assertInstanceOf(FailedToConnect::class, $e);
        }
    }

    public function testThrowWhenFailedToSendMessage()
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
            ->willReturn(new ConnectionStart);
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
            ->with($this->callback(static function($message): bool {
                if ($message->toString() === 'message-to-send') {
                    throw new RuntimeException;
                }

                return true;
            }))
            ->willReturn(Either::right($socket));

        $process = new Unix(
            $sockets,
            $protocol,
            $this->createMock(Clock::class),
            $address,
            $name = new Name('foo'),
            $timeout,
        );

        $this->expectException(MessageNotSent::class);

        $process->send($message);
    }
}
