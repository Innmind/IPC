<?php
declare(strict_types = 1);

namespace Tests\Innmind\IPC\Server;

use Innmind\IPC\{
    Server\Unix,
    Server,
    Protocol,
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
};
use Innmind\Socket\{
    Address\Unix as Address,
    Server as ServerSocket,
};
use Innmind\Stream\Watch;
use Innmind\Immutable\Maybe;
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

    public function testFailWhenCantOpenTheServer()
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
            ->willReturn(Maybe::nothing());

        $e = $receive(null, static function($_, $continuation) {
            return $continuation;
        })->match(
            static fn() => null,
            static fn($e) => $e,
        );

        $this->assertInstanceOf(Server\UnableToStart::class, $e);
    }

    public function testStopWhenNoActivityInGivenPeriod()
    {
        $os = Factory::build();
        @\unlink($os->status()->tmp()->toString().'/innmind/ipc/server.sock');

        $listen = new Unix(
            $os->sockets(),
            new Protocol\Binary,
            $os->clock(),
            $os->process()->signals(),
            Address::of($os->status()->tmp()->toString().'/innmind/ipc/server'),
            new Timeout(100),
            new Timeout(1000),
        );

        $this->assertNull($listen(null, static function($_, $continuation) {
            return $continuation;
        })->match(
            static fn() => null,
            static fn($e) => $e,
        ));
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
            ->expects($this->exactly(12))
            ->method('listen')
            ->withConsecutive(
                [Signal::hangup, $callback],
                [Signal::interrupt, $callback],
                [Signal::abort, $callback],
                [Signal::terminate, $callback],
                [Signal::terminalStop, $callback],
                [Signal::alarm, $callback],
                [Signal::hangup, $callback],
                [Signal::interrupt, $callback],
                [Signal::abort, $callback],
                [Signal::terminate, $callback],
                [Signal::terminalStop, $callback],
                [Signal::alarm, $callback],
            );

        try {
            $server(null, static function($_, $continuation) {
                return $continuation->stop(null);
            });
        } catch (\Exception $e) {
            $this->assertSame($expected, $e);
        }

        try {
            // check signals are not registered twice
            $server(null, static function($_, $continuation) {
                return $continuation->stop(null);
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
                ->withEnvironment('TMPDIR', $os->status()->tmp()->toString())
                ->withEnvironment('PATH', $_SERVER['PATH']),
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

        $this->assertNull($listen(null, static function($_, $continuation) {
            return $continuation->stop(null);
        })->match(
            static fn() => null,
            static fn($e) => $e,
        ));
    }

    public function testClientClose()
    {
        $os = Factory::build();
        @\unlink($os->status()->tmp()->toString().'/innmind/ipc/server.sock');
        $processes = $os->control()->processes();
        $client = $processes->execute(
            Command::foreground('php')
                ->withArgument('fixtures/long-client.php')
                ->withEnvironment('TMPDIR', $os->status()->tmp()->toString())
                ->withEnvironment('PATH', $_SERVER['PATH']),
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

        $this->assertNull($listen(null, static function($message, $continuation) {
            return $continuation->close(null);
        })->match(
            static fn() => null,
            static fn($e) => $e,
        ));
    }

    public function testBidirectionalHeartbeat()
    {
        $os = Factory::build();
        @\unlink($os->status()->tmp()->toString().'/innmind/ipc/server.sock');
        $processes = $os->control()->processes();
        $processes->execute(
            Command::foreground('php')
                ->withArgument('fixtures/long-client.php')
                ->withEnvironment('TMPDIR', $os->status()->tmp()->toString())
                ->withEnvironment('PATH', $_SERVER['PATH']),
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

        $this->assertNull($listen(null, static function($_, $continuation) {
            return $continuation;
        })->match(
            static fn() => null,
            static fn($e) => $e,
        ));
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
                ->withEnvironment('TMPDIR', $os->status()->tmp()->toString())
                ->withEnvironment('PATH', $_SERVER['PATH']),
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

        $listen(null, static function() {
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
                ->withEnvironment('TMPDIR', $os->status()->tmp()->toString())
                ->withEnvironment('PATH', $_SERVER['PATH']),
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

        $this->assertNull($listen(null, static function($_, $continuation) {
            return $continuation;
        })->match(
            static fn() => null,
            static fn($e) => $e,
        ));
        $client->wait();
        $this->assertSame('', $client->output()->toString());
    }

    public function testCarriedValueIsReturnedWhenStopped()
    {
        $os = Factory::build();
        @\unlink($os->status()->tmp()->toString().'/innmind/ipc/server.sock');
        $processes = $os->control()->processes();
        $processes->execute(
            Command::foreground('php')
                ->withArgument('fixtures/long-client-multi-message.php')
                ->withEnvironment('TMPDIR', $os->status()->tmp()->toString())
                ->withEnvironment('PATH', $_SERVER['PATH']),
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

        $carry = $listen(0, static function($_, $continuation, $carry) {
            if ($carry === 2) {
                return $continuation->stop($carry + 1);
            }

            return $continuation->continue($carry + 1);
        })->match(
            static fn($carry) => $carry,
            static fn() => null,
        );

        $this->assertSame(3, $carry);
    }
}
