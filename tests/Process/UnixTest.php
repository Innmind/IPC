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
    Sender,
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

    public function testSendMessages()
    {
        $process = new Unix(
            $sockets = $this->createMock(Sockets::class),
            $protocol = $this->createMock(Protocol::class),
            $address = new Address('/tmp/foo'),
            new Name('foo')
        );
        $message1 = $this->createMock(Message::class);
        $message2 = $this->createMock(Message::class);
        $sockets
            ->expects($this->once())
            ->method('connectTo')
            ->with($address)
            ->willReturn($client = $this->createMock(Client::class));
        $protocol
            ->expects($this->at(0))
            ->method('encode')
            ->with($this->callback(static function($message): bool {
                return (string) $message->mediaType() === 'text/plain' &&
                    (string) $message->content() === 'sender';
            }))
            ->willReturn($greeting = Str::of('greeting'));
        $protocol
            ->expects($this->at(1))
            ->method('encode')
            ->with($message1)
            ->willReturn($frame1 = Str::of(''));
        $protocol
            ->expects($this->at(2))
            ->method('encode')
            ->with($message2)
            ->willReturn($frame2 = Str::of(''));
        $client
            ->expects($this->at(0))
            ->method('write')
            ->with($greeting)
            ->will($this->returnSelf());
        $client
            ->expects($this->at(1))
            ->method('write')
            ->with($frame1)
            ->will($this->returnSelf());
        $client
            ->expects($this->at(2))
            ->method('write')
            ->with($frame2)
            ->will($this->returnSelf());
        $client
            ->expects($this->once())
            ->method('close');

        $send = $process->send(new Name('sender'));

        $this->assertInstanceOf(Sender\Unix::class, $send);
        $this->assertNull($send($message1, $message2));
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
