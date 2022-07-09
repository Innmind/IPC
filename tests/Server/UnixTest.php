<?php
declare(strict_types = 1);

namespace Tests\Innmind\IPC\Server;

use Innmind\IPC\{
    Server\Unix,
    Server,
    Protocol,
    Exception\RuntimeException,
};
use Innmind\OperatingSystem\{
    Factory,
    Sockets,
    CurrentProcess\Signals,
};
use Innmind\Signals\Signal;
use Innmind\Server\Control\Server\Command;
use Innmind\TimeContinuum\{
    Clock,
    ElapsedPeriod,
    Earth\ElapsedPeriod as Timeout,
    PointInTime,
};
use Innmind\Socket\{
    Address\Unix as Address,
    Server as ServerSocket,
    Exception\Exception as SocketException,
};
use Innmind\Stream\{
    Watch,
    Watch\Select,
    Exception\Exception as StreamException
};
use PHPUnit\Framework\TestCase;

class UnixTest extends TestCase
{
    public function testInterface()
    {
        $this->assertInstanceOf(
            Server::class,
            new Unix(
                $this->createMock(Sockets::class),
                $this->createMock(Protocol::class),
                $this->createMock(Clock::class),
                $this->createMock(Signals::class),
                Address::of('/tmp/foo.sock'),
                new Timeout(1000),
            ),
        );
    }

    public function testWrapStreamException()
    {
        $receive = new Unix(
            $sockets = $this->createMock(Sockets::class),
            $this->createMock(Protocol::class),
            $this->createMock(Clock::class),
            $this->createMock(Signals::class),
            Address::of('/tmp/foo.sock'),
            new Timeout(1000),
        );
        $sockets
            ->expects($this->once())
            ->method('open')
            ->will($this->throwException($expected = $this->createMock(StreamException::class)));

        try {
            $receive(static function() {});

            $this->fail('it should throw');
        } catch (RuntimeException $e) {
            $this->assertSame($expected, $e->getPrevious());
        }
    }

    public function testWrapSocketException()
    {
        $receive = new Unix(
            $sockets = $this->createMock(Sockets::class),
            $this->createMock(Protocol::class),
            $this->createMock(Clock::class),
            $this->createMock(Signals::class),
            Address::of('/tmp/foo.sock'),
            new Timeout(1000),
        );
        $sockets
            ->expects($this->once())
            ->method('open')
            ->will($this->throwException($expected = $this->createMock(SocketException::class)));

        try {
            $receive(static function() {});

            $this->fail('it should throw');
        } catch (RuntimeException $e) {
            $this->assertSame($expected, $e->getPrevious());
        }
    }

    public function testStopWhenNoActivityInGivenPeriod()
    {
        $receive = new Unix(
            $sockets = $this->createMock(Sockets::class),
            $this->createMock(Protocol::class),
            $clock = $this->createMock(Clock::class),
            $this->createMock(Signals::class),
            $address = Address::of('/tmp/foo.sock'),
            $heartbeat = new Timeout(10),
            $timeout = $this->createMock(ElapsedPeriod::class),
        );
        $sockets
            ->expects($this->once())
            ->method('open')
            ->willReturn(ServerSocket\Unix::recoverable($address));
        $sockets
            ->expects($this->once())
            ->method('watch')
            ->with($heartbeat)
            ->willReturn(Select::timeoutAfter($heartbeat));
        $clock
            ->expects($this->exactly(3))
            ->method('now')
            ->will($this->onConsecutiveCalls(
                $start = $this->createMock(PointInTime::class),
                $firstIteration = $this->createMock(PointInTime::class),
                $secondIteration = $this->createMock(PointInTime::class),
            ));
        $firstIteration
            ->expects($this->once())
            ->method('elapsedSince')
            ->with($start)
            ->willReturn($duration = $this->createMock(ElapsedPeriod::class));
        $duration
            ->expects($this->once())
            ->method('longerThan')
            ->with($timeout)
            ->willReturn(false);
        $secondIteration
            ->expects($this->once())
            ->method('elapsedSince')
            ->with($start)
            ->willReturn($duration = $this->createMock(ElapsedPeriod::class));
        $duration
            ->expects($this->once())
            ->method('longerThan')
            ->with($timeout)
            ->willReturn(true);

        $this->assertNull($receive(static function() {}));
    }

    public function testInstallSignalsHandlerOnlyWhenStartingTheServer()
    {
        $signals = $this->createMock(Signals::class);

        $server = new Unix(
            $sockets = $this->createMock(Sockets::class),
            $this->createMock(Protocol::class),
            $this->createMock(Clock::class),
            $signals,
            $address = Address::of('/tmp/foo.sock'),
            $heartbeat = new Timeout(10),
            $this->createMock(ElapsedPeriod::class),
        );
        $sockets
            ->expects($this->any())
            ->method('open')
            ->willReturn(ServerSocket\Unix::recoverable($address));
        $sockets
            ->expects($this->any())
            ->method('watch')
            ->with($heartbeat)
            ->willReturn($watch = $this->createMock(Watch::class));
        $watch
            ->expects($this->any())
            ->method('forRead')
            ->will($this->returnSelf());
        $watch
            ->expects($this->any())
            ->method('__invoke')
            ->will($this->throwException($expected = new \Exception));

        $callback = $this->callback(static function($listen): bool {
            $listen();

            return true;
        });
        $signals
            ->expects($this->exactly(6))
            ->method('listen')
            ->withConsecutive(
                [Signal::hangup, $callback],
                [Signal::interrupt, $callback],
                [Signal::abort, $callback],
                [Signal::terminate, $callback],
                [Signal::terminalStop, $callback],
                [Signal::alarm, $callback],
            );

        try {
            $server(static function($_, $continuation) {
                return $continuation->stop();
            });
        } catch (\Exception $e) {
            $this->assertSame($expected, $e);
        }

        try {
            // check signals are not registered twice
            $server(static function($_, $continuation) {
                return $continuation->stop();
            });
        } catch (\Exception $e) {
            $this->assertSame($expected, $e);
        }
    }

    public function testShutdownProcess()
    {
        $os = Factory::build();
        @\unlink($os->status()->tmp()->toString().'/innmind/ipc/server.sock');
        $processes = $os->control()->processes();
        $server = $processes->execute(
            Command::foreground('php')
                ->withArgument('fixtures/long-client.php')
                ->withEnvironment('TMPDIR', $os->status()->tmp()->toString()),
        );

        $listen = new Unix(
            $os->sockets(),
            new Protocol\Binary,
            $os->clock(),
            $os->process()->signals(),
            Address::of($os->status()->tmp()->toString().'/innmind/ipc/server'),
            new Timeout(100),
            new Timeout(10000),
        );

        $this->assertNull($listen(static function($_, $continuation) {
            return $continuation->stop();
        }));
    }

    public function testClientClose()
    {
        $os = Factory::build();
        @\unlink($os->status()->tmp()->toString().'/innmind/ipc/server.sock');
        $processes = $os->control()->processes();
        $client = $processes->execute(
            Command::foreground('php')
                ->withArgument('fixtures/long-client.php')
                ->withEnvironment('TMPDIR', $os->status()->tmp()->toString()),
        );

        $listen = new Unix(
            $os->sockets(),
            new Protocol\Binary,
            $os->clock(),
            $os->process()->signals(),
            Address::of($os->status()->tmp()->toString().'/innmind/ipc/server'),
            new Timeout(100),
            new Timeout(3000),
        );

        $this->assertNull($listen(static function($message, $continuation) {
            return $continuation->close();
        }));
    }

    public function testBidirectionalHeartbeat()
    {
        $os = Factory::build();
        @\unlink($os->status()->tmp()->toString().'/innmind/ipc/server.sock');
        $processes = $os->control()->processes();
        $processes->execute(
            Command::foreground('php')
                ->withArgument('fixtures/long-client.php')
                ->withEnvironment('TMPDIR', $os->status()->tmp()->toString()),
        );

        $listen = new Unix(
            $os->sockets(),
            new Protocol\Binary,
            $os->clock(),
            $os->process()->signals(),
            Address::of($os->status()->tmp()->toString().'/innmind/ipc/server'),
            new Timeout(100),
            new Timeout(3000),
        );

        $this->assertNull($listen(static function($_, $continuation) {
            return $continuation;
        }));
        // only test coverage can show that heartbeat messages are sent
    }

    public function testEmergencyShutdown()
    {
        $os = Factory::build();
        @\unlink($os->status()->tmp()->toString().'/innmind/ipc/server.sock');
        $processes = $os->control()->processes();
        $processes->execute(
            Command::foreground('php')
                ->withArgument('fixtures/long-client.php')
                ->withEnvironment('TMPDIR', $os->status()->tmp()->toString()),
        );

        $listen = new Unix(
            $os->sockets(),
            new Protocol\Binary,
            $os->clock(),
            $os->process()->signals(),
            Address::of($os->status()->tmp()->toString().'/innmind/ipc/server'),
            new Timeout(100),
            new Timeout(3000),
        );

        $this->expectException(\Exception::class);

        $listen(static function() {
            throw new \Exception;
        });
        // only test coverage can show that show that connections are closed on
        // user exception
    }

    public function testRespondToClientClose()
    {
        $os = Factory::build();
        @\unlink($os->status()->tmp()->toString().'/innmind/ipc/server.sock');
        $processes = $os->control()->processes();
        $client = $processes->execute(
            Command::foreground('php')
                ->withArgument('fixtures/self-closing-client.php')
                ->withEnvironment('TMPDIR', $os->status()->tmp()->toString()),
        );

        $listen = new Unix(
            $os->sockets(),
            new Protocol\Binary,
            $os->clock(),
            $os->process()->signals(),
            Address::of($os->status()->tmp()->toString().'/innmind/ipc/server'),
            new Timeout(100),
            new Timeout(3000),
        );

        $this->assertNull($listen(static function($_, $continuation) {
            return $continuation;
        }));
        $client->wait();
        $this->assertSame('', $client->output()->toString());
    }
}
