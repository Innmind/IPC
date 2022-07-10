<?php
declare(strict_types = 1);

namespace Innmind\IPC\Server\Unix;

use Innmind\IPC\{
    Server\Connections,
    Server\Connections\Active,
    Server\ClientLifecycle,
    Client,
    Protocol,
    Exception\RuntimeException,
    Exception\Stop,
};
use Innmind\TimeContinuum\{
    Clock,
    ElapsedPeriod,
};
use Innmind\Socket\Server;
use Innmind\Immutable\{
    Maybe,
    Map,
};

enum State
{
    case awaitingConnection;
    case shuttingDown;

    /**
     * @return Maybe<Active>
     */
    public function watch(Connections $connections): Maybe
    {
        // When awaiting connections if we encounter an error when watch the
        // sockets we return an empty Active as it may only be a hiccup or it
        // happens when the process is signaled and we enter in shutting down
        // mode. This allows to properly shutdown the sockets.
        // However when shutting down if we encounter an error we directly close
        // all the sockets (connections and server). We do this because it's
        // either the process is crashing or an error due to a signal that mess
        // with the function that watch the sockets so we simply give up and let
        // the server stop

        /** @var Maybe<Active> */
        return match ($this) {
            self::shuttingDown => $connections
                ->watch()
                ->otherwise(
                    static function() use ($connections) {
                        $connections->close();

                        /** @var Maybe<Active> */
                        return Maybe::nothing();
                    },
                ),
            self::awaitingConnection => $connections
                ->watch()
                ->otherwise(static fn() => Maybe::just(Active::none())),
        };
    }

    /**
     * @param Maybe<Server> $server
     */
    public function acceptConnection(
        Maybe $server,
        Connections $connections,
        Protocol $protocol,
        Clock $clock,
        ElapsedPeriod $heartbeat,
    ): Connections {
        return match ($this) {
            self::shuttingDown => $connections,
            self::awaitingConnection => $server
                ->flatMap(static fn($server) => $server->accept())
                ->flatMap(
                    static fn($connection) => ClientLifecycle::of(
                        new Client\Unix($connection, $protocol),
                        $clock,
                        $heartbeat,
                    )->map(static fn($lifecycle) => $connections->add(
                        $connection,
                        $lifecycle,
                    )),
                )
                ->match(
                    static fn($connections) => $connections,
                    static fn() => $connections,
                ),
        };
    }

    public function shutdown(Connections $connections): Connections
    {
        /** @psalm-suppress InvalidArgument Due to the empty map */
        return match ($this) {
            self::shuttingDown => $connections,
            self::awaitingConnection => $connections->flatMap(
                static fn($connection, $client) => $client->shutdown()->match(
                    static fn($client) => Map::of([$connection, $client]), // pendingCloseOk
                    static fn() => Map::of(), // can't shutdown properly, discard
                ),
            ),
        };
    }

    public function terminate(Connections $connections): Connections
    {
        return match ($this) {
            self::awaitingConnection => $connections,
            self::shuttingDown => $connections->terminate()->match(
                static fn($connections) => $connections,
                static fn() => throw new Stop,
            ),
        };
    }
}
