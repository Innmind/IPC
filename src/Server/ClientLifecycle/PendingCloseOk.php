<?php
declare(strict_types = 1);

namespace Innmind\IPC\Server\ClientLifecycle;

use Innmind\IPC\{
    Server\ClientLifecycle,
    Message,
    Message\ConnectionCloseOk,
    Exception\NoMessage,
};
use Innmind\Socket\Exception\Exception as SocketException;
use Innmind\Stream\Exception\Exception as StreamException;

final class PendingCloseOk extends ClientLifecycle
{
    public function actUpon(Message $message, callable $notify): self|Garbage
    {
        if ($message->equals(new ConnectionCloseOk)) {
            try {
                $this->connection->close();
            } catch (StreamException | SocketException $e) {
                // nothing to do
            } finally {
                return $this->garbage();
            }
        }

        return $this;
    }

    public function toBeGarbageCollected(): bool
    {
        return false;
    }
}
