<?php
declare(strict_types = 1);

namespace Innmind\IPC\Server;

use Innmind\IPC\{
    Server,
    Protocol,
    Client,
    Message,
    Exception\NoMessage,
    Exception\Stop,
    Exception\RuntimeException,
};
use Innmind\OperatingSystem\Sockets;
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
    PointInTimeInterface,
};
use Innmind\Immutable\{
    MapInterface,
    Map,
    SetInterface,
    Set,
};

final class Unix implements Server
{
    private $sockets;
    private $protocol;
    private $clock;
    private $address;
    private $heartbeat;
    private $timeout;
    private $connectionStart;
    private $connectionStartOk;
    private $connectionClose;
    private $connectionCloseOk;
    private $connectionHeartbeat;
    private $messageReceived;
    private $pendingStartOk;
    private $clients;
    private $pendingCloseOk;
    private $lastHeartbeat;
    private $lastReceivedData;
    private $hadActivity = false;
    private $shuttingDown = false;

    public function __construct(
        Sockets $sockets,
        Protocol $protocol,
        TimeContinuumInterface $clock,
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
        $this->connectionStart = new Message\ConnectionStart;
        $this->connectionStartOk = new Message\ConnectionStartOk;
        $this->connectionClose = new Message\ConnectionClose;
        $this->connectionCloseOk = new Message\ConnectionCloseOk;
        $this->connectionHeartbeat = new Message\Heartbeat;
        $this->messageReceived = new Message\MessageReceived;
        $this->pendingStartOk = Map::of(Connection::class, Client::class);
        $this->clients = Map::of(Connection::class, Client::class);
        $this->pendingCloseOk = Set::of(Connection::class);
        $this->lastHeartbeat = Map::of(Connection::class, PointInTimeInterface::class);
    }

    /**
     * {@inheritdoc}
     */
    public function __invoke(callable $listen): void
    {
        // reset state in case the server is restarted
        $this->clients = $this->clients->clear();
        $this->pendingCloseOk = $this->pendingCloseOk->clear();
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
                    $this->pendingStartOk($connection);
                }

                $sockets = $sockets->get('read')->remove($server);

                $this->discardClosedConnections();

                $this->heartbeat($sockets);

                $select = $sockets->reduce(
                    $select,
                    function(Select $select, Connection $connection) use ($listen): Select {
                        $this->heartbeated($connection);

                        try {
                            $message = $this->protocol->decode($connection);
                        } catch (NoMessage $e) {
                            // connection closed
                            return $select->unwatch($connection);
                        }

                        $this->welcome($connection, $message);
                        $select = $this->cleanup($connection, $message, $select);

                        if ($this->closing($message)) {
                            return $this->goodbye($select, $connection);
                        }

                        if ($this->discard($connection, $message)) {
                            return $select;
                        }

                        $client = $this->clients->get($connection);
                        $client->send($this->messageReceived);
                        $listen($message, $client);

                        if ($client->closed()) {
                            $this->expectCloseOk($connection);
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

    private function pendingStartOk(Connection $connection): void
    {
        $client = new Client\Unix(
            $connection,
            $this->protocol
        );
        $this->pendingStartOk = $this->pendingStartOk->put($connection, $client);
        $client->send($this->connectionStart);
        $this->heartbeated($connection);
    }

    private function welcome(Connection $connection, Message $message): void
    {
        if (!$this->pendingStartOk->contains($connection)) {
            return;
        }

        if (!$this->connectionStartOk->equals($message)) {
            return;
        }

        $this->clients = $this->clients->put(
            $connection,
            $this->pendingStartOk->get($connection)
        );
        $this->pendingStartOk = $this->pendingStartOk->remove($connection);
    }

    private function discard(Connection $connection, Message $message): bool
    {
        return !$this->clients->contains($connection) ||
            $this->pendingCloseOk->contains($connection) ||
            $this->connectionStart->equals($message) ||
            $this->connectionStartOk->equals($message) ||
            $this->connectionClose->equals($message) ||
            $this->connectionCloseOk->equals($message) ||
            $this->connectionHeartbeat->equals($message);
    }

    private function closing(Message $message): bool
    {
        return $this->connectionClose->equals($message);
    }

    private function goodbye(Select $select, Connection $connection): Select
    {
        $this->clients->get($connection)->send($this->connectionCloseOk);
        $connection->close();
        $this->clients = $this->clients->remove($connection);

        return $select->unwatch($connection);
    }

    private function cleanup(Connection $connection, Message $message, Select $select): Select
    {
        if (!$this->pendingCloseOk->contains($connection)) {
            return $select;
        }

        if (!$this->connectionCloseOk->equals($message)) {
            return $select;
        }

        $connection->close();
        $this->pendingCloseOk = $this->pendingCloseOk->remove($connection);
        $this->clients = $this->clients->remove($connection);

        return $select->unwatch($connection);
    }

    private function expectCloseOk(Connection $connection): void
    {
        $this->pendingCloseOk = $this->pendingCloseOk->add($connection);
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
        $this->clients->foreach(function(Connection $connection, Client $client): void {
            $client->close();
            $this->expectCloseOk($connection);
        });
    }

    private function monitorTermination(ServerSocket $server): void
    {
        if (!$this->shuttingDown) {
            return;
        }

        $pending = $this->pendingCloseOk->filter(static function(Connection $connection): bool {
            return !$connection->closed();
        });

        if ($pending->empty()) {
            $server->close();

            throw new Stop;
        }
    }

    private function emergencyShutdown(ServerSocket $server): void
    {
        $this->clients->foreach(static function(Connection $connection): void {
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
            ->clients
            ->filter(static function(Connection $connection) use ($activeSockets): bool {
                return !$activeSockets->contains($connection);
            })
            ->filter(function(Connection $connection): bool {
                return $this
                    ->clock
                    ->now()
                    ->elapsedSince($this->lastHeartbeat->get($connection))
                    ->longerThan($this->heartbeat);
            })
            ->foreach(function(Connection $connection, Client $client): void {
                $client->send($this->connectionHeartbeat);
                $this->heartbeated($connection);
            });
    }

    private function discardClosedConnections(): void
    {
        $closedConnections = $this
            ->clients
            ->keys()
            ->filter(static function(Connection $connection): bool {
                return $connection->closed();
            });
        $this->clients = $closedConnections->reduce(
            $this->clients,
            static function(MapInterface $clients, Connection $connection): MapInterface {
                return $clients->remove($connection);
            }
        );
        $this->pendingStartOk = $closedConnections->reduce(
            $this->pendingStartOk,
            static function(MapInterface $clients, Connection $connection): MapInterface {
                return $clients->remove($connection);
            }
        );
        $this->pendingCloseOk = $closedConnections->reduce(
            $this->pendingCloseOk,
            static function(SetInterface $connections, Connection $connection): SetInterface {
                return $connections->remove($connection);
            }
        );
    }

    private function heartbeated(Connection $connection): void
    {
        $this->lastHeartbeat = $this->lastHeartbeat->put(
            $connection,
            $this->clock->now()
        );
    }
}
