<?php
declare(strict_types = 1);

namespace Innmind\IPC\Server;

use Innmind\IPC\{
    Server,
    Protocol,
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
    Exception\SelectFailed,
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
    private bool $hadActivity = false;
    private bool $shuttingDown = false;
    private bool $signalsRegistered = false;

    public function __construct(
        Sockets $sockets,
        Protocol $protocol,
        Clock $clock,
        Signals $signals,
        Address $address,
        ElapsedPeriod $heartbeat,
        ElapsedPeriod $timeout = null
    ) {
        $this->sockets = $sockets;
        $this->protocol = $protocol;
        $this->clock = $clock;
        $this->signals = $signals;
        $this->address = $address;
        $this->heartbeat = $heartbeat;
        $this->timeout = $timeout;
        /** @var Map<Connection, ClientLifecycle> */
        $this->connections = Map::of(Connection::class, ClientLifecycle::class);
        $this->shutdown = function(): void {
            $this->startShutdown();
        };
    }

    public function __invoke(callable $listen): void
    {
        // reset state in case the server is restarted
        $this->connections = $this->connections->clear();
        $this->hadActivity = false;
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

    private function loop(callable $listen): void
    {
        $server = $this->sockets->open($this->address);
        $watch = $this->sockets->watch($this->heartbeat)->forRead($server);

        do {
            try {
                $ready = $watch();
            } catch (SelectFailed $e) {
                if ($this->shuttingDown) {
                    $this->emergencyShutdown($server);

                    return;
                }

                throw $e;
            }

            try {
                if (!$ready->toRead()->empty()) {
                    $this->lastReceivedData = $this->clock->now();
                }

                if ($ready->toRead()->contains($server) && !$this->shuttingDown) {
                    $connection = $server->accept();
                    $watch = $watch->forRead($connection);
                    $this->connections = ($this->connections)(
                        $connection,
                        new ClientLifecycle(
                            $connection,
                            $this->protocol,
                            $this->clock,
                            $this->heartbeat,
                        ),
                    );
                }

                /** @var Set<Connection> */
                $sockets = $ready->toRead()->remove($server);

                $this->heartbeat($sockets);

                $watch = $sockets->reduce(
                    $watch,
                    function(Watch $watch, Connection $connection) use ($listen): Watch {
                        $lifecycle = $this->connections->get($connection);
                        $lifecycle->notify($listen);

                        if ($lifecycle->toBeGarbageCollected()) {
                            $this->connections = $this->connections->remove($connection);
                            $watch = $watch->unwatch($connection);
                        }

                        return $watch;
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
        $this
            ->connections
            ->values()
            ->foreach(static function(ClientLifecycle $client): void {
                $client->shutdown();
            });
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
        $this->connections->foreach(static function(Connection $connection): void {
            $connection->close();
        });
        $server->close();
    }

    /**
     * @param Set<Connection> $activeSockets
     */
    private function heartbeat(Set $activeSockets): void
    {
        $this
            ->connections
            ->filter(static function(Connection $connection) use ($activeSockets): bool {
                return !$activeSockets->contains($connection);
            })
            ->values()
            ->foreach(static function(ClientLifecycle $client): void {
                $client->heartbeat();
            });
    }

    private function registerSignals(): void
    {
        if ($this->signalsRegistered) {
            return;
        }

        $this->signals->listen(Signal::hangup(), $this->shutdown);
        $this->signals->listen(Signal::interrupt(), $this->shutdown);
        $this->signals->listen(Signal::abort(), $this->shutdown);
        $this->signals->listen(Signal::terminate(), $this->shutdown);
        $this->signals->listen(Signal::terminalStop(), $this->shutdown);
        $this->signals->listen(Signal::alarm(), $this->shutdown);
        $this->signalsRegistered = true;
    }

    private function unregisterSignals(): void
    {
        $this->signals->remove($this->shutdown);
        $this->signalsRegistered = false;
    }
}
