<?php
declare(strict_types = 1);

namespace Tests\Innmind\IPC\Server;

use Innmind\IPC\{
    Server\ClientLifecycle,
    Protocol,
    CLient,
    Message,
    Message\ConnectionStart,
    Message\ConnectionStartOk,
    Message\ConnectionClose,
    Message\ConnectionCloseOk,
    Message\MessageReceived,
    Message\Heartbeat,
    Exception\NoMessage,
    Exception\MessageNotSent,
};
use Innmind\Socket\{
    Server\Connection,
    Exception\Exception as SocketException,
};
use Innmind\TimeContinuum\{
    TimeContinuumInterface,
    ElapsedPeriodInterface,
    ElapsedPeriod,
    PointInTimeInterface,
};
use Innmind\Stream\Exception\Exception as StreamException;
use Innmind\Immutable\Str;
use PHPUnit\Framework\TestCase;

class ClientLifecycleTest extends TestCase
{
    public function testGreetClientUponInstanciation()
    {
        $connection = $this->createMock(Connection::class);
        $protocol = $this->createMock(Protocol::class);
        $clock = $this->createMock(TimeContinuumInterface::class);
        $heartbeat = new ElapsedPeriod(1000);
        $connection
            ->expects($this->at(0))
            ->method('closed')
            ->willReturn(false);
        $protocol
            ->expects($this->once())
            ->method('encode')
            ->with(new ConnectionStart)
            ->willReturn(Str::of('start'));
        $connection
            ->expects($this->at(1))
            ->method('write')
            ->with(Str::of('start'));

        $lifecycle = new ClientLifecycle($connection, $protocol, $clock, $heartbeat);

        $this->assertFalse($lifecycle->toBeGarbageCollected());
    }

    public function testDoNotSendHeartbeatWhenFewerThanHeartbeatPeriod()
    {
        $connection = $this->createMock(Connection::class);
        $protocol = $this->createMock(Protocol::class);
        $clock = $this->createMock(TimeContinuumInterface::class);
        $heartbeat = new ElapsedPeriod(1000);
        $connection
            ->expects($this->at(0))
            ->method('closed')
            ->willReturn(false);
        $protocol
            ->expects($this->once())
            ->method('encode')
            ->with(new ConnectionStart)
            ->willReturn(Str::of('start'));
        $connection
            ->expects($this->at(1))
            ->method('write')
            ->with(Str::of('start'));
        $clock
            ->expects($this->exactly(2))
            ->method('now')
            ->will($this->onConsecutiveCalls(
                $start = $this->createMock(PointInTimeInterface::class),
                $now = $this->createMock(PointInTimeInterface::class)
            ));
        $now
            ->expects($this->once())
            ->method('elapsedSince')
            ->with($start)
            ->willReturn($period = $this->createMock(ElapsedPeriodInterface::class));
        $period
            ->expects($this->once())
            ->method('longerThan')
            ->with($heartbeat)
            ->willReturn(false);

        $lifecycle = new ClientLifecycle($connection, $protocol, $clock, $heartbeat);

        $this->assertNull($lifecycle->heartbeat());
        $this->assertFalse($lifecycle->toBeGarbageCollected());
    }

    public function testDoNotSendHeartbeatWhenToBeGarbageCollected()
    {
        $connection = $this->createMock(Connection::class);
        $protocol = $this->createMock(Protocol::class);
        $clock = $this->createMock(TimeContinuumInterface::class);
        $heartbeat = new ElapsedPeriod(1000);
        $connection
            ->expects($this->at(0))
            ->method('closed')
            ->willReturn(false);
        $protocol
            ->expects($this->at(0))
            ->method('encode')
            ->with(new ConnectionStart)
            ->willReturn(Str::of('start'));
        $connection
            ->expects($this->at(1))
            ->method('write')
            ->with(Str::of('start'));
        $connection
            ->expects($this->at(2))
            ->method('closed')
            ->willReturn(false);
        $protocol
            ->expects($this->at(1))
            ->method('encode')
            ->with(new ConnectionClose)
            ->willReturn(Str::of('close'));
        $connection
            ->expects($this->at(3))
            ->method('write')
            ->with(Str::of('close'))
            ->will($this->throwException(new MessageNotSent));
        $clock
            ->expects($this->once())
            ->method('now');

        $lifecycle = new ClientLifecycle($connection, $protocol, $clock, $heartbeat);

        $lifecycle->shutdown();
        $this->assertTrue($lifecycle->toBeGarbageCollected());
        $this->assertNull($lifecycle->heartbeat());
    }

    public function testSilenceFailureToHeartbeatClient()
    {
        $connection = $this->createMock(Connection::class);
        $protocol = $this->createMock(Protocol::class);
        $clock = $this->createMock(TimeContinuumInterface::class);
        $heartbeat = new ElapsedPeriod(1000);
        $connection
            ->expects($this->at(0))
            ->method('closed')
            ->willReturn(false);
        $protocol
            ->expects($this->at(0))
            ->method('encode')
            ->with(new ConnectionStart)
            ->willReturn(Str::of('start'));
        $connection
            ->expects($this->at(1))
            ->method('write')
            ->with(Str::of('start'));
        $clock
            ->expects($this->exactly(2))
            ->method('now')
            ->will($this->onConsecutiveCalls(
                $start = $this->createMock(PointInTimeInterface::class),
                $now = $this->createMock(PointInTimeInterface::class)
            ));
        $now
            ->expects($this->once())
            ->method('elapsedSince')
            ->with($start)
            ->willReturn($period = $this->createMock(ElapsedPeriodInterface::class));
        $period
            ->expects($this->once())
            ->method('longerThan')
            ->with($heartbeat)
            ->willReturn(true);
        $connection
            ->expects($this->at(2))
            ->method('closed')
            ->willReturn(false);
        $protocol
            ->expects($this->at(1))
            ->method('encode')
            ->with(new Heartbeat)
            ->willReturn(Str::of('heartbeat'));
        $connection
            ->expects($this->at(3))
            ->method('write')
            ->with(Str::of('heartbeat'))
            ->will($this->throwException(new MessageNotSent));

        $lifecycle = new ClientLifecycle($connection, $protocol, $clock, $heartbeat);

        $this->assertNull($lifecycle->heartbeat());
        $this->assertFalse($lifecycle->toBeGarbageCollected());
    }

    public function testSendHeartbeatWhenLongerThanHeartbeatPeriod()
    {
        $connection = $this->createMock(Connection::class);
        $protocol = $this->createMock(Protocol::class);
        $clock = $this->createMock(TimeContinuumInterface::class);
        $heartbeat = new ElapsedPeriod(1000);
        $connection
            ->expects($this->at(0))
            ->method('closed')
            ->willReturn(false);
        $protocol
            ->expects($this->at(0))
            ->method('encode')
            ->with(new ConnectionStart)
            ->willReturn(Str::of('start'));
        $connection
            ->expects($this->at(1))
            ->method('write')
            ->with(Str::of('start'));
        $clock
            ->expects($this->exactly(2))
            ->method('now')
            ->will($this->onConsecutiveCalls(
                $start = $this->createMock(PointInTimeInterface::class),
                $now = $this->createMock(PointInTimeInterface::class)
            ));
        $now
            ->expects($this->once())
            ->method('elapsedSince')
            ->with($start)
            ->willReturn($period = $this->createMock(ElapsedPeriodInterface::class));
        $period
            ->expects($this->once())
            ->method('longerThan')
            ->with($heartbeat)
            ->willReturn(true);
        $connection
            ->expects($this->at(2))
            ->method('closed')
            ->willReturn(false);
        $protocol
            ->expects($this->at(1))
            ->method('encode')
            ->with(new Heartbeat)
            ->willReturn(Str::of('heartbeat'));
        $connection
            ->expects($this->at(3))
            ->method('write')
            ->with(Str::of('heartbeat'));

        $lifecycle = new ClientLifecycle($connection, $protocol, $clock, $heartbeat);

        $this->assertNull($lifecycle->heartbeat());
        $this->assertFalse($lifecycle->toBeGarbageCollected());
    }

    public function testConsiderGarbageWhenReadingBuNoMessage()
    {
        $connection = $this->createMock(Connection::class);
        $protocol = $this->createMock(Protocol::class);
        $clock = $this->createMock(TimeContinuumInterface::class);
        $heartbeat = new ElapsedPeriod(1000);
        $connection
            ->expects($this->at(0))
            ->method('closed')
            ->willReturn(false);
        $protocol
            ->expects($this->at(0))
            ->method('encode')
            ->with(new ConnectionStart)
            ->willReturn(Str::of('start'));
        $connection
            ->expects($this->at(1))
            ->method('write')
            ->with(Str::of('start'));
        $protocol
            ->expects($this->at(1))
            ->method('decode')
            ->with($connection)
            ->will($this->throwException(new NoMessage));

        $lifecycle = new ClientLifecycle($connection, $protocol, $clock, $heartbeat);
        $called = false;

        $this->assertNull($lifecycle->notify(function() use (&$called) {
            $called = true;
        }));
        $this->assertFalse($called);
        $this->assertTrue($lifecycle->toBeGarbageCollected());
    }

    public function testDoNotNotifyWhenNotReceivedMesageOk()
    {
        $connection = $this->createMock(Connection::class);
        $protocol = $this->createMock(Protocol::class);
        $clock = $this->createMock(TimeContinuumInterface::class);
        $heartbeat = new ElapsedPeriod(1000);
        $connection
            ->expects($this->at(0))
            ->method('closed')
            ->willReturn(false);
        $protocol
            ->expects($this->at(0))
            ->method('encode')
            ->with(new ConnectionStart)
            ->willReturn(Str::of('start'));
        $connection
            ->expects($this->at(1))
            ->method('write')
            ->with(Str::of('start'));
        $protocol
            ->expects($this->at(1))
            ->method('decode')
            ->with($connection)
            ->willReturn($this->createMock(Message::class));

        $lifecycle = new ClientLifecycle($connection, $protocol, $clock, $heartbeat);
        $called = false;

        $this->assertNull($lifecycle->notify(function() use (&$called) {
            $called = true;
        }));
        $this->assertFalse($called);
        $this->assertFalse($lifecycle->toBeGarbageCollected());
    }

    public function testDoNotNotifyWhenHeartbeatMessage()
    {
        $connection = $this->createMock(Connection::class);
        $protocol = $this->createMock(Protocol::class);
        $clock = $this->createMock(TimeContinuumInterface::class);
        $heartbeat = new ElapsedPeriod(1000);
        $connection
            ->expects($this->at(0))
            ->method('closed')
            ->willReturn(false);
        $protocol
            ->expects($this->at(0))
            ->method('encode')
            ->with(new ConnectionStart)
            ->willReturn(Str::of('start'));
        $connection
            ->expects($this->at(1))
            ->method('write')
            ->with(Str::of('start'));
        $protocol
            ->expects($this->at(1))
            ->method('decode')
            ->with($connection)
            ->willReturn(new Heartbeat);

        $lifecycle = new ClientLifecycle($connection, $protocol, $clock, $heartbeat);
        $called = false;

        $this->assertNull($lifecycle->notify(function() use (&$called) {
            $called = true;
        }));
        $this->assertFalse($called);
        $this->assertFalse($lifecycle->toBeGarbageCollected());
    }

    public function testConfirmConnectionClose()
    {
        $connection = $this->createMock(Connection::class);
        $protocol = $this->createMock(Protocol::class);
        $clock = $this->createMock(TimeContinuumInterface::class);
        $heartbeat = new ElapsedPeriod(1000);
        $connection
            ->expects($this->at(0))
            ->method('closed')
            ->willReturn(false);
        $protocol
            ->expects($this->at(0))
            ->method('encode')
            ->with(new ConnectionStart)
            ->willReturn(Str::of('start'));
        $connection
            ->expects($this->at(1))
            ->method('write')
            ->with(Str::of('start'));
        $protocol
            ->expects($this->at(1))
            ->method('decode')
            ->with($connection)
            ->willReturn(new ConnectionStartOk);
        $protocol
            ->expects($this->at(2))
            ->method('decode')
            ->with($connection)
            ->willReturn(new ConnectionClose);
        $protocol
            ->expects($this->at(3))
            ->method('encode')
            ->with(new ConnectionCloseOk)
            ->willReturn(Str::of('close-ok'));
        $connection
            ->expects($this->at(2))
            ->method('closed')
            ->willReturn(false);
        $connection
            ->expects($this->at(3))
            ->method('write')
            ->with(Str::of('close-ok'));
        $connection
            ->expects($this->at(4))
            ->method('close');

        $lifecycle = new ClientLifecycle($connection, $protocol, $clock, $heartbeat);
        $called = false;
        $callback = function() use (&$called) {
            $called = true;
        };

        $this->assertNull($lifecycle->notify($callback)); // connection start
        $this->assertNull($lifecycle->notify($callback)); // connection close
        $this->assertFalse($called);
        $this->assertTrue($lifecycle->toBeGarbageCollected());
    }

    public function testNotify()
    {
        $connection = $this->createMock(Connection::class);
        $protocol = $this->createMock(Protocol::class);
        $clock = $this->createMock(TimeContinuumInterface::class);
        $heartbeat = new ElapsedPeriod(1000);
        $connection
            ->expects($this->at(0))
            ->method('closed')
            ->willReturn(false);
        $protocol
            ->expects($this->at(0))
            ->method('encode')
            ->with(new ConnectionStart)
            ->willReturn(Str::of('start'));
        $connection
            ->expects($this->at(1))
            ->method('write')
            ->with(Str::of('start'));
        $protocol
            ->expects($this->at(1))
            ->method('decode')
            ->with($connection)
            ->willReturn(new ConnectionStartOk);
        $protocol
            ->expects($this->at(2))
            ->method('decode')
            ->with($connection)
            ->willReturn($message = $this->createMock(Message::class));
        $protocol
            ->expects($this->at(3))
            ->method('encode')
            ->with(new MessageReceived)
            ->willReturn(Str::of('received'));
        $connection
            ->expects($this->at(2))
            ->method('closed')
            ->willReturn(false);
        $connection
            ->expects($this->at(3))
            ->method('write')
            ->with(Str::of('received'));
        $connection
            ->expects($this->at(4))
            ->method('closed')
            ->willReturn(false);
        $protocol
            ->expects($this->at(4))
            ->method('decode')
            ->with($connection)
            ->willReturn($message);
        $protocol
            ->expects($this->at(5))
            ->method('encode')
            ->with(new MessageReceived)
            ->willReturn(Str::of('received'));
        $connection
            ->expects($this->at(5))
            ->method('closed')
            ->willReturn(false);
        $connection
            ->expects($this->at(6))
            ->method('write')
            ->with(Str::of('received'));

        $lifecycle = new ClientLifecycle($connection, $protocol, $clock, $heartbeat);
        $called = 0;
        $callback = function($a, $b) use (&$called, $message) {
            ++$called;
            $this->assertSame($message, $a);
            $this->assertInstanceOf(Client::class, $b);
        };

        $this->assertNull($lifecycle->notify($callback)); // connection start
        $this->assertNull($lifecycle->notify($callback)); // message 1
        $this->assertNull($lifecycle->notify($callback)); // message 2
        $this->assertSame(2, $called);
        $this->assertFalse($lifecycle->toBeGarbageCollected());
    }

    public function testDoNotNotifyWhenPendingCloseOkButNoConfirmation()
    {
        $connection = $this->createMock(Connection::class);
        $protocol = $this->createMock(Protocol::class);
        $clock = $this->createMock(TimeContinuumInterface::class);
        $heartbeat = new ElapsedPeriod(1000);
        $connection
            ->expects($this->at(0))
            ->method('closed')
            ->willReturn(false);
        $protocol
            ->expects($this->at(0))
            ->method('encode')
            ->with(new ConnectionStart)
            ->willReturn(Str::of('start'));
        $connection
            ->expects($this->at(1))
            ->method('write')
            ->with(Str::of('start'));
        $protocol
            ->expects($this->at(1))
            ->method('decode')
            ->with($connection)
            ->willReturn(new ConnectionStartOk);
        $protocol
            ->expects($this->at(2))
            ->method('decode')
            ->with($connection)
            ->willReturn($this->createMock(Message::class));
        $protocol
            ->expects($this->at(3))
            ->method('encode')
            ->with(new MessageReceived)
            ->willReturn(Str::of('received'));
        $connection
            ->expects($this->at(2))
            ->method('closed')
            ->willReturn(false);
        $connection
            ->expects($this->at(3))
            ->method('write')
            ->with(Str::of('received'));
        $protocol
            ->expects($this->at(4))
            ->method('encode')
            ->with(new ConnectionClose)
            ->willReturn(Str::of('close'));
        $protocol
            ->expects($this->at(5))
            ->method('decode')
            ->with($connection)
            ->willReturn($this->createMock(Message::class));

        $lifecycle = new ClientLifecycle($connection, $protocol, $clock, $heartbeat);
        $called = 0;
        $callback = function($_, $client) use (&$called) {
            ++$called;
            $client->close();
        };

        $this->assertNull($lifecycle->notify($callback)); // connection start
        $this->assertNull($lifecycle->notify($callback)); // message 1
        $this->assertNull($lifecycle->notify($callback)); // message 2
        $this->assertSame(1, $called);
        $this->assertFalse($lifecycle->toBeGarbageCollected());
    }

    public function testCloseConfirmation()
    {
        $connection = $this->createMock(Connection::class);
        $protocol = $this->createMock(Protocol::class);
        $clock = $this->createMock(TimeContinuumInterface::class);
        $heartbeat = new ElapsedPeriod(1000);
        $connection
            ->expects($this->at(0))
            ->method('closed')
            ->willReturn(false);
        $protocol
            ->expects($this->at(0))
            ->method('encode')
            ->with(new ConnectionStart)
            ->willReturn(Str::of('start'));
        $connection
            ->expects($this->at(1))
            ->method('write')
            ->with(Str::of('start'));
        $protocol
            ->expects($this->at(1))
            ->method('decode')
            ->with($connection)
            ->willReturn(new ConnectionStartOk);
        $protocol
            ->expects($this->at(2))
            ->method('decode')
            ->with($connection)
            ->willReturn($this->createMock(Message::class));
        $protocol
            ->expects($this->at(3))
            ->method('encode')
            ->with(new MessageReceived)
            ->willReturn(Str::of('received'));
        $connection
            ->expects($this->at(2))
            ->method('closed')
            ->willReturn(false);
        $connection
            ->expects($this->at(3))
            ->method('write')
            ->with(Str::of('received'));
        $connection
            ->expects($this->at(4))
            ->method('closed')
            ->willReturn(false);
        $protocol
            ->expects($this->at(4))
            ->method('encode')
            ->with(new ConnectionClose)
            ->willReturn(Str::of('close'));
        $protocol
            ->expects($this->at(5))
            ->method('decode')
            ->with($connection)
            ->willReturn(new ConnectionCloseOk);
        $connection
            ->expects($this->at(5))
            ->method('close');

        $lifecycle = new ClientLifecycle($connection, $protocol, $clock, $heartbeat);
        $called = 0;
        $callback = function($_, $client) use (&$called) {
            ++$called;
            $client->close();
        };

        $this->assertNull($lifecycle->notify($callback)); // connection start
        $this->assertNull($lifecycle->notify($callback)); // message 1
        $this->assertNull($lifecycle->notify($callback)); // connection close ok
        $this->assertSame(1, $called);
        $this->assertTrue($lifecycle->toBeGarbageCollected());
    }

    public function testCloseConfirmationEvenWhenStreamError()
    {
        $connection = $this->createMock(Connection::class);
        $protocol = $this->createMock(Protocol::class);
        $clock = $this->createMock(TimeContinuumInterface::class);
        $heartbeat = new ElapsedPeriod(1000);
        $connection
            ->expects($this->at(0))
            ->method('closed')
            ->willReturn(false);
        $protocol
            ->expects($this->at(0))
            ->method('encode')
            ->with(new ConnectionStart)
            ->willReturn(Str::of('start'));
        $connection
            ->expects($this->at(1))
            ->method('write')
            ->with(Str::of('start'));
        $protocol
            ->expects($this->at(1))
            ->method('decode')
            ->with($connection)
            ->willReturn(new ConnectionStartOk);
        $protocol
            ->expects($this->at(2))
            ->method('decode')
            ->with($connection)
            ->willReturn($this->createMock(Message::class));
        $protocol
            ->expects($this->at(3))
            ->method('encode')
            ->with(new MessageReceived)
            ->willReturn(Str::of('received'));
        $connection
            ->expects($this->at(2))
            ->method('closed')
            ->willReturn(false);
        $connection
            ->expects($this->at(3))
            ->method('write')
            ->with(Str::of('received'));
        $connection
            ->expects($this->at(4))
            ->method('closed')
            ->willReturn(false);
        $protocol
            ->expects($this->at(4))
            ->method('encode')
            ->with(new ConnectionClose)
            ->willReturn(Str::of('close'));
        $protocol
            ->expects($this->at(5))
            ->method('decode')
            ->with($connection)
            ->willReturn(new ConnectionCloseOk);
        $connection
            ->expects($this->at(5))
            ->method('close')
            ->will($this->throwException($this->createMock(StreamException::class)));

        $lifecycle = new ClientLifecycle($connection, $protocol, $clock, $heartbeat);
        $called = 0;
        $callback = function($_, $client) use (&$called) {
            ++$called;
            $client->close();
        };

        $this->assertNull($lifecycle->notify($callback)); // connection start
        $this->assertNull($lifecycle->notify($callback)); // message 1
        $this->assertNull($lifecycle->notify($callback)); // connection close ok
        $this->assertSame(1, $called);
        $this->assertTrue($lifecycle->toBeGarbageCollected());
    }

    public function testCloseConfirmationEvenWhenSocketError()
    {
        $connection = $this->createMock(Connection::class);
        $protocol = $this->createMock(Protocol::class);
        $clock = $this->createMock(TimeContinuumInterface::class);
        $heartbeat = new ElapsedPeriod(1000);
        $connection
            ->expects($this->at(0))
            ->method('closed')
            ->willReturn(false);
        $protocol
            ->expects($this->at(0))
            ->method('encode')
            ->with(new ConnectionStart)
            ->willReturn(Str::of('start'));
        $connection
            ->expects($this->at(1))
            ->method('write')
            ->with(Str::of('start'));
        $protocol
            ->expects($this->at(1))
            ->method('decode')
            ->with($connection)
            ->willReturn(new ConnectionStartOk);
        $protocol
            ->expects($this->at(2))
            ->method('decode')
            ->with($connection)
            ->willReturn($this->createMock(Message::class));
        $protocol
            ->expects($this->at(3))
            ->method('encode')
            ->with(new MessageReceived)
            ->willReturn(Str::of('received'));
        $connection
            ->expects($this->at(2))
            ->method('closed')
            ->willReturn(false);
        $connection
            ->expects($this->at(3))
            ->method('write')
            ->with(Str::of('received'));
        $connection
            ->expects($this->at(4))
            ->method('closed')
            ->willReturn(false);
        $protocol
            ->expects($this->at(4))
            ->method('encode')
            ->with(new ConnectionClose)
            ->willReturn(Str::of('close'));
        $protocol
            ->expects($this->at(5))
            ->method('decode')
            ->with($connection)
            ->willReturn(new ConnectionCloseOk);
        $connection
            ->expects($this->at(5))
            ->method('close')
            ->will($this->throwException($this->createMock(SocketException::class)));

        $lifecycle = new ClientLifecycle($connection, $protocol, $clock, $heartbeat);
        $called = 0;
        $callback = function($_, $client) use (&$called) {
            ++$called;
            $client->close();
        };

        $this->assertNull($lifecycle->notify($callback)); // connection start
        $this->assertNull($lifecycle->notify($callback)); // message 1
        $this->assertNull($lifecycle->notify($callback)); // connection close ok
        $this->assertSame(1, $called);
        $this->assertTrue($lifecycle->toBeGarbageCollected());
    }

    /**
     * @dataProvider protocolMessages
     */
    public function testNeverNotifyProtocolMessages($message)
    {
        $connection = $this->createMock(Connection::class);
        $protocol = $this->createMock(Protocol::class);
        $clock = $this->createMock(TimeContinuumInterface::class);
        $heartbeat = new ElapsedPeriod(1000);
        $connection
            ->expects($this->at(0))
            ->method('closed')
            ->willReturn(false);
        $protocol
            ->expects($this->at(0))
            ->method('encode')
            ->with(new ConnectionStart)
            ->willReturn(Str::of('start'));
        $connection
            ->expects($this->at(1))
            ->method('write')
            ->with(Str::of('start'));
        $protocol
            ->expects($this->at(1))
            ->method('decode')
            ->with($connection)
            ->willReturn(new ConnectionStartOk);
        $protocol
            ->expects($this->at(2))
            ->method('decode')
            ->with($connection)
            ->willReturn($this->createMock(Message::class));
        $protocol
            ->expects($this->at(3))
            ->method('encode')
            ->with(new MessageReceived)
            ->willReturn(Str::of('received'));
        $connection
            ->expects($this->at(2))
            ->method('closed')
            ->willReturn(false);
        $connection
            ->expects($this->at(3))
            ->method('write')
            ->with(Str::of('received'));
        $connection
            ->expects($this->at(4))
            ->method('closed')
            ->willReturn(false);
        $protocol
            ->expects($this->at(4))
            ->method('decode')
            ->with($connection)
            ->willReturn($message);
        $protocol
            ->expects($this->at(5))
            ->method('encode')
            ->with(new MessageReceived)
            ->willReturn(Str::of('received'));
        $connection
            ->expects($this->at(5))
            ->method('closed')
            ->willReturn(false);
        $connection
            ->expects($this->at(6))
            ->method('write')
            ->with(Str::of('received'));

        $lifecycle = new ClientLifecycle($connection, $protocol, $clock, $heartbeat);
        $called = 0;
        $callback = function($a, $b) use (&$called) {
            ++$called;
        };

        $this->assertNull($lifecycle->notify($callback)); // connection start
        $this->assertNull($lifecycle->notify($callback)); // message
        $this->assertNull($lifecycle->notify($callback)); // protocol message
        $this->assertSame(1, $called);
        $this->assertFalse($lifecycle->toBeGarbageCollected());
    }

    public function testShutdown()
    {
        $connection = $this->createMock(Connection::class);
        $protocol = $this->createMock(Protocol::class);
        $clock = $this->createMock(TimeContinuumInterface::class);
        $heartbeat = new ElapsedPeriod(1000);
        $connection
            ->expects($this->at(0))
            ->method('closed')
            ->willReturn(false);
        $protocol
            ->expects($this->at(0))
            ->method('encode')
            ->with(new ConnectionStart)
            ->willReturn(Str::of('start'));
        $connection
            ->expects($this->at(1))
            ->method('write')
            ->with(Str::of('start'));
        $connection
            ->expects($this->at(2))
            ->method('closed')
            ->willReturn(false);
        $protocol
            ->expects($this->at(1))
            ->method('encode')
            ->with(new ConnectionClose)
            ->willReturn(Str::of('close'));
        $connection
            ->expects($this->at(3))
            ->method('write')
            ->with(Str::of('close'));
        $protocol
            ->expects($this->at(2))
            ->method('decode')
            ->with($connection)
            ->willReturn(new ConnectionCloseOk);

        $lifecycle = new ClientLifecycle($connection, $protocol, $clock, $heartbeat);
        $called = false;

        $this->assertNull($lifecycle->shutdown());
        $this->assertFalse($lifecycle->toBeGarbageCollected());
        $lifecycle->notify(function() use (&$called) {
            $called = true;
        });
        $this->assertFalse($called);
        $this->assertTrue($lifecycle->toBeGarbageCollected());
    }

    public function testConsiderToBeGarbageCollectedWhenFailToClose()
    {
        $connection = $this->createMock(Connection::class);
        $protocol = $this->createMock(Protocol::class);
        $clock = $this->createMock(TimeContinuumInterface::class);
        $heartbeat = new ElapsedPeriod(1000);
        $connection
            ->expects($this->at(0))
            ->method('closed')
            ->willReturn(false);
        $protocol
            ->expects($this->at(0))
            ->method('encode')
            ->with(new ConnectionStart)
            ->willReturn(Str::of('start'));
        $connection
            ->expects($this->at(1))
            ->method('write')
            ->with(Str::of('start'));
        $connection
            ->expects($this->at(2))
            ->method('closed')
            ->willReturn(false);
        $protocol
            ->expects($this->at(1))
            ->method('encode')
            ->with(new ConnectionClose)
            ->willReturn(Str::of('close'));
        $connection
            ->expects($this->at(3))
            ->method('write')
            ->with(Str::of('close'))
            ->will($this->throwException(new MessageNotSent));

        $lifecycle = new ClientLifecycle($connection, $protocol, $clock, $heartbeat);

        $this->assertNull($lifecycle->shutdown());
        $this->assertTrue($lifecycle->toBeGarbageCollected());
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
