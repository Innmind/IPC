<?php
declare(strict_types = 1);

namespace Innmind\IPC\Client;

use Innmind\IPC\{
    Client,
    Protocol,
    Message,
    Message\ConnectionClose,
    Exception\MessageNotSent,
    Exception\RuntimeException,
};
use Innmind\Socket\{
    Server\Connection,
    Exception\Exception as Socket,
};
use Innmind\Stream\Exception\Exception as Stream;

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

        try {
            $this->connection->write(
                $this->protocol->encode($message)
            );
        } catch (Stream | Socket $e) {
            throw new MessageNotSent('', 0, $e);
        }
    }

    public function close(): void
    {
        if ($this->closed()) {
            return;
        }

        try {
            $this->connection->write(
                $this->protocol->encode(new ConnectionClose)
            );
        } catch (Stream | Socket $e) {
            throw new RuntimeException('', 0, $e);
        } finally {
            $this->closed = true;
        }
    }

    public function closed(): bool
    {
        return $this->closed || $this->connection->closed();
    }
}
