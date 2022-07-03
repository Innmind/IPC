<?php
declare(strict_types = 1);

namespace Tests\Innmind\IPC\Server;

use Innmind\IPC\{
    Server\ClientLifecycle,
    Protocol,
    Client,
    Continuation,
    Message,
    Message\ConnectionStart,
    Message\ConnectionStartOk,
    Message\ConnectionClose,
    Message\ConnectionCloseOk,
    Message\MessageReceived,
    Message\Heartbeat,
};
use Innmind\Socket\Server\Connection;
use Innmind\TimeContinuum\{
    Clock,
    ElapsedPeriod,
    Earth\ElapsedPeriod as Timeout,
    PointInTime,
};
use Innmind\Stream\{
    FailedToWriteToStream,
    FailedToCloseStream,
};
use Innmind\Immutable\{
    Str,
    Either,
    SideEffect,
    Maybe,
};
use PHPUnit\Framework\TestCase;

class ClientLifecycleTest extends TestCase
{
    public function testGreetClientUponInstanciation()
    {
        $connection = $this->createMock(Connection::class);
        $protocol = $this->createMock(Protocol::class);
        $clock = $this->createMock(Clock::class);
        $heartbeat = new Timeout(1000);
        $connection
            ->expects($this->once())
            ->method('closed')
            ->willReturn(false);
        $protocol
            ->expects($this->once())
            ->method('encode')
            ->with(new ConnectionStart)
            ->willReturn(Str::of('start'));
        $connection
            ->expects($this->once())
            ->method('write')
            ->with(Str::of('start'))
            ->willReturn(Either::right($connection));

        $lifecycle = ClientLifecycle::of($connection, $protocol, $clock, $heartbeat)->match(
            static fn($lifecycle) => $lifecycle,
            static fn() => null,
        );

        $this->assertInstanceOf(ClientLifecycle::class, $lifecycle);
    }

    public function testDoNotSendHeartbeatWhenFewerThanHeartbeatPeriod()
    {
        $connection = $this->createMock(Connection::class);
        $protocol = $this->createMock(Protocol::class);
        $clock = $this->createMock(Clock::class);
        $heartbeat = new Timeout(1000);
        $connection
            ->expects($this->once())
            ->method('closed')
            ->willReturn(false);
        $connection
            ->expects($this->once())
            ->method('write')
            ->with(Str::of('start'))
            ->willReturn(Either::right($connection));
        $protocol
            ->expects($this->once())
            ->method('encode')
            ->with(new ConnectionStart)
            ->willReturn(Str::of('start'));
        $clock
            ->expects($this->exactly(2))
            ->method('now')
            ->will($this->onConsecutiveCalls(
                $start = $this->createMock(PointInTime::class),
                $now = $this->createMock(PointInTime::class),
            ));
        $now
            ->expects($this->once())
            ->method('elapsedSince')
            ->with($start)
            ->willReturn($period = $this->createMock(ElapsedPeriod::class));
        $period
            ->expects($this->once())
            ->method('longerThan')
            ->with($heartbeat)
            ->willReturn(false);

        $lifecycle = ClientLifecycle::of($connection, $protocol, $clock, $heartbeat)->match(
            static fn($lifecycle) => $lifecycle,
            static fn() => null,
        );

        $lifecycle = $lifecycle->heartbeat();
        $this->assertInstanceOf(ClientLifecycle::class, $lifecycle);
    }

    public function testSilenceFailureToHeartbeatClient()
    {
        $connection = $this->createMock(Connection::class);
        $protocol = $this->createMock(Protocol::class);
        $clock = $this->createMock(Clock::class);
        $heartbeat = new Timeout(1000);
        $connection
            ->expects($this->exactly(2))
            ->method('closed')
            ->willReturn(false);
        $connection
            ->expects($this->exactly(2))
            ->method('write')
            ->withConsecutive([Str::of('start')], [Str::of('heartbeat')])
            ->will($this->onConsecutiveCalls(
                $this->returnValue(Either::right($connection)),
                $this->returnValue(Either::left(new FailedToWriteToStream)),
            ));
        $protocol
            ->expects($this->exactly(2))
            ->method('encode')
            ->withConsecutive([new ConnectionStart], [new Heartbeat])
            ->will($this->onConsecutiveCalls(
                Str::of('start'),
                Str::of('heartbeat'),
            ));
        $clock
            ->expects($this->exactly(2))
            ->method('now')
            ->will($this->onConsecutiveCalls(
                $start = $this->createMock(PointInTime::class),
                $now = $this->createMock(PointInTime::class),
            ));
        $now
            ->expects($this->once())
            ->method('elapsedSince')
            ->with($start)
            ->willReturn($period = $this->createMock(ElapsedPeriod::class));
        $period
            ->expects($this->once())
            ->method('longerThan')
            ->with($heartbeat)
            ->willReturn(true);

        $lifecycle = ClientLifecycle::of($connection, $protocol, $clock, $heartbeat)->match(
            static fn($lifecycle) => $lifecycle,
            static fn() => null,
        );

        $lifecycle = $lifecycle->heartbeat();
        $this->assertInstanceOf(ClientLifecycle::class, $lifecycle);
    }

    public function testSendHeartbeatWhenLongerThanHeartbeatPeriod()
    {
        $connection = $this->createMock(Connection::class);
        $protocol = $this->createMock(Protocol::class);
        $clock = $this->createMock(Clock::class);
        $heartbeat = new Timeout(1000);
        $connection
            ->expects($this->exactly(2))
            ->method('closed')
            ->willReturn(false);
        $connection
            ->expects($this->exactly(2))
            ->method('write')
            ->withConsecutive([Str::of('start')], [Str::of('heartbeat')])
            ->willReturn(Either::right($connection));
        $protocol
            ->expects($this->exactly(2))
            ->method('encode')
            ->withConsecutive([new ConnectionStart], [new Heartbeat])
            ->will($this->onConsecutiveCalls(
                Str::of('start'),
                Str::of('heartbeat'),
            ));
        $clock
            ->expects($this->exactly(2))
            ->method('now')
            ->will($this->onConsecutiveCalls(
                $start = $this->createMock(PointInTime::class),
                $now = $this->createMock(PointInTime::class),
            ));
        $now
            ->expects($this->once())
            ->method('elapsedSince')
            ->with($start)
            ->willReturn($period = $this->createMock(ElapsedPeriod::class));
        $period
            ->expects($this->once())
            ->method('longerThan')
            ->with($heartbeat)
            ->willReturn(true);

        $lifecycle = ClientLifecycle::of($connection, $protocol, $clock, $heartbeat)->match(
            static fn($lifecycle) => $lifecycle,
            static fn() => null,
        );

        $lifecycle = $lifecycle->heartbeat();
        $this->assertInstanceOf(ClientLifecycle::class, $lifecycle);
    }

    public function testConsiderGarbageWhenReadingButNoMessage()
    {
        $connection = $this->createMock(Connection::class);
        $protocol = $this->createMock(Protocol::class);
        $clock = $this->createMock(Clock::class);
        $heartbeat = new Timeout(1000);
        $connection
            ->expects($this->once())
            ->method('closed')
            ->willReturn(false);
        $connection
            ->expects($this->once())
            ->method('write')
            ->with(Str::of('start'))
            ->willReturn(Either::right($connection));
        $protocol
            ->expects($this->once())
            ->method('encode')
            ->with(new ConnectionStart)
            ->willReturn(Str::of('start'));
        $protocol
            ->expects($this->once())
            ->method('decode')
            ->with($connection)
            ->willReturn(Maybe::nothing());

        $lifecycle = ClientLifecycle::of($connection, $protocol, $clock, $heartbeat)->match(
            static fn($lifecycle) => $lifecycle,
            static fn() => null,
        );
        $called = false;

        $lifecycle = $lifecycle->notify(static function() use (&$called) {
            $called = true;
        })->match(
            static fn($lifecycle) => $lifecycle,
            static fn() => null,
        );
        $this->assertNull($lifecycle);
        $this->assertFalse($called);
    }

    public function testDoNotNotifyWhenNotReceivedMesageOk()
    {
        $connection = $this->createMock(Connection::class);
        $protocol = $this->createMock(Protocol::class);
        $clock = $this->createMock(Clock::class);
        $heartbeat = new Timeout(1000);
        $connection
            ->expects($this->once())
            ->method('closed')
            ->willReturn(false);
        $connection
            ->expects($this->once())
            ->method('write')
            ->with(Str::of('start'))
            ->willReturn(Either::right($connection));
        $protocol
            ->expects($this->once())
            ->method('encode')
            ->with(new ConnectionStart)
            ->willReturn(Str::of('start'));
        $protocol
            ->expects($this->once())
            ->method('decode')
            ->with($connection)
            ->willReturn(Maybe::just($this->createMock(Message::class)));

        $lifecycle = ClientLifecycle::of($connection, $protocol, $clock, $heartbeat)->match(
            static fn($lifecycle) => $lifecycle,
            static fn() => null,
        );
        $called = false;

        $lifecycle2 = $lifecycle->notify(static function() use (&$called) {
            $called = true;
        })->match(
            static fn($lifecycle) => $lifecycle,
            static fn() => null,
        );
        $this->assertEquals($lifecycle2, $lifecycle);
        $this->assertFalse($called);
    }

    public function testDoNotNotifyWhenHeartbeatMessage()
    {
        $connection = $this->createMock(Connection::class);
        $protocol = $this->createMock(Protocol::class);
        $clock = $this->createMock(Clock::class);
        $heartbeat = new Timeout(1000);
        $connection
            ->expects($this->once())
            ->method('closed')
            ->willReturn(false);
        $connection
            ->expects($this->once())
            ->method('write')
            ->with(Str::of('start'))
            ->willReturn(Either::right($connection));
        $protocol
            ->expects($this->once())
            ->method('encode')
            ->with(new ConnectionStart)
            ->willReturn(Str::of('start'));
        $protocol
            ->expects($this->once())
            ->method('decode')
            ->with($connection)
            ->willReturn(Maybe::just(new Heartbeat));

        $lifecycle = ClientLifecycle::of($connection, $protocol, $clock, $heartbeat)->match(
            static fn($lifecycle) => $lifecycle,
            static fn() => null,
        );
        $called = false;

        $lifecycle2 = $lifecycle->notify(static function() use (&$called) {
            $called = true;
        })->match(
            static fn($lifecycle) => $lifecycle,
            static fn() => null,
        );
        $this->assertEquals($lifecycle2, $lifecycle);
        $this->assertFalse($called);
    }

    public function testConfirmConnectionClose()
    {
        $connection = $this->createMock(Connection::class);
        $protocol = $this->createMock(Protocol::class);
        $clock = $this->createMock(Clock::class);
        $heartbeat = new Timeout(1000);
        $connection
            ->expects($this->exactly(2))
            ->method('closed')
            ->willReturn(false);
        $connection
            ->expects($this->exactly(2))
            ->method('write')
            ->withConsecutive([Str::of('start')], [Str::of('close-ok')])
            ->willReturn(Either::right($connection));
        $connection
            ->expects($this->once())
            ->method('close')
            ->willReturn(Either::right(new SideEffect));
        $protocol
            ->expects($this->exactly(2))
            ->method('encode')
            ->withConsecutive([new ConnectionStart], [new ConnectionCloseOk])
            ->will($this->onConsecutiveCalls(Str::of('start'), Str::of('close-ok')));
        $protocol
            ->expects($this->exactly(2))
            ->method('decode')
            ->with($connection)
            ->will($this->onConsecutiveCalls(
                Maybe::just(new ConnectionStartOk),
                Maybe::just(new ConnectionClose),
            ));

        $lifecycle = ClientLifecycle::of($connection, $protocol, $clock, $heartbeat)->match(
            static fn($lifecycle) => $lifecycle,
            static fn() => null,
        );
        $called = false;
        $callback = static function() use (&$called) {
            $called = true;
        };

        $lifecycle = $lifecycle->notify($callback)->match(
            static fn($lifecycle) => $lifecycle,
            static fn() => null,
        ); // connection start
        $this->assertInstanceOf(ClientLifecycle::class, $lifecycle);
        $lifecycle = $lifecycle->notify($callback)->match(
            static fn($lifecycle) => $lifecycle,
            static fn() => null,
        ); // connection close
        $this->assertNull($lifecycle);
        $this->assertFalse($called);
    }

    public function testNotify()
    {
        $connection = $this->createMock(Connection::class);
        $protocol = $this->createMock(Protocol::class);
        $clock = $this->createMock(Clock::class);
        $heartbeat = new Timeout(1000);
        $connection
            ->expects($this->exactly(3))
            ->method('closed')
            ->willReturn(false);
        $connection
            ->expects($this->exactly(3))
            ->method('write')
            ->withConsecutive(
                [Str::of('start')],
                [Str::of('received')],
                [Str::of('received')],
            )
            ->willReturn(Either::right($connection));
        $protocol
            ->expects($this->exactly(3))
            ->method('encode')
            ->withConsecutive(
                [new ConnectionStart],
                [new MessageReceived],
                [new MessageReceived],
            )
            ->will($this->onConsecutiveCalls(
                Str::of('start'),
                Str::of('received'),
                Str::of('received'),
            ));
        $protocol
            ->expects($this->exactly(3))
            ->method('decode')
            ->with($connection)
            ->will($this->onConsecutiveCalls(
                Maybe::just(new ConnectionStartOk),
                Maybe::just($message = $this->createMock(Message::class)),
                Maybe::just($message),
            ));

        $lifecycle = ClientLifecycle::of($connection, $protocol, $clock, $heartbeat)->match(
            static fn($lifecycle) => $lifecycle,
            static fn() => null,
        );
        $called = 0;
        $callback = function($a, $b) use (&$called, $message) {
            ++$called;
            $this->assertSame($message, $a);
            $this->assertInstanceOf(Continuation::class, $b);

            return $b;
        };

        $lifecycle = $lifecycle->notify($callback)->match(
            static fn($lifecycle) => $lifecycle,
            static fn() => null,
        ); // connection start
        $this->assertInstanceOf(ClientLifecycle::class, $lifecycle);
        $lifecycle = $lifecycle->notify($callback)->match(
            static fn($lifecycle) => $lifecycle,
            static fn() => null,
        ); // message 1
        $this->assertInstanceOf(ClientLifecycle::class, $lifecycle);
        $lifecycle = $lifecycle->notify($callback)->match(
            static fn($lifecycle) => $lifecycle,
            static fn() => null,
        ); // message 2
        $this->assertInstanceOf(ClientLifecycle::class, $lifecycle);
        $this->assertSame(2, $called);
    }

    public function testDoNotNotifyWhenPendingCloseOkButNoConfirmation()
    {
        $connection = $this->createMock(Connection::class);
        $protocol = $this->createMock(Protocol::class);
        $clock = $this->createMock(Clock::class);
        $heartbeat = new Timeout(1000);
        $connection
            ->expects($this->exactly(4))
            ->method('closed')
            ->willReturn(false);
        $connection
            ->expects($this->exactly(3))
            ->method('write')
            ->withConsecutive(
                [Str::of('start')],
                [Str::of('received')],
                [Str::of('close')],
            )
            ->willReturn(Either::right($connection));
        $protocol
            ->expects($this->exactly(3))
            ->method('encode')
            ->withConsecutive(
                [new ConnectionStart],
                [new MessageReceived],
                [new ConnectionClose],
            )
            ->will($this->onConsecutiveCalls(
                Str::of('start'),
                Str::of('received'),
                Str::of('close'),
            ));
        $protocol
            ->expects($this->exactly(3))
            ->method('decode')
            ->with($connection)
            ->will($this->onConsecutiveCalls(
                Maybe::just(new ConnectionStartOk),
                Maybe::just($this->createMock(Message::class)),
                Maybe::just($this->createMock(Message::class)),
            ));

        $lifecycle = ClientLifecycle::of($connection, $protocol, $clock, $heartbeat)->match(
            static fn($lifecycle) => $lifecycle,
            static fn() => null,
        );
        $called = 0;
        $callback = static function($_, $continuation) use (&$called) {
            ++$called;

            return $continuation->close();
        };

        $lifecycle = $lifecycle->notify($callback)->match(
            static fn($lifecycle) => $lifecycle,
            static fn() => null,
        ); // connection start
        $this->assertInstanceOf(ClientLifecycle::class, $lifecycle);
        $lifecycle = $lifecycle->notify($callback)->match(
            static fn($lifecycle) => $lifecycle,
            static fn() => null,
        ); // message 1
        $this->assertInstanceOf(ClientLifecycle::class, $lifecycle);
        $lifecycle = $lifecycle->notify($callback)->match(
            static fn($lifecycle) => $lifecycle,
            static fn() => null,
        ); // message 2
        $this->assertInstanceOf(ClientLifecycle::class, $lifecycle);
        $this->assertSame(1, $called);
    }

    public function testCloseConfirmation()
    {
        $connection = $this->createMock(Connection::class);
        $protocol = $this->createMock(Protocol::class);
        $clock = $this->createMock(Clock::class);
        $heartbeat = new Timeout(1000);
        $connection
            ->expects($this->exactly(4))
            ->method('closed')
            ->willReturn(false);
        $connection
            ->expects($this->exactly(3))
            ->method('write')
            ->withConsecutive(
                [Str::of('start')],
                [Str::of('received')],
                [Str::of('close')],
            )
            ->willReturn(Either::right($connection));
        $connection
            ->expects($this->once())
            ->method('close')
            ->willReturn(Either::right(new SideEffect));
        $protocol
            ->expects($this->exactly(3))
            ->method('encode')
            ->withConsecutive(
                [new ConnectionStart],
                [new MessageReceived],
                [new ConnectionClose],
            )
            ->will($this->onConsecutiveCalls(
                Str::of('start'),
                Str::of('received'),
                Str::of('close'),
            ));
        $protocol
            ->expects($this->exactly(3))
            ->method('decode')
            ->with($connection)
            ->will($this->onConsecutiveCalls(
                Maybe::just(new ConnectionStartOk),
                Maybe::just($this->createMock(Message::class)),
                Maybe::just(new ConnectionCloseOk),
            ));

        $lifecycle = ClientLifecycle::of($connection, $protocol, $clock, $heartbeat)->match(
            static fn($lifecycle) => $lifecycle,
            static fn() => null,
        );
        $called = 0;
        $callback = static function($_, $continuation) use (&$called) {
            ++$called;

            return $continuation->close();
        };

        $lifecycle = $lifecycle->notify($callback)->match(
            static fn($lifecycle) => $lifecycle,
            static fn() => null,
        ); // connection start
        $this->assertInstanceOf(ClientLifecycle::class, $lifecycle);
        $lifecycle = $lifecycle->notify($callback)->match(
            static fn($lifecycle) => $lifecycle,
            static fn() => null,
        ); // message 1
        $this->assertInstanceOf(ClientLifecycle::class, $lifecycle);
        $lifecycle = $lifecycle->notify($callback)->match(
            static fn($lifecycle) => $lifecycle,
            static fn() => null,
        ); // connection close ok
        $this->assertNull($lifecycle);
        $this->assertSame(1, $called);
    }

    public function testCloseConfirmationEvenWhenSocketError()
    {
        $connection = $this->createMock(Connection::class);
        $protocol = $this->createMock(Protocol::class);
        $clock = $this->createMock(Clock::class);
        $heartbeat = new Timeout(1000);
        $connection
            ->expects($this->exactly(4))
            ->method('closed')
            ->willReturn(false);
        $connection
            ->expects($this->once())
            ->method('close')
            ->willReturn(Either::left(new FailedToCloseStream));
        $connection
            ->expects($this->atLeast(2))
            ->method('write')
            ->withConsecutive([Str::of('start')], [Str::of('received')])
            ->willReturn(Either::right($connection));
        $protocol
            ->expects($this->exactly(3))
            ->method('encode')
            ->withConsecutive(
                [new ConnectionStart],
                [new MessageReceived],
                [new ConnectionClose],
            )
            ->will($this->onConsecutiveCalls(
                Str::of('start'),
                Str::of('received'),
                Str::of('close'),
            ));
        $protocol
            ->expects($this->exactly(3))
            ->method('decode')
            ->with($connection)
            ->will($this->onConsecutiveCalls(
                Maybe::just(new ConnectionStartOk),
                Maybe::just($this->createMock(Message::class)),
                Maybe::just(new ConnectionCloseOk),
            ));

        $lifecycle = ClientLifecycle::of($connection, $protocol, $clock, $heartbeat)->match(
            static fn($lifecycle) => $lifecycle,
            static fn() => null,
        );
        $called = 0;
        $callback = static function($_, $continuation) use (&$called) {
            ++$called;

            return $continuation->close();
        };

        $lifecycle = $lifecycle->notify($callback)->match(
            static fn($lifecycle) => $lifecycle,
            static fn() => null,
        ); // connection start
        $this->assertInstanceOf(ClientLifecycle::class, $lifecycle);
        $lifecycle = $lifecycle->notify($callback)->match(
            static fn($lifecycle) => $lifecycle,
            static fn() => null,
        ); // message 1
        $this->assertInstanceOf(ClientLifecycle::class, $lifecycle);
        $lifecycle = $lifecycle->notify($callback)->match(
            static fn($lifecycle) => $lifecycle,
            static fn() => null,
        ); // connection close ok
        $this->assertNull($lifecycle);
        $this->assertSame(1, $called);
    }

    /**
     * @dataProvider protocolMessages
     */
    public function testNeverNotifyProtocolMessages($message)
    {
        $connection = $this->createMock(Connection::class);
        $protocol = $this->createMock(Protocol::class);
        $clock = $this->createMock(Clock::class);
        $heartbeat = new Timeout(1000);
        $connection
            ->expects($this->exactly(2))
            ->method('closed')
            ->willReturn(false);
        $connection
            ->expects($this->exactly(2))
            ->method('write')
            ->withConsecutive([Str::of('start')], [Str::of('received')])
            ->willReturn(Either::right($connection));
        $protocol
            ->expects($this->exactly(2))
            ->method('encode')
            ->withConsecutive([new ConnectionStart], [new MessageReceived])
            ->will($this->onConsecutiveCalls(Str::of('start'), Str::of('received')));
        $protocol
            ->expects($this->exactly(3))
            ->method('decode')
            ->with($connection)
            ->will($this->onConsecutiveCalls(
                Maybe::just(new ConnectionStartOk),
                Maybe::just($this->createMock(Message::class)),
                Maybe::just($message),
            ));

        $lifecycle = ClientLifecycle::of($connection, $protocol, $clock, $heartbeat)->match(
            static fn($lifecycle) => $lifecycle,
            static fn() => null,
        );
        $called = 0;
        $callback = static function($a, $b) use (&$called) {
            ++$called;

            return $b;
        };

        $lifecycle = $lifecycle->notify($callback)->match(
            static fn($lifecycle) => $lifecycle,
            static fn() => null,
        ); // connection start
        $this->assertInstanceOf(ClientLifecycle::class, $lifecycle);
        $lifecycle = $lifecycle->notify($callback)->match(
            static fn($lifecycle) => $lifecycle,
            static fn() => null,
        ); // message
        $this->assertInstanceOf(ClientLifecycle::class, $lifecycle);
        $lifecycle = $lifecycle->notify($callback)->match(
            static fn($lifecycle) => $lifecycle,
            static fn() => null,
        ); // protocol message
        $this->assertInstanceOf(ClientLifecycle::class, $lifecycle);
        $this->assertSame(1, $called);
    }

    public function testShutdown()
    {
        $connection = $this->createMock(Connection::class);
        $protocol = $this->createMock(Protocol::class);
        $clock = $this->createMock(Clock::class);
        $heartbeat = new Timeout(1000);
        $connection
            ->expects($this->exactly(3))
            ->method('closed')
            ->willReturn(false);
        $connection
            ->expects($this->exactly(2))
            ->method('write')
            ->withConsecutive([Str::of('start')], [Str::of('close')])
            ->willReturn(Either::right($connection));
        $connection
            ->expects($this->once())
            ->method('close')
            ->willReturn(Either::right(new SideEffect));
        $protocol
            ->expects($this->exactly(2))
            ->method('encode')
            ->withConsecutive([new ConnectionStart], [new ConnectionClose])
            ->will($this->onConsecutiveCalls(Str::of('start'), Str::of('close')));
        $protocol
            ->expects($this->once())
            ->method('decode')
            ->with($connection)
            ->willReturn(Maybe::just(new ConnectionCloseOk));

        $lifecycle = ClientLifecycle::of($connection, $protocol, $clock, $heartbeat)->match(
            static fn($lifecycle) => $lifecycle,
            static fn() => null,
        );
        $called = false;

        $lifecycle = $lifecycle->shutdown()->match(
            static fn($lifecycle) => $lifecycle,
            static fn() => null,
        );
        $this->assertInstanceOf(ClientLifecycle::class, $lifecycle);
        $lifecycle = $lifecycle->notify(static function() use (&$called) {
            $called = true;
        })->match(
            static fn($lifecycle) => $lifecycle,
            static fn() => null,
        );
        $this->assertFalse($called);
        $this->assertNull($lifecycle);
    }

    public function testShutdownEvenWhenSocketErrorWhenClosingConnection()
    {
        $connection = $this->createMock(Connection::class);
        $protocol = $this->createMock(Protocol::class);
        $clock = $this->createMock(Clock::class);
        $heartbeat = new Timeout(1000);
        $connection
            ->expects($this->exactly(3))
            ->method('closed')
            ->willReturn(false);
        $connection
            ->expects($this->exactly(2))
            ->method('write')
            ->withConsecutive([Str::of('start')], [Str::of('close')])
            ->willReturn(Either::right($connection));
        $connection
            ->expects($this->once())
            ->method('close')
            ->willReturn(Either::left(new FailedToCloseStream));
        $protocol
            ->expects($this->exactly(2))
            ->method('encode')
            ->withConsecutive([new ConnectionStart], [new ConnectionClose])
            ->will($this->onConsecutiveCalls(Str::of('start'), Str::of('close')));
        $protocol
            ->expects($this->once())
            ->method('decode')
            ->with($connection)
            ->willReturn(Maybe::just(new ConnectionCloseOk));

        $lifecycle = ClientLifecycle::of($connection, $protocol, $clock, $heartbeat)->match(
            static fn($lifecycle) => $lifecycle,
            static fn() => null,
        );
        $called = false;

        $lifecycle = $lifecycle->shutdown()->match(
            static fn($lifecycle) => $lifecycle,
            static fn() => null,
        );
        $this->assertInstanceOf(ClientLifecycle::class, $lifecycle);
        $lifecycle = $lifecycle->notify(static function() use (&$called) {
            $called = true;
        })->match(
            static fn($lifecycle) => $lifecycle,
            static fn() => null,
        );
        $this->assertFalse($called);
        $this->assertNull($lifecycle);
    }

    public function testConsiderToBeGarbageCollectedWhenFailToClose()
    {
        $connection = $this->createMock(Connection::class);
        $protocol = $this->createMock(Protocol::class);
        $clock = $this->createMock(Clock::class);
        $heartbeat = new Timeout(1000);
        $connection
            ->expects($this->exactly(3))
            ->method('closed')
            ->willReturn(false);
        $connection
            ->expects($this->exactly(2))
            ->method('write')
            ->withConsecutive([Str::of('start')], [Str::of('close')])
            ->will($this->onConsecutiveCalls(
                $this->returnValue(Either::right($connection)),
                $this->returnValue(Either::left(new FailedToWriteToStream)),
            ));
        $protocol
            ->expects($this->exactly(2))
            ->method('encode')
            ->withConsecutive([new ConnectionStart], [new ConnectionClose])
            ->will($this->onConsecutiveCalls(
                Str::of('start'),
                Str::of('close'),
            ));

        $lifecycle = ClientLifecycle::of($connection, $protocol, $clock, $heartbeat)->match(
            static fn($lifecycle) => $lifecycle,
            static fn() => null,
        );

        $this->assertNull($lifecycle->shutdown()->match(
            static fn($lifecycle) => $lifecycle,
            static fn() => null,
        ));
    }

    public function protocolMessages(): array
    {
        return [
            [new ConnectionStart],
            [new ConnectionStartOk],
            [new ConnectionCloseOk],
            [new Heartbeat],
            [new MessageReceived],
        ];
    }
}
