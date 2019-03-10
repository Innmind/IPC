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
    Select,
    Exception\Exception as Stream,
};
use Innmind\TimeContinuum\{
    TimeContinuumInterface,
    ElapsedPeriodInterface,
    ElapsedPeriod,
};
use Innmind\Immutable\{
    Map,
    SetInterface,
};

final class Unix implements Server
{
    private $sockets;
    private $protocol;
    private $clock;
    private $address;
    private $heartbeat;
    private $timeout;
    private $connections;
    private $lastReceivedData;
    private $hadActivity = false;
    private $shuttingDown = false;

    public function __construct(
        Sockets $sockets,
        Protocol $protocol,
        TimeContinuumInterface $clock,
        Signals $signals,
        Address $address,
        ElapsedPeriod $heartbeat,
        ElapsedPeriodInterface $timeout = null
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
        $select = (new Select($this->heartbeat))->forRead($server);

        do {
            $sockets = $select();

            try {
                if (!$sockets->get('read')->empty()) {
                    $this->lastReceivedData = $this->clock->now();
                }

                if ($sockets->get('read')->contains($server) && !$this->shuttingDown) {
                    $connection = $server->accept();
                    $select = $select->forRead($connection);
                    $this->connections = $this->connections->put(
                        $connection,
                        new ClientLifecycle(
                            $connection,
                            $this->protocol,
                            $this->clock,
                            $this->heartbeat
                        )
                    );
                }

                $sockets = $sockets->get('read')->remove($server);

                $this->heartbeat($sockets);

                $select = $sockets->reduce(
                    $select,
                    function(Select $select, Connection $connection) use ($listen): Select {
                        $lifecycle = $this->connections->get($connection);
                        $lifecycle->notify($listen);

                        if ($lifecycle->toBeGarbageCollected()) {
                            $this->connections = $this->connections->remove($connection);
                            $select = $select->unwatch($connection);
                        }

                        return $select;
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
        if (!$this->timeout instanceof ElapsedPeriodInterface) {
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
     * @param SetInterface<Connection> $activeSockets
     */
    private function heartbeat(SetInterface $activeSockets): void
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
