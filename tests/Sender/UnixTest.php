<?php
declare(strict_types = 1);

namespace Tests\Innmind\IPC\Sender;

use Innmind\IPC\{
    Sender\Unix,
    Sender,
    Protocol,
    Message,
    Process\Name,
    Exception\RuntimeException,
};
use Innmind\OperatingSystem\Sockets;
use Innmind\Socket\{
    Address\Unix as Address,
    Client,
    Exception\Exception as SocketException,
};
use Innmind\Stream\Exception\Exception as StreamException;
use Innmind\Immutable\Str;
use PHPUnit\Framework\TestCase;

class UnixTest extends TestCase
{
    public function testInterface()
    {
        $this->assertInstanceOf(
            Sender::class,
            new Unix(
                $this->createMock(Sockets::class),
                $this->createMock(Protocol::class),
                new Address('/tmp/foo'),
                new Name('foo')
            )
        );
    }

    public function testSocketNotClosedOnDestructedWhenNoMessagesSent()
    {
        $send = new Unix(
            $sockets = $this->createMock(Sockets::class),
            $this->createMock(Protocol::class),
            new Address('/tmp/foo'),
            new Name('foo')
        );
        $sockets
            ->expects($this->never())
            ->method('connectTo');

        unset($send);
    }

    public function testSendMessages()
    {
        $send = new Unix(
            $sockets = $this->createMock(Sockets::class),
            $protocol = $this->createMock(Protocol::class),
            $address = new Address('/tmp/foo'),
            new Name('foo')
        );
        $message1 = $this->createMock(Message::class);
        $message2 = $this->createMock(Message::class);
        $protocol
            ->expects($this->at(0))
            ->method('encode')
            ->with($this->callback(static function($message): bool {
                return (string) $message->content() === 'foo';
            }))
            ->willReturn(Str::of('greeting'));
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
        $sockets
            ->expects($this->once())
            ->method('connectTo')
            ->with($address)
            ->willReturn($client = $this->createMock(Client::class));
        $client
            ->expects($this->at(0))
            ->method('write')
            ->with(Str::of('greeting'))
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

        $this->assertNull($send($message1, $message2));
    }

    public function testOpenSocketOnceWhenSendingMessagesOnMultipleCalls()
    {
        $send = new Unix(
            $sockets = $this->createMock(Sockets::class),
            $protocol = $this->createMock(Protocol::class),
            $address = new Address('/tmp/foo'),
            new Name('foo')
        );
        $message1 = $this->createMock(Message::class);
        $message2 = $this->createMock(Message::class);
        $protocol
            ->expects($this->at(0))
            ->method('encode')
            ->with($this->callback(static function($message): bool {
                return (string) $message->content() === 'foo';
            }))
            ->willReturn(Str::of('greeting'));
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
        $sockets
            ->expects($this->once())
            ->method('connectTo')
            ->with($address)
            ->willReturn($client = $this->createMock(Client::class));
        $client
            ->method('write')
            ->withConsecutive(
                [Str::of('greeting')],
                [$frame1],
                [$frame2]
            )
            ->will($this->returnSelf());
        $client
            ->expects($this->once())
            ->method('closed')
            ->willReturn(false);
        $client
            ->expects($this->once())
            ->method('close')
            ->will($this->returnSelf());

        $this->assertNull($send($message1));
        $this->assertNull($send($message2));
    }

    public function testWrapStreamException()
    {
        $send = new Unix(
            $sockets = $this->createMock(Sockets::class),
            $this->createMock(Protocol::class),
            new Address('/tmp/foo'),
            new Name('foo')
        );
        $sockets
            ->expects($this->once())
            ->method('connectTo')
            ->will($this->throwException($expected = $this->createMock(StreamException::class)));

        try {
            $send();

            $this->fail('it should throw');
        } catch (RuntimeException $e) {
            $this->assertSame($expected, $e->getPrevious());
        }
    }

    public function testWrapSocketException()
    {
        $send = new Unix(
            $sockets = $this->createMock(Sockets::class),
            $this->createMock(Protocol::class),
            new Address('/tmp/foo'),
            new Name('foo')
        );
        $sockets
            ->expects($this->once())
            ->method('connectTo')
            ->will($this->throwException($expected = $this->createMock(SocketException::class)));

        try {
            $send();

            $this->fail('it should throw');
        } catch (RuntimeException $e) {
            $this->assertSame($expected, $e->getPrevious());
        }
    }
}
