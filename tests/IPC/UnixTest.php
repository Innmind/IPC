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
    Map,
    Set,
    Str,
};
use function Innmind\Immutable\unwrap;
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
                new Timeout(1000)
            )
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
            new Timeout(1000)
        );
        $filesystem
            ->expects($this->once())
            ->method('all')
            ->willReturn(
                Set::of(
                    File::class,
                    $foo = $this->createMock(File::class),
                    $bar = $this->createMock(File::class),
                )
            );
        $foo
            ->method('name')
            ->willReturn(new FileName('foo'));
        $bar
            ->method('name')
            ->willReturn(new FileName('bar'));

        $processes = $ipc->processes();

        $this->assertInstanceOf(Set::class, $processes);
        $this->assertSame(Process\Name::class, (string) $processes->type());
        $this->assertCount(2, $processes);
        $processes = unwrap($processes);

        $foo = \current($processes);
        $this->assertSame('foo', (string) $foo);
        \next($processes);
        $bar = \current($processes);
        $this->assertSame('bar', (string) $bar);
    }

    public function testThrowWhenGettingUnknownProcess()
    {
        $ipc = new Unix(
            $this->createMock(Sockets::class),
            $filesystem = $this->createMock(Adapter::class),
            $this->createMock(Clock::class),
            $this->createMock(CurrentProcess::class),
            $this->createMock(Protocol::class),
            Path::of('/tmp/somewhere/'),
            new Timeout(1000)
        );
        $filesystem
            ->expects($this->once())
            ->method('contains')
            ->with(new FileName('foo.sock'))
            ->willReturn(false);

        $this->expectException(LogicException::class);

        $ipc->get(new Name('foo'));
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
            $heartbeat = new Timeout(1000)
        );
        $filesystem
            ->expects($this->once())
            ->method('contains')
            ->with(new FileName('foo.sock'))
            ->willReturn(true);
        $sockets
            ->expects($this->once())
            ->method('connectTo')
            ->willReturn($client = $this->createMock(Client::class));
        $sockets
            ->expects($this->once())
            ->method('watch')
            ->with($heartbeat)
            ->willReturn(new Select($heartbeat));
        $resource = \tmpfile();
        $client
            ->expects($this->any())
            ->method('resource')
            ->willReturn($resource);
        $protocol
            ->expects($this->once())
            ->method('encode')
            ->willReturn(Str::of('welcome'));
        $protocol
            ->expects($this->once())
            ->method('decode')
            ->willReturn(new ConnectionStart);

        $foo = $ipc->get(new Name('foo'));

        $this->assertInstanceOf(Process\Unix::class, $foo);
        $this->assertSame('foo', (string) $foo->name());
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
            new Timeout(1000)
        );
        $filesystem
            ->expects($this->exactly(2))
            ->method('contains')
            ->with(new FileName('foo.sock'))
            ->will($this->onConsecutiveCalls(true, false));

        $this->assertTrue($ipc->exist(new Name('foo')));
        $this->assertFalse($ipc->exist(new Name('foo')));
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
            new Timeout(1000)
        );

        $server = $ipc->listen(
            new Name('bar'),
            $this->createMock(ElapsedPeriod::class)
        );

        $this->assertInstanceOf(Server\Unix::class, $server);
    }

    public function testWait()
    {
        $ipc = new Unix(
            $this->createMock(Sockets::class),
            $filesystem = $this->createMock(Adapter::class),
            $this->createMock(Clock::class),
            $process = $this->createMock(CurrentProcess::class),
            $this->createMock(Protocol::class),
            Path::of('/tmp/'),
            new Timeout(1000)
        );
        $filesystem
            ->expects($this->exactly(3))
            ->method('contains')
            ->with(new FileName('foo.sock'))
            ->will($this->onConsecutiveCalls(false, false, true));
        $process
            ->expects($this->exactly(2))
            ->method('halt')
            ->with(new Millisecond(1000));

        $this->assertNull($ipc->wait(new Name('foo')));
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
            new Timeout(1000)
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
            ->expects($this->at(0))
            ->method('now')
            ->willReturn($start = $this->createMock(PointInTime::class));
        $clock
            ->expects($this->at(1))
            ->method('now')
            ->willReturn($firstIteration = $this->createMock(PointInTime::class));
        $clock
            ->expects($this->at(2))
            ->method('now')
            ->willReturn($secondIteration = $this->createMock(PointInTime::class));
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

        $this->assertNull($ipc->wait(new Name('foo'), $timeout));
    }
}
