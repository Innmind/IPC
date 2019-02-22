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
};
use Innmind\Url\Path;
use Innmind\TimeContinuum\{
    TimeContinuumInterface,
    ElapsedPeriodInterface,
    ElapsedPeriod,
    Period\Earth\Millisecond,
    PointInTimeInterface,
};
use Innmind\Socket\Client;
use Innmind\Immutable\{
    Map,
    SetInterface,
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
                $this->createMock(TimeContinuumInterface::class),
                $this->createMock(CurrentProcess::class),
                $this->createMock(Protocol::class),
                new Path('/tmp/somewhere/'),
                new ElapsedPeriod(1000)
            )
        );
    }

    public function testProcesses()
    {
        $ipc = new Unix(
            $sockets = $this->createMock(Sockets::class),
            $filesystem = $this->createMock(Adapter::class),
            $this->createMock(TimeContinuumInterface::class),
            $this->createMock(CurrentProcess::class),
            $protocol = $this->createMock(Protocol::class),
            new Path('/tmp/'),
            new ElapsedPeriod(1000)
        );
        $filesystem
            ->expects($this->once())
            ->method('all')
            ->willReturn(
                Map::of('string', File::class)
                    ('foo', $this->createMock(File::class))
                    ('bar', $this->createMock(File::class))
            );
        $sockets
            ->expects($this->at(0))
            ->method('connectTo')
            ->willReturn($client1 = $this->createMock(Client::class));
        $sockets
            ->expects($this->at(1))
            ->method('connectTo')
            ->willReturn($client2 = $this->createMock(Client::class));
        $resource1 = \tmpfile();
        $resource2 = \tmpfile();
        $client1
            ->expects($this->any())
            ->method('resource')
            ->willReturn($resource1);
        $client2
            ->expects($this->any())
            ->method('resource')
            ->willReturn($resource2);
        $protocol
            ->expects($this->exactly(2))
            ->method('decode')
            ->willReturn(new ConnectionStart);

        $processes = $ipc->processes();

        $this->assertInstanceOf(SetInterface::class, $processes);
        $this->assertSame(Process::class, (string) $processes->type());
        $this->assertCount(2, $processes);
        $foo = $processes->current();

        $this->assertInstanceOf(Process\Unix::class, $foo);
        $this->assertSame('foo', (string) $foo->name());
    }

    public function testThrowWhenGettingUnknownProcess()
    {
        $ipc = new Unix(
            $this->createMock(Sockets::class),
            $filesystem = $this->createMock(Adapter::class),
            $this->createMock(TimeContinuumInterface::class),
            $this->createMock(CurrentProcess::class),
            $this->createMock(Protocol::class),
            new Path('/tmp/somewhere/'),
            new ElapsedPeriod(1000)
        );
        $filesystem
            ->expects($this->once())
            ->method('has')
            ->with('foo.sock')
            ->willReturn(false);

        $this->expectException(LogicException::class);

        $ipc->get(new Name('foo'));
    }

    public function testGetProcess()
    {
        $ipc = new Unix(
            $sockets = $this->createMock(Sockets::class),
            $filesystem = $this->createMock(Adapter::class),
            $this->createMock(TimeContinuumInterface::class),
            $this->createMock(CurrentProcess::class),
            $protocol = $this->createMock(Protocol::class),
            new Path('/tmp/'),
            new ElapsedPeriod(1000)
        );
        $filesystem
            ->expects($this->once())
            ->method('has')
            ->with('foo.sock')
            ->willReturn(true);
        $sockets
            ->expects($this->once())
            ->method('connectTo')
            ->willReturn($client = $this->createMock(Client::class));
        $resource = \tmpfile();
        $client
            ->expects($this->any())
            ->method('resource')
            ->willReturn($resource);
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
            $this->createMock(TimeContinuumInterface::class),
            $this->createMock(CurrentProcess::class),
            $this->createMock(Protocol::class),
            new Path('/tmp/'),
            new ElapsedPeriod(1000)
        );
        $filesystem
            ->expects($this->exactly(2))
            ->method('has')
            ->with('foo.sock')
            ->will($this->onConsecutiveCalls(true, false));

        $this->assertTrue($ipc->exist(new Name('foo')));
        $this->assertFalse($ipc->exist(new Name('foo')));
    }

    public function testListen()
    {
        $ipc = new Unix(
            $sockets = $this->createMock(Sockets::class),
            $filesystem = $this->createMock(Adapter::class),
            $this->createMock(TimeContinuumInterface::class),
            $this->createMock(CurrentProcess::class),
            $this->createMock(Protocol::class),
            new Path('/tmp/'),
            new ElapsedPeriod(1000)
        );

        $server = $ipc->listen(
            new Name('bar'),
            $this->createMock(ElapsedPeriodInterface::class)
        );

        $this->assertInstanceOf(Server\Unix::class, $server);
    }

    public function testWait()
    {
        $ipc = new Unix(
            $this->createMock(Sockets::class),
            $filesystem = $this->createMock(Adapter::class),
            $this->createMock(TimeContinuumInterface::class),
            $process = $this->createMock(CurrentProcess::class),
            $this->createMock(Protocol::class),
            new Path('/tmp/'),
            new ElapsedPeriod(1000)
        );
        $filesystem
            ->expects($this->exactly(3))
            ->method('has')
            ->with('foo.sock')
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
            $clock = $this->createMock(TimeContinuumInterface::class),
            $process = $this->createMock(CurrentProcess::class),
            $this->createMock(Protocol::class),
            new Path('/tmp/'),
            new ElapsedPeriod(1000)
        );
        $timeout = $this->createMock(ElapsedPeriodInterface::class);
        $filesystem
            ->expects($this->any())
            ->method('has')
            ->with('foo.sock')
            ->willReturn(false);
        $process
            ->expects($this->once())
            ->method('halt');
        $clock
            ->expects($this->at(0))
            ->method('now')
            ->willReturn($start = $this->createMock(PointInTimeInterface::class));
        $clock
            ->expects($this->at(1))
            ->method('now')
            ->willReturn($firstIteration = $this->createMock(PointInTimeInterface::class));
        $clock
            ->expects($this->at(2))
            ->method('now')
            ->willReturn($secondIteration = $this->createMock(PointInTimeInterface::class));
        $firstIteration
            ->expects($this->once())
            ->method('elapsedSince')
            ->with($start)
            ->willReturn($duration = $this->createMock(ElapsedPeriodInterface::class));
        $duration
            ->expects($this->once())
            ->method('longerThan')
            ->with($timeout)
            ->willReturn(false);
        $secondIteration
            ->expects($this->once())
            ->method('elapsedSince')
            ->with($start)
            ->willReturn($duration = $this->createMock(ElapsedPeriodInterface::class));
        $duration
            ->expects($this->once())
            ->method('longerThan')
            ->with($timeout)
            ->willReturn(true);

        $this->assertNull($ipc->wait(new Name('foo'), $timeout));
    }
}
