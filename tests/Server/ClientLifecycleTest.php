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
            ->expects($matcher = $this->exactly(2))
            ->method('send')
            ->willReturnCallback(function($message) use ($matcher, $client) {
                match ($matcher->numberOfInvocations()) {
                    1 => $this->assertEquals(new ConnectionStart, $message),
                    2 => $this->assertEquals(new Heartbeat, $message),
                };

                return match ($matcher->numberOfInvocations()) {
                    1 => Maybe::just($client),
                    2 => Maybe::nothing(),
                };
            });
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
            ->expects($matcher = $this->exactly(2))
            ->method('send')
            ->willReturnCallback(function($message) use ($matcher, $client) {
                match ($matcher->numberOfInvocations()) {
                    1 => $this->assertEquals(new ConnectionStart, $message),
                    2 => $this->assertEquals(new Heartbeat, $message),
                };

                return Maybe::just($client);
            });
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

        [$lifecycle] = $lifecycle->notify(static function() use (&$called) {
            $called = true;
        }, null)->match(
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
            ->willReturn(Maybe::just([$client, $this->createMock(Message::class)]));
        $clock = $this->createMock(Clock::class);
        $heartbeat = new Timeout(1000);

        $lifecycle = ClientLifecycle::of($client, $clock, $heartbeat)->match(
            static fn($lifecycle) => $lifecycle,
            static fn() => null,
        );
        $called = false;

        [$lifecycle2] = $lifecycle->notify(static function() use (&$called) {
            $called = true;
        }, null)->match(
            static fn($either) => $either->match(
                static fn($lifecycle) => $lifecycle,
                static fn() => null,
            ),
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
            ->willReturn(Maybe::just([$client, new Heartbeat]));
        $clock = $this->createMock(Clock::class);
        $heartbeat = new Timeout(1000);

        $lifecycle = ClientLifecycle::of($client, $clock, $heartbeat)->match(
            static fn($lifecycle) => $lifecycle,
            static fn() => null,
        );
        $called = false;

        [$lifecycle2] = $lifecycle->notify(static function() use (&$called) {
            $called = true;
        }, null)->match(
            static fn($either) => $either->match(
                static fn($lifecycle) => $lifecycle,
                static fn() => null,
            ),
            static fn() => null,
        );
        $this->assertEquals($lifecycle2, $lifecycle);
        $this->assertFalse($called);
    }

    public function testConfirmConnectionClose()
    {
        $client = $this->createMock(Client::class);
        $client
            ->expects($matcher = $this->exactly(2))
            ->method('send')
            ->willReturnCallback(function($message) use ($matcher, $client) {
                match ($matcher->numberOfInvocations()) {
                    1 => $this->assertEquals(new ConnectionStart, $message),
                    2 => $this->assertEquals(new ConnectionCloseOk, $message),
                };

                return Maybe::just($client);
            });
        $client
            ->expects($this->exactly(2))
            ->method('read')
            ->will($this->onConsecutiveCalls(
                Maybe::just([$client, new ConnectionStartOk]),
                Maybe::just([$client, new ConnectionClose]),
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
        $callback = static function($_, $continuation) use (&$called) {
            return $continuation->continue(true);
        };

        [$lifecycle] = $lifecycle->notify($callback, null)->match(
            static fn($either) => $either->match(
                static fn($lifecycle) => $lifecycle,
                static fn() => null,
            ),
            static fn() => null,
        ); // connection start
        $this->assertInstanceOf(ClientLifecycle::class, $lifecycle);
        [$lifecycle] = $lifecycle->notify($callback, null)->match(
            static fn($either) => $either,
            static fn() => null,
        ); // connection close
        $this->assertNull($lifecycle);
        $this->assertFalse($called);
    }

    public function testNotify()
    {
        $client = $this->createMock(Client::class);
        $client
            ->expects($matcher = $this->exactly(3))
            ->method('send')
            ->willReturnCallback(function($message) use ($matcher, $client) {
                match ($matcher->numberOfInvocations()) {
                    1 => $this->assertEquals(new ConnectionStart, $message),
                    2 => $this->assertEquals(new MessageReceived, $message),
                    3 => $this->assertEquals(new MessageReceived, $message),
                };

                return Maybe::just($client);
            });
        $client
            ->expects($this->exactly(3))
            ->method('read')
            ->will($this->onConsecutiveCalls(
                Maybe::just([$client, new ConnectionStartOk]),
                Maybe::just([$client, $message = $this->createMock(Message::class)]),
                Maybe::just([$client, $message]),
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

        [$lifecycle] = $lifecycle->notify($callback, null)->match(
            static fn($either) => $either->match(
                static fn($lifecycle) => $lifecycle,
                static fn() => null,
            ),
            static fn() => null,
        ); // connection start
        $this->assertInstanceOf(ClientLifecycle::class, $lifecycle);
        [$lifecycle] = $lifecycle->notify($callback, null)->match(
            static fn($either) => $either->match(
                static fn($lifecycle) => $lifecycle,
                static fn() => null,
            ),
            static fn() => null,
        ); // message 1
        $this->assertInstanceOf(ClientLifecycle::class, $lifecycle);
        [$lifecycle] = $lifecycle->notify($callback, null)->match(
            static fn($either) => $either->match(
                static fn($lifecycle) => $lifecycle,
                static fn() => null,
            ),
            static fn() => null,
        ); // message 2
        $this->assertInstanceOf(ClientLifecycle::class, $lifecycle);
        $this->assertSame(2, $called);
    }

    public function testDoNotNotifyWhenPendingCloseOkButNoConfirmation()
    {
        $client = $this->createMock(Client::class);
        $client
            ->expects($matcher = $this->exactly(3))
            ->method('send')
            ->willReturnCallback(function($message) use ($matcher, $client) {
                match ($matcher->numberOfInvocations()) {
                    1 => $this->assertEquals(new ConnectionStart, $message),
                    2 => $this->assertEquals(new MessageReceived, $message),
                    3 => $this->assertEquals(new ConnectionClose, $message),
                };

                return Maybe::just($client);
            });
        $client
            ->expects($this->exactly(3))
            ->method('read')
            ->will($this->onConsecutiveCalls(
                Maybe::just([$client, new ConnectionStartOk]),
                Maybe::just([$client, $this->createMock(Message::class)]),
                Maybe::just([$client, $this->createMock(Message::class)]),
            ));
        $clock = $this->createMock(Clock::class);
        $heartbeat = new Timeout(1000);

        $lifecycle = ClientLifecycle::of($client, $clock, $heartbeat)->match(
            static fn($lifecycle) => $lifecycle,
            static fn() => null,
        );
        $callback = static function($_, $continuation, $called) {
            return $continuation->close(++$called);
        };

        [$lifecycle, $called] = $lifecycle->notify($callback, 0)->match(
            static fn($either) => $either->match(
                static fn($lifecycle) => $lifecycle,
                static fn() => null,
            ),
            static fn() => null,
        ); // connection start
        $this->assertInstanceOf(ClientLifecycle::class, $lifecycle);
        [$lifecycle, $called] = $lifecycle->notify($callback, $called)->match(
            static fn($either) => $either->match(
                static fn($lifecycle) => $lifecycle,
                static fn() => null,
            ),
            static fn() => null,
        ); // message 1
        $this->assertInstanceOf(ClientLifecycle::class, $lifecycle);
        [$lifecycle, $called] = $lifecycle->notify($callback, $called)->match(
            static fn($either) => $either->match(
                static fn($lifecycle) => $lifecycle,
                static fn() => null,
            ),
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
            ->expects($matcher = $this->exactly(3))
            ->method('send')
            ->willReturnCallback(function($message) use ($matcher, $client) {
                match ($matcher->numberOfInvocations()) {
                    1 => $this->assertEquals(new ConnectionStart, $message),
                    2 => $this->assertEquals(new MessageReceived, $message),
                    3 => $this->assertEquals(new ConnectionClose, $message),
                };

                return Maybe::just($client);
            });
        $client
            ->expects($this->exactly(3))
            ->method('read')
            ->will($this->onConsecutiveCalls(
                Maybe::just([$client, new ConnectionStartOk]),
                Maybe::just([$client, $this->createMock(Message::class)]),
                Maybe::just([$client, new ConnectionCloseOk]),
            ));
        $clock = $this->createMock(Clock::class);
        $heartbeat = new Timeout(1000);

        $lifecycle = ClientLifecycle::of($client, $clock, $heartbeat)->match(
            static fn($lifecycle) => $lifecycle,
            static fn() => null,
        );
        $callback = static function($_, $continuation, $called) {
            return $continuation->close(++$called);
        };

        [$lifecycle, $called] = $lifecycle->notify($callback, 0)->match(
            static fn($either) => $either->match(
                static fn($lifecycle) => $lifecycle,
                static fn() => null,
            ),
            static fn() => null,
        ); // connection start
        $this->assertInstanceOf(ClientLifecycle::class, $lifecycle);
        [$lifecycle, $called] = $lifecycle->notify($callback, $called)->match(
            static fn($either) => $either->match(
                static fn($lifecycle) => $lifecycle,
                static fn() => null,
            ),
            static fn() => null,
        ); // message 1
        $this->assertInstanceOf(ClientLifecycle::class, $lifecycle);
        [$lifecycle] = $lifecycle->notify($callback, $called)->match(
            static fn($either) => $either,
            static fn() => null,
        ); // connection close ok
        $this->assertNull($lifecycle);
        $this->assertSame(1, $called);
    }

    public function testCloseConfirmationEvenWhenError()
    {
        $client = $this->createMock(Client::class);
        $client
            ->expects($matcher = $this->exactly(3))
            ->method('send')
            ->willReturnCallback(function($message) use ($matcher, $client) {
                match ($matcher->numberOfInvocations()) {
                    1 => $this->assertEquals(new ConnectionStart, $message),
                    2 => $this->assertEquals(new MessageReceived, $message),
                    3 => $this->assertEquals(new ConnectionClose, $message),
                };

                return Maybe::just($client);
            });
        $client
            ->expects($this->exactly(3))
            ->method('read')
            ->will($this->onConsecutiveCalls(
                Maybe::just([$client, new ConnectionStartOk]),
                Maybe::just([$client, $this->createMock(Message::class)]),
                Maybe::just([$client, new ConnectionCloseOk]),
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
        $callback = static function($_, $continuation, $called) {
            return $continuation->close(++$called);
        };

        [$lifecycle, $called] = $lifecycle->notify($callback, 0)->match(
            static fn($either) => $either->match(
                static fn($lifecycle) => $lifecycle,
                static fn() => null,
            ),
            static fn() => null,
        ); // connection start
        $this->assertInstanceOf(ClientLifecycle::class, $lifecycle);
        [$lifecycle, $called] = $lifecycle->notify($callback, $called)->match(
            static fn($either) => $either->match(
                static fn($lifecycle) => $lifecycle,
                static fn() => null,
            ),
            static fn() => null,
        ); // message 1
        $this->assertInstanceOf(ClientLifecycle::class, $lifecycle);
        [$lifecycle] = $lifecycle->notify($callback, $called)->match(
            static fn($either) => $either,
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
            ->expects($matcher = $this->exactly(2))
            ->method('send')
            ->willReturnCallback(function($message) use ($matcher, $client) {
                match ($matcher->numberOfInvocations()) {
                    1 => $this->assertEquals(new ConnectionStart, $message),
                    2 => $this->assertEquals(new MessageReceived, $message),
                };

                return Maybe::just($client);
            });
        $client
            ->expects($this->exactly(3))
            ->method('read')
            ->will($this->onConsecutiveCalls(
                Maybe::just([$client, new ConnectionStartOk]),
                Maybe::just([$client, $this->createMock(Message::class)]),
                Maybe::just([$client, $message]),
            ));
        $clock = $this->createMock(Clock::class);
        $heartbeat = new Timeout(1000);

        $lifecycle = ClientLifecycle::of($client, $clock, $heartbeat)->match(
            static fn($lifecycle) => $lifecycle,
            static fn() => null,
        );
        $callback = static function($a, $b, $called) {
            return $b->continue(++$called);
        };

        [$lifecycle, $called] = $lifecycle->notify($callback, 0)->match(
            static fn($either) => $either->match(
                static fn($lifecycle) => $lifecycle,
                static fn() => null,
            ),
            static fn() => null,
        ); // connection start
        $this->assertInstanceOf(ClientLifecycle::class, $lifecycle);
        [$lifecycle, $called] = $lifecycle->notify($callback, $called)->match(
            static fn($either) => $either->match(
                static fn($lifecycle) => $lifecycle,
                static fn() => null,
            ),
            static fn() => null,
        ); // message
        $this->assertInstanceOf(ClientLifecycle::class, $lifecycle);
        [$lifecycle, $called] = $lifecycle->notify($callback, $called)->match(
            static fn($either) => $either->match(
                static fn($lifecycle) => $lifecycle,
                static fn() => null,
            ),
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
            ->expects($matcher = $this->exactly(2))
            ->method('send')
            ->willReturnCallback(function($message) use ($matcher, $client) {
                match ($matcher->numberOfInvocations()) {
                    1 => $this->assertEquals(new ConnectionStart, $message),
                    2 => $this->assertEquals(new ConnectionClose, $message),
                };

                return Maybe::just($client);
            });
        $client
            ->expects($this->once())
            ->method('read')
            ->willReturn(Maybe::just([$client, new ConnectionCloseOk]));
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
        }, null)->match(
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
            ->expects($matcher = $this->exactly(2))
            ->method('send')
            ->willReturnCallback(function($message) use ($matcher, $client) {
                match ($matcher->numberOfInvocations()) {
                    1 => $this->assertEquals(new ConnectionStart, $message),
                    2 => $this->assertEquals(new ConnectionClose, $message),
                };

                return Maybe::just($client);
            });
        $client
            ->expects($this->once())
            ->method('read')
            ->willReturn(Maybe::just([$client, new ConnectionCloseOk]));
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
        }, null)->match(
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
            ->expects($matcher = $this->exactly(2))
            ->method('send')
            ->willReturnCallback(function($message) use ($matcher, $client) {
                match ($matcher->numberOfInvocations()) {
                    1 => $this->assertEquals(new ConnectionStart, $message),
                    2 => $this->assertEquals(new ConnectionClose, $message),
                };

                return match ($matcher->numberOfInvocations()) {
                    1 => Maybe::just($client),
                    2 => Maybe::nothing(),
                };
            });
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

    public static function protocolMessages(): array
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
