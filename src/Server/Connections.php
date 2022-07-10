<?php
declare(strict_types = 1);

namespace Innmind\IPC\Server;

use Innmind\IPC\{
    Message,
    Continuation,
};
use Innmind\Socket\{
    Server,
    Server\Connection,
};
use Innmind\Stream\{
    Selectable,
    Readable,
    Watch,
    Watch\Ready,
};
use Innmind\Immutable\{
    Map,
    Maybe,
    Either,
    SideEffect,
};

final class Connections
{
    private Server $server;
    private Watch $watch;
    /** @var Map<Connection, ClientLifecycle> */
    private Map $connections;

    /**
     * @param Map<Connection, ClientLifecycle> $connections
     */
    private function __construct(
        Server $server,
        Watch $watch,
        Map $connections,
    ) {
        $this->server = $server;
        $this->watch = $watch;
        $this->connections = $connections;
    }

    public static function start(
        Watch $watch,
        Server $server,
    ): self {
        /** @var Map<Connection, ClientLifecycle> */
        $connections = Map::of();

        /** @psalm-suppress InvalidArgument TODO FIX */
        return new self(
            $server,
            $watch->forRead($server),
            $connections,
        );
    }

    /**
     * @return Maybe<Connections\Active>
     */
    public function watch(): Maybe
    {
        /** @psalm-suppress ArgumentTypeCoercion */
        return ($this->watch)()->map(fn($ready) => new Connections\Active(
            $ready->toRead()->find(fn($socket) => $socket === $this->server),
            $ready->toRead()->remove($this->server),
        ));
    }

    public function add(
        Connection $connection,
        ClientLifecycle $client,
    ): self {
        return new self(
            $this->server,
            $this->watch->forRead($connection),
            ($this->connections)($connection, $client),
        );
    }

    /**
     * @param callable(Connection, ClientLifecycle): ClientLifecycle $map
     */
    public function map(callable $map): self
    {
        return new self(
            $this->server,
            $this->watch,
            $this->connections->map($map),
        );
    }

    /**
     * @param callable(Connection, ClientLifecycle): Map<Connection, ClientLifecycle> $map
     */
    public function flatMap(callable $map): self
    {
        return new self(
            $this->server,
            $this->watch,
            $this->connections->flatMap($map),
        );
    }

    /**
     * @param callable(Message, Continuation): Continuation $listen
     *
     * @return Either<self, self> Left side means the connections must be shutdown
     */
    public function notify(Connection $connection, callable $listen): Either
    {
        return $this
            ->connections
            ->get($connection)
            ->flatMap(fn($client) => $client->notify($listen))
            ->match(
                fn($either) => $either
                    ->map(fn($client) => new self(
                        $this->server,
                        $this->watch,
                        ($this->connections)($connection, $client),
                    ))
                    ->leftMap(fn($client) => new self(
                        $this->server,
                        $this->watch,
                        ($this->connections)($connection, $client),
                    )),
                fn() => Either::right(new self(
                    $this->server,
                    $this->watch->unwatch($connection),
                    $this->connections->remove($connection),
                )),
            );
    }

    public function close(): void
    {
        $_ = $this->connections->foreach(static fn($connection) => $connection->close());
        $this->server->close();
    }

    /**
     * @return Maybe<self> Returns nothing when it was terminated
     */
    public function terminate(): Maybe
    {
        if ($this->connections->empty()) {
            /** @var Maybe<self> */
            return $this
                ->server
                ->close()
                ->maybe()
                ->filter(static fn() => false); // in all cases the connections are no longer usable
        }

        return Maybe::just($this);
    }
}
