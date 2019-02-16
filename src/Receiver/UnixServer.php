<?php
declare(strict_types = 1);

namespace Innmind\IPC\Receiver;

use Innmind\IPC\{
    Receiver,
    Protocol,
    Process,
    Exception\NoMessage,
    Exception\Stop,
    Exception\RuntimeException,
};
use Innmind\OperatingSystem\Sockets;
use Innmind\Socket\{
    Address\Unix as Address,
    Server,
    Server\Connection,
    Exception\Exception as Socket,
};
use Innmind\Stream\{
    Select,
    Exception\Exception as Stream,
};
use Innmind\TimeContinuum\{
    ElapsedPeriodInterface,
    ElapsedPeriod,
};
use Innmind\Immutable\Map;

final class UnixServer implements Receiver
{
    private $sockets;
    private $protocol;
    private $address;
    private $name;
    private $timeout;
    private $processes;

    public function __construct(
        Sockets $sockets,
        Protocol $protocol,
        Address $address,
        Process\Name $name,
        ElapsedPeriodInterface $timeout = null
    ) {
        $this->sockets = $sockets;
        $this->protocol = $protocol;
        $this->address = $address;
        $this->name = (string) $name;
        $this->timeout = new ElapsedPeriod(
            ($timeout ?? new ElapsedPeriod(60000))->milliseconds() // default to 1 minute
        );
        $this->processes = Map::of(Connection::class, Process\Name::class);
    }

    /**
     * {@inheritdoc}
     */
    public function __invoke(callable $listen): void
    {
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
        $select = (new Select($this->timeout))->forRead($server);

        do {
            $sockets = $select();

            try {
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
                            if (!$this->processes->contains($connection)) {
                                $this->welcome($connection);

                                return $select;
                            }

                            try {
                                $message = $this->protocol->decode($connection);
                            } catch (NoMessage $e) {
                                // connection closed
                                $connection->close();
                                $this->processes = $this->processes->remove($connection);

                                return $select->unwatch($connection);
                            }

                            $listen(
                                $message,
                                $this->processes->get($connection)
                            );

                            return $select;
                        }
                    );
            } catch (\Throwable $e) {
                $this->close($server);

                throw $e;
            }
        } while (true);
    }

    private function welcome(Connection $connection): void
    {
        $message = $this->protocol->decode($connection);
        $this->processes = $this->processes->put(
            $connection,
            new Process\Name((string) $message->content())
        );
    }

    private function close(Server $server): void
    {
        $this->processes = $this
            ->processes
            ->foreach(static function(Connection $connection): void {
                $connection->close();
            })
            ->clear();
        $server->close();
    }
}
