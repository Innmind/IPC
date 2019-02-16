<?php
declare(strict_types = 1);

namespace Tests\Innmind\IPC\Receiver;

use Innmind\IPC\{
    Receiver\UnixClient,
    Receiver,
    Protocol,
    Process,
    Message,
    Exception\NoMessage,
    Exception\Stop,
    Exception\RuntimeException,
};
use Innmind\OperatingSystem\Sockets;
use Innmind\Socket\{
    Address\Unix as Address,
    Client,
    Exception\Exception as SocketException,
};
use Innmind\Stream\Exception\Exception as StreamException;
use PHPUnit\Framework\TestCase;

class UnixClientTest extends TestCase
{
    public function testInterface()
    {
        $this->assertInstanceOf(
            Receiver::class,
            new UnixClient(
                $this->createMock(Sockets::class),
                $this->createMock(Protocol::class),
                new Process\Name('foo'),
                new Address('/tmp/foo')
            )
        );
    }

    public function testLoop()
    {
        $receive = new UnixClient(
            $sockets = $this->createMock(Sockets::class),
            $protocol = $this->createMock(Protocol::class),
            $process = new Process\Name('foo'),
            $address = new Address('/tmp/foo')
        );
        $client = $this->createMock(Client::class);
        $client
            ->expects($this->any())
            ->method('resource')
            ->willReturn(\tmpfile());
        $client
            ->expects($this->once())
            ->method('close');
        $sockets
            ->expects($this->once())
            ->method('connectTo')
            ->with($address)
            ->willReturn($client);
        $protocol
            ->expects($this->exactly(4))
            ->method('decode')
            ->with($client)
            ->willReturn($message = $this->createMock(Message::class));

        try {
            $count = 0;

            $receive(function($a, $b) use ($message, $process, &$count): void {
                if ($count === 3) {
                    throw new \Exception;
                }

                $this->assertSame($message, $a);
                $this->assertSame($process, $b);
                ++$count;
            });

            $this->fail('it should throw');
        } catch (\Exception $e) {
            $this->assertSame(3, $count);
        }
    }

    public function testStopWhenConnectionClosedByOtherProcess()
    {
        $receive = new UnixClient(
            $sockets = $this->createMock(Sockets::class),
            $protocol = $this->createMock(Protocol::class),
            new Process\Name('foo'),
            $address = new Address('/tmp/foo')
        );
        $client = $this->createMock(Client::class);
        $client
            ->expects($this->any())
            ->method('resource')
            ->willReturn(\tmpfile());
        $client
            ->expects($this->once())
            ->method('close');
        $sockets
            ->expects($this->once())
            ->method('connectTo')
            ->with($address)
            ->willReturn($client);
        $protocol
            ->expects($this->at(3))
            ->method('decode')
            ->with($client)
            ->will($this->throwException(new NoMessage));

        $count = 0;

        $this->assertNull($receive(function() use (&$count): void {
            ++$count;
        }));
        $this->assertSame(3, $count);
    }

    public function testStopWhenAskedToDoSo()
    {
        $receive = new UnixClient(
            $sockets = $this->createMock(Sockets::class),
            $this->createMock(Protocol::class),
            new Process\Name('foo'),
            $address = new Address('/tmp/foo')
        );
        $client = $this->createMock(Client::class);
        $client
            ->expects($this->any())
            ->method('resource')
            ->willReturn(\tmpfile());
        $client
            ->expects($this->once())
            ->method('close');
        $sockets
            ->expects($this->once())
            ->method('connectTo')
            ->with($address)
            ->willReturn($client);

        $count = 0;

        $this->assertNull($receive(function() use (&$count): void {
            ++$count;
            throw new Stop;
        }));
        $this->assertSame(1, $count);
    }

    public function testWrapStreamException()
    {
        $receive = new UnixClient(
            $sockets = $this->createMock(Sockets::class),
            $this->createMock(Protocol::class),
            new Process\Name('foo'),
            new Address('/tmp/foo')
        );
        $sockets
            ->expects($this->once())
            ->method('connectTo')
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
        $receive = new UnixClient(
            $sockets = $this->createMock(Sockets::class),
            $this->createMock(Protocol::class),
            new Process\Name('foo'),
            new Address('/tmp/foo')
        );
        $sockets
            ->expects($this->once())
            ->method('connectTo')
            ->will($this->throwException($expected = $this->createMock(SocketException::class)));

        try {
            $receive(function(){});

            $this->fail('it should throw');
        } catch (RuntimeException $e) {
            $this->assertSame($expected, $e->getPrevious());
        }
    }
}
