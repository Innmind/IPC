<?php
declare(strict_types = 1);

namespace Innmind\IPC\Server;

use Innmind\IPC\{
    Server,
    Protocol,
    Client,
    Message,
    Continuation,
    Exception\Stop,
    Exception\RuntimeException,
};
use Innmind\OperatingSystem\{
    Sockets,
    CurrentProcess\Signals,
};
use Innmind\Signals\Signal;
use Innmind\Socket\{
    Address\Unix as Address,
    Server as ServerSocket,
    Server\Connection,
    Exception\Exception as Socket,
};
use Innmind\Stream\{
    Watch,
    Exception\Exception as Stream,
};
use Innmind\TimeContinuum\{
    Clock,
    ElapsedPeriod,
    PointInTime,
};
use Innmind\Immutable\{
    Map,
    Set,
};

final class Unix implements Server
{
    private Sockets $sockets;
    private Protocol $protocol;
    private Clock $clock;
    private Signals $signals;
    private Address $address;
    private ElapsedPeriod $heartbeat;
    private ?ElapsedPeriod $timeout;
    /** @var Map<Connection, ClientLifecycle> */
    private Map $connections;
    /** @psalm-suppress PropertyNotSetInConstructor Property never accessed before initialization */
    private PointInTime $lastReceivedData;
    private \Closure $shutdown;
    private bool $shuttingDown = false;
    private bool $signalsRegistered = false;

    public function __construct(
        Sockets $sockets,
        Protocol $protocol,
        Clock $clock,
        Signals $signals,
        Address $address,
        ElapsedPeriod $heartbeat,
        ElapsedPeriod $timeout = null,
    ) {
        $this->sockets = $sockets;
        $this->protocol = $protocol;
        $this->clock = $clock;
        $this->signals = $signals;
        $this->address = $address;
        $this->heartbeat = $heartbeat;
        $this->timeout = $timeout;
        /** @var Map<Connection, ClientLifecycle> */
        $this->connections = Map::of();
        $this->shutdown = function(): void {
            $this->startShutdown();
        };
    }

    public function __invoke(callable $listen): void
    {
        // reset state in case the server is restarted
        $this->connections = $this->connections->clear();
        $this->shuttingDown = false;
        $this->lastReceivedData = $this->clock->now();
        $this->registerSignals();

        try {
            $this->loop($listen);
        } catch (Stop $e) {
            $this->unregisterSignals();
            // stop receiving messages
        } catch (Stream | Socket $e) {
            $this->unregisterSignals();

            throw new RuntimeException('', 0, $e);
        }
    }

    /**
     * @param callable(Message, Continuation): Continuation $listen
     */
    private function loop(callable $listen): void
    {
        $server = $this->sockets->open($this->address)->match(
            static fn($server) => $server,
            static fn() => throw new RuntimeException,
        );
        /** @psalm-suppress InvalidArgument TODO FIX */
        $watch = $this->sockets->watch($this->heartbeat)->forRead($server);

        do {
            $ready = $watch()->match(
                static fn($ready) => $ready,
                static fn() => null,
            );

            if (\is_null($ready)) {
                if ($this->shuttingDown) {
                    $this->emergencyShutdown($server);

                    return;
                }

                throw new RuntimeException;
            }

            try {
                if (!$ready->toRead()->empty()) {
                    $this->lastReceivedData = $this->clock->now();
                }

                if ($ready->toRead()->contains($server) && !$this->shuttingDown) {
                    $connection = $server
                        ->accept()
                        ->flatMap(
                            fn($connection) => ClientLifecycle::of(
                                new Client\Unix($connection, $this->protocol),
                                $this->clock,
                                $this->heartbeat,
                            )->map(
                                static fn($lifecycle) => [$connection, $lifecycle],
                            ),
                        );

                    $this->connections = $connection->match(
                        fn($pair) => ($this->connections)($pair[0], $pair[1]),
                        fn() => $this->connections,
                    );
                    $watch = $connection->match(
                        static fn($pair) => $watch->forRead($pair[0]),
                        static fn() => $watch,
                    );
                }

                /** @var Set<Connection> */
                $sockets = $ready->toRead()->remove($server);

                $this->heartbeat($sockets);

                $watch = $sockets->reduce(
                    $watch,
                    function(Watch $watch, Connection $connection) use ($listen): Watch {
                        $lifecycle = $this->connections->get($connection)->match(
                            static fn($lifecycle) => $lifecycle,
                            static fn() => throw new \LogicException,
                        );
                        $newLifecycle = $lifecycle->notify($listen);
                        $this->connections = $newLifecycle->match(
                            fn($lifecycle) => ($this->connections)($connection, $lifecycle),
                            fn() => $this->connections->remove($connection),
                        );

                        return $newLifecycle->match(
                            static fn() => $watch,
                            static fn() => $watch->unwatch($connection),
                        );
                    },
                );
            } catch (\Throwable $e) {
                if (!$e instanceof Stop) {
                    $this->emergencyShutdown($server);

                    throw $e;
                }

                $this->startShutdown();
            }

            $this->monitorTimeout();
            $this->monitorTermination($server);
        } while (true);
    }

    private function monitorTimeout(): void
    {
        if (!$this->timeout instanceof ElapsedPeriod) {
            return;
        }

        $iteration = $this->clock->now()->elapsedSince($this->lastReceivedData);

        if ($iteration->longerThan($this->timeout)) {
            // stop execution when no activity in the given period
            $this->startShutdown();
        }
    }

    private function startShutdown(): void
    {
        if ($this->shuttingDown) {
            return;
        }

        $this->shuttingDown = true;
        /** @psalm-suppress InvalidArgument Due to the empty map */
        $this->connections = $this->connections->flatMap(
            static fn($connection, $client) => $client->shutdown()->match(
                static fn($client) => Map::of([$connection, $client]), // pendingCloseOk
                static fn() => Map::of(), // can't shutdown properly, discard
            ),
        );
    }

    private function monitorTermination(ServerSocket $server): void
    {
        if (!$this->shuttingDown) {
            return;
        }

        if ($this->connections->empty()) {
            $server->close();

            throw new Stop;
        }
    }

    private function emergencyShutdown(ServerSocket $server): void
    {
        $_ = $this->connections->foreach(static function(Connection $connection): void {
            $connection->close();
        });
        $server->close();
    }

    /**
     * @param Set<Connection> $activeSockets
     */
    private function heartbeat(Set $activeSockets): void
    {
        $pinged = $this
            ->connections
            ->filter(static function(Connection $connection) use ($activeSockets): bool {
                return !$activeSockets->contains($connection);
            })
            ->map(static fn($_, $client) => $client->heartbeat());
        $this->connections = $this->connections->merge($pinged);
    }

    private function registerSignals(): void
    {
        if ($this->signalsRegistered) {
            return;
        }

        $this->signals->listen(Signal::hangup, $this->shutdown);
        $this->signals->listen(Signal::interrupt, $this->shutdown);
        $this->signals->listen(Signal::abort, $this->shutdown);
        $this->signals->listen(Signal::terminate, $this->shutdown);
        $this->signals->listen(Signal::terminalStop, $this->shutdown);
        $this->signals->listen(Signal::alarm, $this->shutdown);
        $this->signalsRegistered = true;
    }

    private function unregisterSignals(): void
    {
        $this->signals->remove($this->shutdown);
        $this->signalsRegistered = false;
    }
}
