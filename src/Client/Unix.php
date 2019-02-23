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

final class Unix implements Client
{
    private $connection;
    private $protocol;
    private $closed = false;

    public function __construct(Connection $connection, Protocol $protocol)
    {
        $this->connection = $connection;
        $this->protocol = $protocol;
    }

    public function send(Message $message): void
    {
        if ($this->closed()) {
            return;
        }

        $this->connection->write(
            $this->protocol->encode($message)
        );
    }

    public function close(): void
    {
        if ($this->closed()) {
            return;
        }

        $this->connection->write(
            $this->protocol->encode(new ConnectionClose)
        );
        $this->closed = true;
    }

    public function closed(): bool
    {
        return $this->closed;
    }
}
