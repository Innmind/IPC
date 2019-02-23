<?php
declare(strict_types = 1);

namespace Tests\Innmind\IPC\Server;

use Innmind\IPC\{
    Server\Unix,
    Server,
    Protocol,
    Exception\RuntimeException,
    Exception\Stop,
};
use Innmind\OperatingSystem\{
    Factory,
    Sockets,
};
use Innmind\Server\Control\Server\Command;
use Innmind\TimeContinuum\{
    TimeContinuumInterface,
    ElapsedPeriodInterface,
    ElapsedPeriod,
    PointInTimeInterface,
};
use Innmind\Filesystem\MediaType\MediaType;
use Innmind\Socket\{
    Address\Unix as Address,
    Server as ServerSocket,
    Exception\Exception as SocketException,
};
use Innmind\Stream\Exception\Exception as StreamException;
use PHPUnit\Framework\TestCase;

class UnixTest extends TestCase
{
    public function testInterface()
    {
        $this->assertInstanceOf(
            Server::class,
            new Unix(
                $this->createMock(Sockets::class),
                $this->createMock(Protocol::class),
                $this->createMock(TimeContinuumInterface::class),
                new Address('/tmp/foo.sock'),
                new ElapsedPeriod(1000)
            )
        );
    }

    public function testWrapStreamException()
    {
        $receive = new Unix(
            $sockets = $this->createMock(Sockets::class),
            $this->createMock(Protocol::class),
            $this->createMock(TimeContinuumInterface::class),
            new Address('/tmp/foo.sock'),
            new ElapsedPeriod(1000)
        );
        $sockets
            ->expects($this->once())
            ->method('open')
            ->will($this->throwException($expected = $this->createMock(StreamException::class)));

        try {
            $receive(function(){});

            $this->fail('it should throw');
        } catch (RuntimeException $e) {
            $this->assertSame($expected, $e->getPrevious());
        }
    }

    public function testWrapSocketException()
    {
        $receive = new Unix(
            $sockets = $this->createMock(Sockets::class),
            $this->createMock(Protocol::class),
            $this->createMock(TimeContinuumInterface::class),
            new Address('/tmp/foo.sock'),
            new ElapsedPeriod(1000)
        );
        $sockets
            ->expects($this->once())
            ->method('open')
            ->will($this->throwException($expected = $this->createMock(SocketException::class)));

        try {
            $receive(function(){});

            $this->fail('it should throw');
        } catch (RuntimeException $e) {
            $this->assertSame($expected, $e->getPrevious());
        }
    }

    public function testStopWhenNoActivityInGivenPeriod()
    {
        $receive = new Unix(
            $sockets = $this->createMock(Sockets::class),
            $this->createMock(Protocol::class),
            $clock = $this->createMock(TimeContinuumInterface::class),
            $address = new Address('/tmp/foo.sock'),
            new ElapsedPeriod(10),
            $timeout = $this->createMock(ElapsedPeriodInterface::class)
        );
        $sockets
            ->expects($this->once())
            ->method('open')
            ->willReturn(ServerSocket\Unix::recoverable($address));
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

        $this->assertNull($receive(function(){}));
    }

    public function testShutdownProcess()
    {
        $os = Factory::build();
        @unlink($os->status()->tmp().'/innmind/ipc/server.sock');
        $processes = $os->control()->processes();
        $server = $processes->execute(
            Command::foreground('php')
                ->withArgument('fixtures/long-client.php')
        );

        $listen = new Unix(
            $os->sockets(),
            new Protocol\Binary,
            $os->clock(),
            new Address($os->status()->tmp().'/innmind/ipc/server'),
            new ElapsedPeriod(100),
            new ElapsedPeriod(10000)
        );

        $this->assertNull($listen(static function() {
            throw new Stop;
        }));
    }
}
