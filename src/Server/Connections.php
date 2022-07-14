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
use Innmind\Stream\Watch;
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
     * @template C
     *
     * @param callable(Message, Continuation<C>): Continuation<C> $listen
     * @param C $carry
     *
     * @return Either<array{self, C}, array{self, C}> Left side means the connections must be shutdown
     */
    public function notify(
        Connection $connection,
        callable $listen,
        mixed $carry,
    ): Either {
        return $this
            ->connections
            ->get($connection)
            ->either()
            ->flatMap(fn($client) => $client->notify($listen, $carry))
            ->match(
                fn($either) => $either
                    ->map(fn($tuple) => [new self(
                        $this->server,
                        $this->watch,
                        ($this->connections)($connection, $tuple[0]),
                    ), $tuple[1]])
                    ->leftMap(fn($tuple) => [new self(
                        $this->server,
                        $this->watch,
                        ($this->connections)($connection, $tuple[0]),
                    ), $tuple[1]]),
                fn($carry) => Either::right([new self(
                    $this->server,
                    $this->watch->unwatch($connection),
                    $this->connections->remove($connection),
                ), $carry]),
            );
    }

    /**
     * @return Maybe<SideEffect>
     */
    public function close(): Maybe
    {
        return $this
            ->connections
            ->reduce(
                Maybe::just(new SideEffect),
                static fn(Maybe $maybe, $connection) => $maybe->flatMap(
                    static fn(): Maybe => $connection
                        ->close()
                        ->maybe(),
                ),
            )
            ->otherwise(fn() => $this->server->close()->maybe()) // force close the server even if a client could not be closed
            ->flatMap(fn() => $this->server->close()->maybe());
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
