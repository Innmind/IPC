<?php
declare(strict_types = 1);

namespace Tests\Innmind\IPC\Process;

use Innmind\IPC\{
    Process\Unix,
    Process\Name,
    Process,
    Protocol,
    Message,
    Receiver,
};
use Innmind\OperatingSystem\Sockets;
use Innmind\Socket\{
    Address\Unix as Address,
    Client,
};
use Innmind\TimeContinuum\ElapsedPeriodInterface;
use Innmind\Immutable\Str;
use PHPUnit\Framework\TestCase;

class UnixTest extends TestCase
{
    public function testInterface()
    {
        $process = new Unix(
            $this->createMock(Sockets::class),
            $this->createMock(Protocol::class),
            new Address('/tmp/foo'),
            $name = new Name('foo')
        );

        $this->assertInstanceOf(Process::class, $process);
        $this->assertSame($name, $process->name());
    }

    public function testSendMessage()
    {
        $process = new Unix(
            $sockets = $this->createMock(Sockets::class),
            $protocol = $this->createMock(Protocol::class),
            $address = new Address('/tmp/foo'),
            new Name('foo')
        );
        $message = $this->createMock(Message::class);
        $sockets
            ->expects($this->once())
            ->method('connectTo')
            ->with($address)
            ->willReturn($client = $this->createMock(Client::class));
        $protocol
            ->expects($this->once())
            ->method('encode')
            ->with($message)
            ->willReturn($frame = Str::of(''));
        $client
            ->expects($this->once())
            ->method('write')
            ->with($frame)
            ->will($this->returnSelf());
        $client
            ->expects($this->once())
            ->method('close');

        $this->assertNull($process->send($message));
    }

    public function testListen()
    {
        $process = new Unix(
            $this->createMock(Sockets::class),
            $this->createMock(Protocol::class),
            new Address('/tmp/foo'),
            new Name('foo')
        );
        $timeout = $this->createMock(ElapsedPeriodInterface::class);

        $receiver = $process->listen($timeout);

        $this->assertInstanceOf(Receiver\UnixClient::class, $receiver);
    }
}
