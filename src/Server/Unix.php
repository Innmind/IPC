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
    private Address $address;
    private ElapsedPeriod $heartbeat;
    private ?ElapsedPeriod $timeout;
    private Map $connections;
    private PointInTime $lastReceivedData;
    private bool $hadActivity = false;
    private bool $shuttingDown = false;

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
        $this->address = $address;
        $this->heartbeat = $heartbeat;
        $this->timeout = $timeout;
        $this->connections = Map::of(Connection::class, ClientLifecycle::class);
        $this->registerSignals($signals);
    }

    /**
     * {@inheritdoc}
     */
    public function __invoke(callable $listen): void
    {
        // reset state in case the server is restarted
        $this->connections = $this->connections->clear();
        $this->hadActivity = false;
        $this->shuttingDown = false;
        $this->lastReceivedData = $this->clock->now();

        try {
            $this->loop($listen);
        } catch (Stop $e) {
            // stop receiving messages
        } catch (Stream | Socket $e) {
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
                            $this->heartbeat
                        )
                    );
                }

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
                    }
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
            ->foreach(function(ClientLifecycle $client): void {
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
            ->foreach(function(ClientLifecycle $client): void {
                $client->heartbeat();
            });
    }

    private function registerSignals(Signals $signals): void
    {
        $shutdown = function(): void {
            $this->startShutdown();
        };

        $signals->listen(Signal::hangup(), $shutdown);
        $signals->listen(Signal::interrupt(), $shutdown);
        $signals->listen(Signal::abort(), $shutdown);
        $signals->listen(Signal::terminate(), $shutdown);
        $signals->listen(Signal::terminalStop(), $shutdown);
        $signals->listen(Signal::alarm(), $shutdown);
    }
}
