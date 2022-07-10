<?php
declare(strict_types = 1);

namespace Innmind\IPC\Server\Connections;

use Innmind\Socket\{
    Server,
    Server\Connection,
};
use Innmind\Immutable\{
    Maybe,
    Set,
};

final class Active
{
    /** @var Maybe<Server> */
    private Maybe $server;
    /** @var Set<Connection> */
    private Set $clients;

    /**
     * @param Maybe<Server> $server
     * @param Set<Connection> $clients
     */
    public function __construct(Maybe $server, Set $clients)
    {
        $this->server = $server;
        $this->clients = $clients;
    }

    public static function none(): self
    {
        /** @var Maybe<Server> */
        $server = Maybe::nothing();

        return new self($server, Set::of());
    }

    /**
     * @return Maybe<Server>
     */
    public function server(): Maybe
    {
        return $this->server;
    }

    /**
     * @return Set<Connection>
     */
    public function clients(): Set
    {
        return $this->clients;
    }
}
