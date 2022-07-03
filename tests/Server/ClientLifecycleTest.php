<?php
declare(strict_types = 1);

namespace Tests\Innmind\IPC\Server;

use Innmind\IPC\{
    Server\ClientLifecycle,
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
use Innmind\TimeContinuum\{
    Clock,
    ElapsedPeriod,
    Earth\ElapsedPeriod as Timeout,
    PointInTime,
};
use Innmind\Immutable\{
    SideEffect,
    Maybe,
};
use PHPUnit\Framework\TestCase;

class ClientLifecycleTest extends TestCase
{
    public function testGreetClientUponInstanciation()
    {
        $client = $this->createMock(Client::class);
        $client
            ->expects($this->once())
            ->method('send')
            ->with(new ConnectionStart)
            ->willReturn(Maybe::just($client));
        $clock = $this->createMock(Clock::class);
        $heartbeat = new Timeout(1000);

        $lifecycle = ClientLifecycle::of($client, $clock, $heartbeat)->match(
            static fn($lifecycle) => $lifecycle,
            static fn() => null,
        );

        $this->assertInstanceOf(ClientLifecycle::class, $lifecycle);
    }

    public function testDoNotSendHeartbeatWhenFewerThanHeartbeatPeriod()
    {
        $client = $this->createMock(Client::class);
        $client
            ->expects($this->once())
            ->method('send')
            ->with(new ConnectionStart)
            ->willReturn(Maybe::just($client));
        $clock = $this->createMock(Clock::class);
        $heartbeat = new Timeout(1000);
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

        $lifecycle = ClientLifecycle::of($client, $clock, $heartbeat)->match(
            static fn($lifecycle) => $lifecycle,
            static fn() => null,
        );

        $lifecycle = $lifecycle->heartbeat();
        $this->assertInstanceOf(ClientLifecycle::class, $lifecycle);
    }

    public function testSilenceFailureToHeartbeatClient()
    {
        $client = $this->createMock(Client::class);
        $client
            ->expects($this->exactly(2))
            ->method('send')
            ->withConsecutive(
                [new ConnectionStart],
                [new Heartbeat],
            )
            ->will($this->onConsecutiveCalls(
                Maybe::just($client),
                Maybe::nothing(),
            ));
        $clock = $this->createMock(Clock::class);
        $heartbeat = new Timeout(1000);
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

        $lifecycle = ClientLifecycle::of($client, $clock, $heartbeat)->match(
            static fn($lifecycle) => $lifecycle,
            static fn() => null,
        );

        $lifecycle = $lifecycle->heartbeat();
        $this->assertInstanceOf(ClientLifecycle::class, $lifecycle);
    }

    public function testSendHeartbeatWhenLongerThanHeartbeatPeriod()
    {
        $client = $this->createMock(Client::class);
        $client
            ->expects($this->exactly(2))
            ->method('send')
            ->withConsecutive(
                [new ConnectionStart],
                [new Heartbeat],
            )
            ->will($this->onConsecutiveCalls(
                Maybe::just($client),
                Maybe::just($client),
            ));
        $clock = $this->createMock(Clock::class);
        $heartbeat = new Timeout(1000);
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

        $lifecycle = ClientLifecycle::of($client, $clock, $heartbeat)->match(
            static fn($lifecycle) => $lifecycle,
            static fn() => null,
        );

        $lifecycle = $lifecycle->heartbeat();
        $this->assertInstanceOf(ClientLifecycle::class, $lifecycle);
    }

    public function testConsiderGarbageWhenReadingButNoMessage()
    {
        $client = $this->createMock(Client::class);
        $client
            ->expects($this->once())
            ->method('send')
            ->with(new ConnectionStart)
            ->willReturn(Maybe::just($client));
        $client
            ->expects($this->once())
            ->method('read')
            ->willReturn(Maybe::nothing());
        $clock = $this->createMock(Clock::class);
        $heartbeat = new Timeout(1000);

        $lifecycle = ClientLifecycle::of($client, $clock, $heartbeat)->match(
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
        $client = $this->createMock(Client::class);
        $client
            ->expects($this->once())
            ->method('send')
            ->with(new ConnectionStart)
            ->willReturn(Maybe::just($client));
        $client
            ->expects($this->once())
            ->method('read')
            ->willReturn(Maybe::just($this->createMock(Message::class)));
        $clock = $this->createMock(Clock::class);
        $heartbeat = new Timeout(1000);

        $lifecycle = ClientLifecycle::of($client, $clock, $heartbeat)->match(
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
        $client = $this->createMock(Client::class);
        $client
            ->expects($this->once())
            ->method('send')
            ->with(new ConnectionStart)
            ->willReturn(Maybe::just($client));
        $client
            ->expects($this->once())
            ->method('read')
            ->willReturn(Maybe::just(new Heartbeat));
        $clock = $this->createMock(Clock::class);
        $heartbeat = new Timeout(1000);

        $lifecycle = ClientLifecycle::of($client, $clock, $heartbeat)->match(
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
        $client = $this->createMock(Client::class);
        $client
            ->expects($this->exactly(2))
            ->method('send')
            ->withConsecutive(
                [new ConnectionStart],
                [new ConnectionCloseOk],
            )
            ->willReturn(Maybe::just($client));
        $client
            ->expects($this->exactly(2))
            ->method('read')
            ->will($this->onConsecutiveCalls(
                Maybe::just(new ConnectionStartOk),
                Maybe::just(new ConnectionClose),
            ));
        $client
            ->expects($this->once())
            ->method('close')
            ->willReturn(Maybe::just(new SideEffect));
        $clock = $this->createMock(Clock::class);
        $heartbeat = new Timeout(1000);

        $lifecycle = ClientLifecycle::of($client, $clock, $heartbeat)->match(
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
        $client = $this->createMock(Client::class);
        $client
            ->expects($this->exactly(3))
            ->method('send')
            ->withConsecutive(
                [new ConnectionStart],
                [new MessageReceived],
                [new MessageReceived],
            )
            ->willReturn(Maybe::just($client));
        $client
            ->expects($this->exactly(3))
            ->method('read')
            ->will($this->onConsecutiveCalls(
                Maybe::just(new ConnectionStartOk),
                Maybe::just($message = $this->createMock(Message::class)),
                Maybe::just($message),
            ));
        $clock = $this->createMock(Clock::class);
        $heartbeat = new Timeout(1000);

        $lifecycle = ClientLifecycle::of($client, $clock, $heartbeat)->match(
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
        $client = $this->createMock(Client::class);
        $client
            ->expects($this->exactly(3))
            ->method('send')
            ->withConsecutive(
                [new ConnectionStart],
                [new MessageReceived],
                [new ConnectionClose],
            )
            ->willReturn(Maybe::just($client));
        $client
            ->expects($this->exactly(3))
            ->method('read')
            ->will($this->onConsecutiveCalls(
                Maybe::just(new ConnectionStartOk),
                Maybe::just($this->createMock(Message::class)),
                Maybe::just($this->createMock(Message::class)),
            ));
        $clock = $this->createMock(Clock::class);
        $heartbeat = new Timeout(1000);

        $lifecycle = ClientLifecycle::of($client, $clock, $heartbeat)->match(
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
        $client = $this->createMock(Client::class);
        $client
            ->expects($this->once())
            ->method('close')
            ->willReturn(Maybe::just(new SideEffect));
        $client
            ->expects($this->exactly(3))
            ->method('send')
            ->withConsecutive(
                [new ConnectionStart],
                [new MessageReceived],
                [new ConnectionClose],
            )
            ->willReturn(Maybe::just($client));
        $client
            ->expects($this->exactly(3))
            ->method('read')
            ->will($this->onConsecutiveCalls(
                Maybe::just(new ConnectionStartOk),
                Maybe::just($this->createMock(Message::class)),
                Maybe::just(new ConnectionCloseOk),
            ));
        $clock = $this->createMock(Clock::class);
        $heartbeat = new Timeout(1000);

        $lifecycle = ClientLifecycle::of($client, $clock, $heartbeat)->match(
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

    public function testCloseConfirmationEvenWhenError()
    {
        $client = $this->createMock(Client::class);
        $client
            ->expects($this->exactly(3))
            ->method('send')
            ->withConsecutive(
                [new ConnectionStart],
                [new MessageReceived],
                [new ConnectionClose],
            )
            ->willReturn(Maybe::just($client));
        $client
            ->expects($this->exactly(3))
            ->method('read')
            ->will($this->onConsecutiveCalls(
                Maybe::just(new ConnectionStartOk),
                Maybe::just($this->createMock(Message::class)),
                Maybe::just(new ConnectionCloseOk),
            ));
        $client
            ->expects($this->once())
            ->method('close')
            ->willReturn(Maybe::nothing());
        $clock = $this->createMock(Clock::class);
        $heartbeat = new Timeout(1000);

        $lifecycle = ClientLifecycle::of($client, $clock, $heartbeat)->match(
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
        $client = $this->createMock(Client::class);
        $client
            ->expects($this->exactly(2))
            ->method('send')
            ->withConsecutive(
                [new ConnectionStart],
                [new MessageReceived],
            )
            ->willReturn(Maybe::just($client));
        $client
            ->expects($this->exactly(3))
            ->method('read')
            ->will($this->onConsecutiveCalls(
                Maybe::just(new ConnectionStartOk),
                Maybe::just($this->createMock(Message::class)),
                Maybe::just($message),
            ));
        $clock = $this->createMock(Clock::class);
        $heartbeat = new Timeout(1000);

        $lifecycle = ClientLifecycle::of($client, $clock, $heartbeat)->match(
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
        $client = $this->createMock(Client::class);
        $client
            ->expects($this->once())
            ->method('close')
            ->willReturn(Maybe::just(new SideEffect));
        $client
            ->expects($this->exactly(2))
            ->method('send')
            ->withConsecutive(
                [new ConnectionStart],
                [new ConnectionClose],
            )
            ->willReturn(Maybe::just($client));
        $client
            ->expects($this->once())
            ->method('read')
            ->willReturn(Maybe::just(new ConnectionCloseOk));
        $clock = $this->createMock(Clock::class);
        $heartbeat = new Timeout(1000);

        $lifecycle = ClientLifecycle::of($client, $clock, $heartbeat)->match(
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

    public function testShutdownEvenWhenErrorWhenClosingConnection()
    {
        $client = $this->createMock(Client::class);
        $client
            ->expects($this->once())
            ->method('close')
            ->willReturn(Maybe::nothing());
        $client
            ->expects($this->exactly(2))
            ->method('send')
            ->withConsecutive(
                [new ConnectionStart],
                [new ConnectionClose],
            )
            ->willReturn(Maybe::just($client));
        $client
            ->expects($this->once())
            ->method('read')
            ->willReturn(Maybe::just(new ConnectionCloseOk));
        $clock = $this->createMock(Clock::class);
        $heartbeat = new Timeout(1000);

        $lifecycle = ClientLifecycle::of($client, $clock, $heartbeat)->match(
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
        $client = $this->createMock(Client::class);
        $client
            ->expects($this->exactly(2))
            ->method('send')
            ->withConsecutive(
                [new ConnectionStart],
                [new ConnectionClose],
            )
            ->will($this->onConsecutiveCalls(
                $this->returnValue(Maybe::just($client)),
                $this->returnValue(Maybe::nothing()),
            ));
        $clock = $this->createMock(Clock::class);
        $heartbeat = new Timeout(1000);

        $lifecycle = ClientLifecycle::of($client, $clock, $heartbeat)->match(
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
