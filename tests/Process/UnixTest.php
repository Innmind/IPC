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
    Exception\FailedToConnect,
    Exception\ConnectionClosed,
    Exception\InvalidConnectionClose,
    Exception\RuntimeException,
    Exception\Timedout,
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
use Innmind\Stream\Exception\Exception as StreamException;
use Innmind\TimeContinuum\{
    TimeContinuumInterface,
    ElapsedPeriodInterface,
    ElapsedPeriod,
    PointInTimeInterface,
};
use Innmind\Immutable\Str;
use PHPUnit\Framework\TestCase;

class UnixTest extends TestCase
{
    public function testInterface()
    {
        $sockets = $this->createMock(Sockets::class);
        $protocol = $this->createMock(Protocol::class);
        $address = new Address('/tmp/foo');
        $sockets
            ->expects($this->once())
            ->method('connectTo')
            ->with($address)
            ->willReturn($socket = $this->createMock(Client::class));
        $resource = \tmpfile();
        $socket
            ->expects($this->any())
            ->method('resource')
            ->willReturn($resource);
        $protocol
            ->expects($this->at(0))
            ->method('decode')
            ->with($socket)
            ->willReturn(new ConnectionStart);
        $protocol
            ->expects($this->at(1))
            ->method('encode')
            ->with(new ConnectionStartOk)
            ->willReturn(Str::of('start-ok'));
        $socket
            ->method('write')
            ->with(Str::of('start-ok'));

        $process = new Unix(
            $sockets,
            $protocol,
            $this->createMock(TimeContinuumInterface::class),
            $address,
            $name = new Name('foo'),
            new ElapsedPeriod(1000)
        );

        $this->assertInstanceOf(Process::class, $process);
        $this->assertSame($name, $process->name());
    }

    public function testThrowWhenFailedConnectionStart()
    {
        $sockets = $this->createMock(Sockets::class);
        $protocol = $this->createMock(Protocol::class);
        $address = new Address('/tmp/foo');
        $sockets
            ->expects($this->once())
            ->method('connectTo')
            ->with($address)
            ->willReturn($socket = $this->createMock(Client::class));
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
            ->with(Str::of('start-ok'));

        $this->expectException(FailedToConnect::class);
        $this->expectExceptionMessage('foo');

        new Unix(
            $sockets,
            $protocol,
            $this->createMock(TimeContinuumInterface::class),
            $address,
            new Name('foo'),
            new ElapsedPeriod(1000)
        );
    }

    public function testSend()
    {
        $message = $this->createMock(Message::class);

        $sockets = $this->createMock(Sockets::class);
        $protocol = $this->createMock(Protocol::class);
        $address = new Address('/tmp/foo');
        $sockets
            ->expects($this->once())
            ->method('connectTo')
            ->with($address)
            ->willReturn($socket = $this->createMock(Client::class));
        $resource = \tmpfile();
        $socket
            ->expects($this->any())
            ->method('resource')
            ->willReturn($resource);
        $protocol
            ->expects($this->at(0))
            ->method('decode')
            ->with($socket)
            ->willReturn(new ConnectionStart);
        $protocol
            ->expects($this->at(1))
            ->method('encode')
            ->with(new ConnectionStartOk)
            ->willReturn(Str::of('start-ok'));
        $protocol
            ->expects($this->at(2))
            ->method('encode')
            ->with($message)
            ->willReturn(Str::of('message-to-send'));
        $socket
            ->method('write')
            ->with($this->logicalOr(
                $this->equalTo(Str::of('start-ok')),
                $this->equalTo(Str::of('message-to-send'))
            ));

        $process = new Unix(
            $sockets,
            $protocol,
            $this->createMock(TimeContinuumInterface::class),
            $address,
            $name = new Name('foo'),
            new ElapsedPeriod(1000)
        );

        $this->assertNull($process->send($message));
    }

    public function testWrapStreamExceptionWhenErrorAtSent()
    {
        $message = $this->createMock(Message::class);

        $sockets = $this->createMock(Sockets::class);
        $protocol = $this->createMock(Protocol::class);
        $address = new Address('/tmp/foo');
        $sockets
            ->expects($this->once())
            ->method('connectTo')
            ->with($address)
            ->willReturn($socket = $this->createMock(Client::class));
        $resource = \tmpfile();
        $socket
            ->expects($this->any())
            ->method('resource')
            ->willReturn($resource);
        $protocol
            ->expects($this->at(0))
            ->method('decode')
            ->with($socket)
            ->willReturn(new ConnectionStart);
        $protocol
            ->expects($this->at(1))
            ->method('encode')
            ->with(new ConnectionStartOk)
            ->willReturn(Str::of('start-ok'));
        $protocol
            ->expects($this->at(2))
            ->method('encode')
            ->with($message)
            ->will($this->throwException($this->createMock(StreamException::class)));
        $socket
            ->method('write')
            ->with(Str::of('start-ok'));

        $process = new Unix(
            $sockets,
            $protocol,
            $this->createMock(TimeContinuumInterface::class),
            $address,
            $name = new Name('foo'),
            new ElapsedPeriod(1000)
        );

        $this->expectException(RuntimeException::class);

        $process->send($message);
    }

    public function testWrapSocketExceptionWhenErrorAtSent()
    {
        $message = $this->createMock(Message::class);

        $sockets = $this->createMock(Sockets::class);
        $protocol = $this->createMock(Protocol::class);
        $address = new Address('/tmp/foo');
        $sockets
            ->expects($this->once())
            ->method('connectTo')
            ->with($address)
            ->willReturn($socket = $this->createMock(Client::class));
        $resource = \tmpfile();
        $socket
            ->expects($this->any())
            ->method('resource')
            ->willReturn($resource);
        $protocol
            ->expects($this->at(0))
            ->method('decode')
            ->with($socket)
            ->willReturn(new ConnectionStart);
        $protocol
            ->expects($this->at(1))
            ->method('encode')
            ->with(new ConnectionStartOk)
            ->willReturn(Str::of('start-ok'));
        $protocol
            ->expects($this->at(2))
            ->method('encode')
            ->with($message)
            ->will($this->throwException($this->createMock(SocketException::class)));
        $socket
            ->method('write')
            ->with(Str::of('start-ok'));

        $process = new Unix(
            $sockets,
            $protocol,
            $this->createMock(TimeContinuumInterface::class),
            $address,
            $name = new Name('foo'),
            new ElapsedPeriod(1000)
        );

        $this->expectException(RuntimeException::class);

        $process->send($message);
    }

    public function testDoNothingWhenTryingToSendOnClosedSocket()
    {
        $sockets = $this->createMock(Sockets::class);
        $protocol = $this->createMock(Protocol::class);
        $address = new Address('/tmp/foo');
        $sockets
            ->expects($this->once())
            ->method('connectTo')
            ->with($address)
            ->willReturn($socket = $this->createMock(Client::class));
        $resource = \tmpfile();
        $socket
            ->expects($this->any())
            ->method('resource')
            ->willReturn($resource);
        $protocol
            ->expects($this->at(0))
            ->method('decode')
            ->with($socket)
            ->willReturn(new ConnectionStart);
        $protocol
            ->expects($this->at(1))
            ->method('encode')
            ->with(new ConnectionStartOk)
            ->willReturn(Str::of('start-ok'));
        $socket
            ->method('write')
            ->with(Str::of('start-ok'));
        $socket
            ->method('closed')
            ->will($this->onConsecutiveCalls(
                false,
                false,
                true
            ));

        $process = new Unix(
            $sockets,
            $protocol,
            $this->createMock(TimeContinuumInterface::class),
            $address,
            $name = new Name('foo'),
            new ElapsedPeriod(1000)
        );

        $this->assertNull($process->send($this->createMock(Message::class)));
    }

    public function testThrowWhenWaitingOnClosedSocket()
    {
        $sockets = $this->createMock(Sockets::class);
        $protocol = $this->createMock(Protocol::class);
        $address = new Address('/tmp/foo');
        $sockets
            ->expects($this->once())
            ->method('connectTo')
            ->with($address)
            ->willReturn($socket = $this->createMock(Client::class));
        $resource = \tmpfile();
        $socket
            ->expects($this->any())
            ->method('resource')
            ->willReturn($resource);
        $protocol
            ->expects($this->at(0))
            ->method('decode')
            ->with($socket)
            ->willReturn(new ConnectionStart);
        $protocol
            ->expects($this->at(1))
            ->method('encode')
            ->with(new ConnectionStartOk)
            ->willReturn(Str::of('start-ok'));
        $socket
            ->method('write')
            ->with(Str::of('start-ok'));
        $socket
            ->method('closed')
            ->will($this->onConsecutiveCalls(
                false,
                false,
                true
            ));

        $process = new Unix(
            $sockets,
            $protocol,
            $this->createMock(TimeContinuumInterface::class),
            $address,
            $name = new Name('foo'),
            new ElapsedPeriod(1000)
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
        $sockets = $this->createMock(Sockets::class);
        $protocol = $this->createMock(Protocol::class);
        $address = new Address('/tmp/foo');
        $sockets
            ->expects($this->once())
            ->method('connectTo')
            ->with($address)
            ->willReturn($socket = $this->createMock(Client::class));
        $resource = \tmpfile();
        $socket
            ->expects($this->any())
            ->method('resource')
            ->willReturn($resource);
        $protocol
            ->expects($this->at(0))
            ->method('decode')
            ->with($socket)
            ->willReturn(new ConnectionStart);
        $protocol
            ->expects($this->at(1))
            ->method('encode')
            ->with(new ConnectionStartOk)
            ->willReturn(Str::of('start-ok'));
        $protocol
            ->expects($this->at(2))
            ->method('decode')
            ->with($socket)
            ->willReturn($message = $this->createMock(Message::class));
        $socket
            ->method('write')
            ->with(Str::of('start-ok'));

        $process = new Unix(
            $sockets,
            $protocol,
            $this->createMock(TimeContinuumInterface::class),
            $address,
            $name = new Name('foo'),
            new ElapsedPeriod(1000)
        );

        $this->assertSame($message, $process->wait());
    }

    public function testWrapStreamExceptionWhenErrorAtWait()
    {
        $sockets = $this->createMock(Sockets::class);
        $protocol = $this->createMock(Protocol::class);
        $address = new Address('/tmp/foo');
        $sockets
            ->expects($this->once())
            ->method('connectTo')
            ->with($address)
            ->willReturn($socket = $this->createMock(Client::class));
        $resource = \tmpfile();
        $socket
            ->expects($this->any())
            ->method('resource')
            ->willReturn($resource);
        $protocol
            ->expects($this->at(0))
            ->method('decode')
            ->with($socket)
            ->willReturn(new ConnectionStart);
        $protocol
            ->expects($this->at(1))
            ->method('encode')
            ->with(new ConnectionStartOk)
            ->willReturn(Str::of('start-ok'));
        $protocol
            ->expects($this->at(2))
            ->method('decode')
            ->with($socket)
            ->will($this->throwException($this->createMock(StreamException::class)));
        $socket
            ->method('write')
            ->with(Str::of('start-ok'));

        $process = new Unix(
            $sockets,
            $protocol,
            $this->createMock(TimeContinuumInterface::class),
            $address,
            $name = new Name('foo'),
            new ElapsedPeriod(1000)
        );

        $this->expectException(RuntimeException::class);

        $process->wait();
    }

    public function testWrapSocketExceptionWhenErrorAtWait()
    {
        $sockets = $this->createMock(Sockets::class);
        $protocol = $this->createMock(Protocol::class);
        $address = new Address('/tmp/foo');
        $sockets
            ->expects($this->once())
            ->method('connectTo')
            ->with($address)
            ->willReturn($socket = $this->createMock(Client::class));
        $resource = \tmpfile();
        $socket
            ->expects($this->any())
            ->method('resource')
            ->willReturn($resource);
        $protocol
            ->expects($this->at(0))
            ->method('decode')
            ->with($socket)
            ->willReturn(new ConnectionStart);
        $protocol
            ->expects($this->at(1))
            ->method('encode')
            ->with(new ConnectionStartOk)
            ->willReturn(Str::of('start-ok'));
        $protocol
            ->expects($this->at(2))
            ->method('decode')
            ->with($socket)
            ->will($this->throwException($this->createMock(SocketException::class)));
        $socket
            ->method('write')
            ->with(Str::of('start-ok'));

        $process = new Unix(
            $sockets,
            $protocol,
            $this->createMock(TimeContinuumInterface::class),
            $address,
            $name = new Name('foo'),
            new ElapsedPeriod(1000)
        );

        $this->expectException(RuntimeException::class);

        $process->wait();
    }

    public function testClose()
    {
        $sockets = $this->createMock(Sockets::class);
        $protocol = $this->createMock(Protocol::class);
        $address = new Address('/tmp/foo');
        $sockets
            ->expects($this->once())
            ->method('connectTo')
            ->with($address)
            ->willReturn($socket = $this->createMock(Client::class));
        $resource = \tmpfile();
        $socket
            ->expects($this->any())
            ->method('resource')
            ->willReturn($resource);
        $protocol
            ->expects($this->at(0))
            ->method('decode')
            ->with($socket)
            ->willReturn(new ConnectionStart);
        $protocol
            ->expects($this->at(1))
            ->method('encode')
            ->with(new ConnectionStartOk)
            ->willReturn(Str::of('start-ok'));
        $protocol
            ->expects($this->at(2))
            ->method('encode')
            ->with(new ConnectionClose)
            ->willReturn(Str::of('start-ok'));
        $protocol
            ->expects($this->at(3))
            ->method('decode')
            ->with($socket)
            ->willReturn(new ConnectionCloseOk);
        $socket
            ->method('write')
            ->with(Str::of('start-ok'));

        $process = new Unix(
            $sockets,
            $protocol,
            $this->createMock(TimeContinuumInterface::class),
            $address,
            $name = new Name('foo'),
            new ElapsedPeriod(1000)
        );

        $this->assertNull($process->close());
        $this->assertTrue($process->closed());
    }

    public function testThrowWhenInvalidCloseConfirmation()
    {
        $sockets = $this->createMock(Sockets::class);
        $protocol = $this->createMock(Protocol::class);
        $address = new Address('/tmp/foo');
        $sockets
            ->expects($this->once())
            ->method('connectTo')
            ->with($address)
            ->willReturn($socket = $this->createMock(Client::class));
        $resource = \tmpfile();
        $socket
            ->expects($this->any())
            ->method('resource')
            ->willReturn($resource);
        $protocol
            ->expects($this->at(0))
            ->method('decode')
            ->with($socket)
            ->willReturn(new ConnectionStart);
        $protocol
            ->expects($this->at(1))
            ->method('encode')
            ->with(new ConnectionStartOk)
            ->willReturn(Str::of('start-ok'));
        $protocol
            ->expects($this->at(2))
            ->method('encode')
            ->with(new ConnectionClose)
            ->willReturn(Str::of('start-ok'));
        $protocol
            ->expects($this->at(3))
            ->method('decode')
            ->with($socket)
            ->willReturn($this->createMock(Message::class));
        $socket
            ->method('write')
            ->with(Str::of('start-ok'));

        $process = new Unix(
            $sockets,
            $protocol,
            $this->createMock(TimeContinuumInterface::class),
            $address,
            $name = new Name('foo'),
            new ElapsedPeriod(1000)
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
        $processes = $os->control()->processes();
        $server = $processes->execute(
            Command::foreground('php')
                ->withArgument('fixtures/eternal-server.php')
        );

        \sleep(1);

        $process = new Unix(
            $os->sockets(),
            new Protocol\Binary,
            $os->clock(),
            new Address($os->status()->tmp().'/innmind/ipc/server'),
            $name = new Name('server'),
            new ElapsedPeriod(1000)
        );

        try {
            $process->wait(new ElapsedPeriod(100));

            $this->fail('it should throw');
        } catch (Timedout $e) {
            $processes->kill($server->pid(), Signal::terminate());
            $this->assertTrue(true);
        }
    }
}
