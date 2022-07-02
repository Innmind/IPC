<?php
declare(strict_types = 1);

namespace Innmind\IPC\Client;

use Innmind\IPC\{
    Client,
    Protocol,
    Message,
    Message\ConnectionClose,
};
use Innmind\Socket\Server\Connection;
use Innmind\Immutable\{
    Maybe,
    SideEffect,
};

final class Unix implements Client
{
    private Connection $connection;
    private Protocol $protocol;
    private bool $closed = false;

    public function __construct(Connection $connection, Protocol $protocol)
    {
        $this->connection = $connection;
        $this->protocol = $protocol;
    }

    public function send(Message $message): Maybe
    {
        if ($this->closed()) {
            /** @var Maybe<Client> */
            return Maybe::nothing();
        }

        /** @var Maybe<Client> */
        return $this
            ->connection
            ->write($this->protocol->encode($message))
            ->maybe()
            ->map(fn() => $this);
    }

    public function close(): Maybe
    {
        if ($this->closed()) {
            return Maybe::just(new SideEffect);
        }

        try {
            return $this
                ->send(new ConnectionClose)
                ->map(static fn() => new SideEffect);
        } finally {
            $this->closed = true;
        }
    }

    public function closed(): bool
    {
        return $this->closed || $this->connection->closed();
    }
}
