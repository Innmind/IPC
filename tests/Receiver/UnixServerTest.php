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
};
use Innmind\OperatingSystem\Sockets;
use Innmind\Filesystem\{
    Adapter,
    MediaType\MediaType,
};
use Innmind\Socket\{
    Address\Unix as Address,
    Server,
    Server\Connection,
};
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
                $this->createMock(Adapter::class),
                $this->createMock(Protocol::class),
                new Address('/tmp/foo.sock'),
                new Process\Name('foo')
            )
        );
    }

    public function testLoop()
    {
        $receive = new UnixServer(
            $sockets = $this->createMock(Sockets::class),
            $filesystem = $this->createMock(Adapter::class),
            $protocol = $this->createMock(Protocol::class),
            $address = new Address('/tmp/foo.sock'),
            $name = new Process\Name('foo')
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
        $filesystem
            ->expects($this->once())
            ->method('remove')
            ->with('foo');

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
}
