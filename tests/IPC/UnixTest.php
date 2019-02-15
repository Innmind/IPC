<?php
declare(strict_types = 1);

namespace Tests\Innmind\IPC\IPC;

use Innmind\IPC\{
    IPC\Unix,
    IPC,
    Protocol,
    Receiver,
    Process,
    Process\Name,
    Exception\LogicException,
};
use Innmind\OperatingSystem\Sockets;
use Innmind\Filesystem\{
    Adapter,
    File,
};
use Innmind\Url\Path;
use Innmind\TimeContinuum\ElapsedPeriodInterface;
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
                $this->createMock(Protocol::class),
                new Path('/tmp/somewhere/')
            )
        );
    }

    public function testProcesses()
    {
        $ipc = new Unix(
            $sockets = $this->createMock(Sockets::class),
            $filesystem = $this->createMock(Adapter::class),
            $this->createMock(Protocol::class),
            new Path('/tmp/')
        );
        $filesystem
            ->expects($this->once())
            ->method('all')
            ->willReturn(
                Map::of('string', File::class)
                    ('foo', $this->createMock(File::class))
                    ('bar', $this->createMock(File::class))
            );

        $processes = $ipc->processes();

        $this->assertInstanceOf(SetInterface::class, $processes);
        $this->assertSame(Process::class, (string) $processes->type());
        $this->assertCount(2, $processes);
        $foo = $processes->current();

        $this->assertSame('foo', (string) $foo->name());
        $sockets
            ->expects($this->once())
            ->method('connectTo')
            ->with($this->callback(static function($address): bool {
                return (string) $address === '/tmp/foo.sock';
            }));

        $this->assertNull($foo->send());
    }

    public function testThrowWhenGettingUnknownProcess()
    {
        $ipc = new Unix(
            $this->createMock(Sockets::class),
            $filesystem = $this->createMock(Adapter::class),
            $this->createMock(Protocol::class),
            new Path('/tmp/somewhere/')
        );
        $filesystem
            ->expects($this->once())
            ->method('has')
            ->with('foo')
            ->willReturn(false);

        $this->expectException(LogicException::class);

        $ipc->get(new Name('foo'));
    }

    public function testGetProcess()
    {
        $ipc = new Unix(
            $sockets = $this->createMock(Sockets::class),
            $filesystem = $this->createMock(Adapter::class),
            $this->createMock(Protocol::class),
            new Path('/tmp/')
        );
        $filesystem
            ->expects($this->once())
            ->method('has')
            ->with('foo')
            ->willReturn(true);

        $foo = $ipc->get(new Name('foo'));

        $this->assertInstanceOf(Process::class, $foo);
        $this->assertSame('foo', (string) $foo->name());
        $sockets
            ->expects($this->once())
            ->method('connectTo')
            ->with($this->callback(static function($address): bool {
                return (string) $address === '/tmp/foo.sock';
            }));

        $this->assertNull($foo->send());
    }

    public function testListen()
    {
        $ipc = new Unix(
            $sockets = $this->createMock(Sockets::class),
            $filesystem = $this->createMock(Adapter::class),
            $this->createMock(Protocol::class),
            new Path('/tmp/')
        );

        $receiver = $ipc->listen(
            new Name('bar'),
            $this->createMock(ElapsedPeriodInterface::class)
        );

        $this->assertInstanceOf(Receiver\UnixServer::class, $receiver);
    }
}
