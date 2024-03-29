<?php
declare(strict_types = 1);

namespace Innmind\IPC\Client;

use Innmind\IPC\{
    Client,
    Protocol,
    Message,
};
use Innmind\Socket\Server\Connection;
use Innmind\Immutable\Maybe;

final class Unix implements Client
{
    private Connection $connection;
    private Protocol $protocol;

    public function __construct(Connection $connection, Protocol $protocol)
    {
        $this->connection = $connection;
        $this->protocol = $protocol;
    }

    public function send(Message $message): Maybe
    {
        if ($this->connection->closed()) {
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

    public function read(): Maybe
    {
        /** @var Maybe<array{Client, Message}> */
        return $this
            ->protocol
            ->decode($this->connection)
            ->map(fn($message) => [$this, $message]);
    }

    public function close(): Maybe
    {
        return $this->connection->close()->maybe();
    }
}
