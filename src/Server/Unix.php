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
};
use Innmind\Immutable\{
    Map,
    Set,
};

final class Unix implements Server
{
    private $sockets;
    private $protocol;
    private $clock;
    private $address;
    private $selectTimeout;
    private $timeout;
    private $connectionStart;
    private $connectionStartOk;
    private $connectionClose;
    private $connectionCloseOk;
    private $clients;
    private $pendingCloseOk;
    private $hadActivity = false;
    private $shuttingDown = false;

    public function __construct(
        Sockets $sockets,
        Protocol $protocol,
        TimeContinuumInterface $clock,
        Address $address,
        ElapsedPeriod $selectTimeout,
        ElapsedPeriodInterface $timeout = null
    ) {
        $this->sockets = $sockets;
        $this->protocol = $protocol;
        $this->clock = $clock;
        $this->address = $address;
        $this->selectTimeout = $selectTimeout;
        $this->timeout = $timeout;
        $this->connectionStart = new Message\ConnectionStart;
        $this->connectionStartOk = new Message\ConnectionStartOk;
        $this->connectionClose = new Message\ConnectionClose;
        $this->connectionCloseOk = new Message\ConnectionCloseOk;
        $this->clients = Map::of(Connection::class, Client::class);
        $this->pendingCloseOk = Set::of(Connection::class);
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
        $this->startedAt = $this->clock->now();

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
        $select = (new Select($this->selectTimeout))->forRead($server);

        do {
            $sockets = $select();

            try {
                if (!$sockets->get('read')->empty()) {
                    $this->hadActivity = true;
                }

                if ($sockets->get('read')->contains($server)) {
                    $connection = $server->accept();
                    $select = $select->forRead($connection);
                }

                $select = $sockets
                    ->get('read')
                    ->remove($server)
                    ->reduce(
                        $select,
                        function(Select $select, Connection $connection) use ($listen): Select {
                            try {
                                $message = $this->protocol->decode($connection);
                            } catch (NoMessage $e) {
                                // connection closed
                                return $select->unwatch($connection);
                            }
dump($message);
                            $this->welcome($connection, $message);
                            $select = $this->cleanup($connection, $message, $select);

                            if ($this->closing($message)) {
                                return $this->goodbye($select, $connection);
                            }

                            if ($this->discard($connection, $message)) {
                                return $select;
                            }

                            $client = $this->clients->get($connection);
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

    private function welcome(Connection $connection, Message $message): void
    {
        if ($this->clients->contains($connection)) {
            return;
        }

        if (!$this->connectionStart->equals($message)) {
            return;
        }

        $client = new Client\Unix(
            $connection,
            $this->protocol
        );
        $this->clients = $this->clients->put($connection, $client);
        $client->send($this->connectionStartOk);
    }

    private function discard(Connection $connection, Message $message): bool
    {
        return !$this->clients->contains($connection) ||
            $this->pendingCloseOk->contains($connection) ||
            $this->connectionStart->equals($message) ||
            $this->connectionCloseOk->equals($message);
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
        if ($this->hadActivity) {
            return;
        }

        if (!$this->timeout instanceof ElapsedPeriodInterface) {
            return;
        }

        $iteration = $this->clock->now()->elapsedSince($this->startedAt);

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

        if ($this->pendingCloseOk->empty()) {
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
}
