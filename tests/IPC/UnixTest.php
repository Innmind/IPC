<?php
declare(strict_types = 1);

namespace Tests\Innmind\IPC\IPC;

use Innmind\IPC\{
    IPC\Unix,
    IPC,
    Protocol,
    Server,
    Process,
    Process\Name,
    Message\ConnectionStart,
    Message\ConnectionStartOk,
    Exception\LogicException,
};
use Innmind\OperatingSystem\{
    Sockets,
    CurrentProcess,
};
use Innmind\Filesystem\{
    Adapter,
    File,
    Name as FileName,
};
use Innmind\Url\Path;
use Innmind\TimeContinuum\{
    Clock,
    ElapsedPeriod,
    Earth\Period\Millisecond,
    Earth\ElapsedPeriod as Timeout,
    PointInTime,
};
use Innmind\Socket\Client;
use Innmind\Stream\Watch\Select;
use Innmind\Immutable\{
    Set,
    Str,
    Maybe,
    Either,
};
use PHPUnit\Framework\TestCase;

class UnixTest extends TestCase
{
    public function testInterface()
    {
        $this->assertInstanceOf(
            IPC::class,
            new Unix(
                $this->createMock(Sockets::class),
                $this->createMock(Adapter::class),
                $this->createMock(Clock::class),
                $this->createMock(CurrentProcess::class),
                $this->createMock(Protocol::class),
                Path::of('/tmp/somewhere/'),
                new Timeout(1000),
            ),
        );
    }

    public function testThrowIfThePathDoesntRepresentADirectory()
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage("Path must be a directory, got '/tmp/somewhere'");

        new Unix(
            $this->createMock(Sockets::class),
            $this->createMock(Adapter::class),
            $this->createMock(Clock::class),
            $this->createMock(CurrentProcess::class),
            $this->createMock(Protocol::class),
            Path::of('/tmp/somewhere'),
            new Timeout(1000),
        );
    }

    public function testProcesses()
    {
        $ipc = new Unix(
            $sockets = $this->createMock(Sockets::class),
            $filesystem = $this->createMock(Adapter::class),
            $this->createMock(Clock::class),
            $this->createMock(CurrentProcess::class),
            $protocol = $this->createMock(Protocol::class),
            Path::of('/tmp/'),
            new Timeout(1000),
        );
        $filesystem
            ->expects($this->once())
            ->method('all')
            ->willReturn(
                Set::of(
                    $foo = $this->createMock(File::class),
                    $bar = $this->createMock(File::class),
                ),
            );
        $foo
            ->method('name')
            ->willReturn(new FileName('foo'));
        $bar
            ->method('name')
            ->willReturn(new FileName('bar'));

        $processes = $ipc->processes();

        $this->assertInstanceOf(Set::class, $processes);
        $this->assertCount(2, $processes);
        $processes = $processes->toList();

        $foo = \current($processes);
        $this->assertSame('foo', $foo->toString());
        \next($processes);
        $bar = \current($processes);
        $this->assertSame('bar', $bar->toString());
    }

    public function testReturnNothingWhenGettingUnknownProcess()
    {
        $ipc = new Unix(
            $this->createMock(Sockets::class),
            $filesystem = $this->createMock(Adapter::class),
            $this->createMock(Clock::class),
            $this->createMock(CurrentProcess::class),
            $this->createMock(Protocol::class),
            Path::of('/tmp/somewhere/'),
            new Timeout(1000),
        );
        $filesystem
            ->expects($this->once())
            ->method('contains')
            ->with(new FileName('foo.sock'))
            ->willReturn(false);

        $this->assertNull($ipc->get(Name::of('foo'))->match(
            static fn($foo) => $foo,
            static fn() => null,
        ));
    }

    public function testGetProcess()
    {
        $ipc = new Unix(
            $sockets = $this->createMock(Sockets::class),
            $filesystem = $this->createMock(Adapter::class),
            $this->createMock(Clock::class),
            $this->createMock(CurrentProcess::class),
            $protocol = $this->createMock(Protocol::class),
            Path::of('/tmp/'),
            $heartbeat = new Timeout(1000),
        );
        $filesystem
            ->expects($this->once())
            ->method('contains')
            ->with(new FileName('foo.sock'))
            ->willReturn(true);
        $sockets
            ->expects($this->once())
            ->method('connectTo')
            ->willReturn(Maybe::just($client = $this->createMock(Client::class)));
        $sockets
            ->expects($this->once())
            ->method('watch')
            ->with($heartbeat)
            ->willReturn(Select::timeoutAfter($heartbeat));
        $resource = \tmpfile();
        $client
            ->expects($this->any())
            ->method('resource')
            ->willReturn($resource);
        $client
            ->expects($this->any())
            ->method('write')
            ->willReturn(Either::right($client));
        $protocol
            ->expects($this->once())
            ->method('encode')
            ->willReturn(Str::of('welcome'));
        $protocol
            ->expects($this->once())
            ->method('decode')
            ->willReturn(Maybe::just(new ConnectionStart));

        $foo = $ipc->get(Name::of('foo'))->match(
            static fn($foo) => $foo,
            static fn() => null,
        );

        $this->assertInstanceOf(Process\Unix::class, $foo);
        $this->assertSame('foo', $foo->name()->toString());
    }

    public function testExist()
    {
        $ipc = new Unix(
            $this->createMock(Sockets::class),
            $filesystem = $this->createMock(Adapter::class),
            $this->createMock(Clock::class),
            $this->createMock(CurrentProcess::class),
            $this->createMock(Protocol::class),
            Path::of('/tmp/'),
            new Timeout(1000),
        );
        $filesystem
            ->expects($this->exactly(2))
            ->method('contains')
            ->with(new FileName('foo.sock'))
            ->will($this->onConsecutiveCalls(true, false));

        $this->assertTrue($ipc->exist(Name::of('foo')));
        $this->assertFalse($ipc->exist(Name::of('foo')));
    }

    public function testListen()
    {
        $ipc = new Unix(
            $sockets = $this->createMock(Sockets::class),
            $filesystem = $this->createMock(Adapter::class),
            $this->createMock(Clock::class),
            $this->createMock(CurrentProcess::class),
            $this->createMock(Protocol::class),
            Path::of('/tmp/'),
            new Timeout(1000),
        );

        $server = $ipc->listen(
            Name::of('bar'),
            $this->createMock(ElapsedPeriod::class),
        );

        $this->assertInstanceOf(Server\Unix::class, $server);
    }

    public function testWait()
    {
        $ipc = new Unix(
            $sockets = $this->createMock(Sockets::class),
            $filesystem = $this->createMock(Adapter::class),
            $this->createMock(Clock::class),
            $process = $this->createMock(CurrentProcess::class),
            $protocol = $this->createMock(Protocol::class),
            Path::of('/tmp/'),
            $timeout = new Timeout(1000),
        );
        $filesystem
            ->expects($this->exactly(4))
            ->method('contains')
            ->with(new FileName('foo.sock'))
            ->will($this->onConsecutiveCalls(false, false, true, true));
        $process
            ->expects($this->exactly(2))
            ->method('halt')
            ->with(new Millisecond(1000));
        $sockets
            ->expects($this->once())
            ->method('connectTo')
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

        $this->assertInstanceOf(Process::class, $ipc->wait(Name::of('foo'))->match(
            static fn($process) => $process,
            static fn() => null,
        ));
    }

    public function testStopWaitingWhenTimeoutExceeded()
    {
        $ipc = new Unix(
            $this->createMock(Sockets::class),
            $filesystem = $this->createMock(Adapter::class),
            $clock = $this->createMock(Clock::class),
            $process = $this->createMock(CurrentProcess::class),
            $this->createMock(Protocol::class),
            Path::of('/tmp/'),
            new Timeout(1000),
        );
        $timeout = $this->createMock(ElapsedPeriod::class);
        $filesystem
            ->expects($this->any())
            ->method('contains')
            ->with(new FileName('foo.sock'))
            ->willReturn(false);
        $process
            ->expects($this->once())
            ->method('halt');
        $clock
            ->expects($this->exactly(3))
            ->method('now')
            ->will($this->onConsecutiveCalls(
                $start = $this->createMock(PointInTime::class),
                $firstIteration = $this->createMock(PointInTime::class),
                $secondIteration = $this->createMock(PointInTime::class),
            ));
        $firstIteration
            ->expects($this->once())
            ->method('elapsedSince')
            ->with($start)
            ->willReturn($duration = $this->createMock(ElapsedPeriod::class));
        $duration
            ->expects($this->once())
            ->method('longerThan')
            ->with($timeout)
            ->willReturn(false);
        $secondIteration
            ->expects($this->once())
            ->method('elapsedSince')
            ->with($start)
            ->willReturn($duration = $this->createMock(ElapsedPeriod::class));
        $duration
            ->expects($this->once())
            ->method('longerThan')
            ->with($timeout)
            ->willReturn(true);

        $this->assertNull($ipc->wait(Name::of('foo'), $timeout)->match(
            static fn($process) => $process,
            static fn() => null,
        ));
    }
}
