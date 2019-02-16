<?php
declare(strict_types = 1);

namespace Tests\Innmind\IPC\Receiver;

use Innmind\IPC\{
    Receiver\UnixServer,
    Receiver,
    Protocol,
    Process,
    Message,
    Exception\Stop,
    Exception\RuntimeException,
};
use Innmind\OperatingSystem\Sockets;
use Innmind\TimeContinuum\{
    TimeContinuumInterface,
    ElapsedPeriodInterface,
    ElapsedPeriod,
    PointInTimeInterface,
};
use Innmind\Filesystem\MediaType\MediaType;
use Innmind\Socket\{
    Address\Unix as Address,
    Server,
    Server\Connection,
    Exception\Exception as SocketException,
};
use Innmind\Stream\Exception\Exception as StreamException;
use Innmind\Immutable\Str;
use PHPUnit\Framework\TestCase;

class UnixServerTest extends TestCase
{
    public function testInterface()
    {
        $this->assertInstanceOf(
            Receiver::class,
            new UnixServer(
                $this->createMock(Sockets::class),
                $this->createMock(Protocol::class),
                $this->createMock(TimeContinuumInterface::class),
                new Address('/tmp/foo.sock'),
                new Process\Name('foo'),
                new ElapsedPeriod(1000)
            )
        );
    }

    public function testLoop()
    {
        $receive = new UnixServer(
            $sockets = $this->createMock(Sockets::class),
            $protocol = $this->createMock(Protocol::class),
            $this->createMock(TimeContinuumInterface::class),
            $address = new Address('/tmp/foo.sock'),
            $name = new Process\Name('foo'),
            new ElapsedPeriod(1000)
        );
        $sockets
            ->expects($this->once())
            ->method('open')
            ->with($address)
            ->willReturn($server = $this->createMock(Server::class));
        $server
            ->expects($this->any())
            ->method('resource')
            ->willReturn($serverResource = \tmpfile());
        $server
            ->expects($this->once())
            ->method('close');
        $connection = $this->createMock(Connection::class);
        $server
            ->expects($this->once())
            ->method('accept')
            ->will($this->returnCallback(function() use ($serverResource, $connection) {
                \fclose($serverResource); // to simulate that no other connection are incoming

                return $connection;
            }));
        $connection
            ->expects($this->any())
            ->method('resource')
            ->willReturn(\tmpfile());
        $connection
            ->expects($this->once())
            ->method('close');
        $protocol
            ->expects($this->at(0))
            ->method('decode')
            ->with($connection)
            ->willReturn(new Message\Generic(
                MediaType::fromString('application/json'),
                Str::of('bar')
            ));
        $protocol
            ->expects($this->at(1))
            ->method('decode')
            ->with($connection)
            ->willReturn($message = $this->createMock(Message::class));

        $count = 0;
        $this->assertNull($receive(function($a, $b) use ($message, &$count): void {
            $this->assertSame($message, $a);
            $this->assertInstanceOf(Process\Name::class, $b);
            $this->assertSame('bar', (string) $b);
            ++$count;

            throw new Stop;
        }));
        $this->assertSame(1, $count);
    }

    public function testWrapStreamException()
    {
        $receive = new UnixServer(
            $sockets = $this->createMock(Sockets::class),
            $this->createMock(Protocol::class),
            $this->createMock(TimeContinuumInterface::class),
            new Address('/tmp/foo.sock'),
            new Process\Name('foo'),
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
        $receive = new UnixServer(
            $sockets = $this->createMock(Sockets::class),
            $this->createMock(Protocol::class),
            $this->createMock(TimeContinuumInterface::class),
            new Address('/tmp/foo.sock'),
            new Process\Name('foo'),
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
        $receive = new UnixServer(
            $sockets = $this->createMock(Sockets::class),
            $this->createMock(Protocol::class),
            $clock = $this->createMock(TimeContinuumInterface::class),
            $address = new Address('/tmp/foo.sock'),
            new Process\Name('foo'),
            new ElapsedPeriod(10),
            $timeout = $this->createMock(ElapsedPeriodInterface::class)
        );
        $sockets
            ->expects($this->once())
            ->method('open')
            ->willReturn(Server\Unix::recoverable($address));
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
}
