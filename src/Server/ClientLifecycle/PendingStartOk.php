<?php
declare(strict_types = 1);

namespace Innmind\IPC\Server\ClientLifecycle;

use Innmind\IPC\{
    Server\ClientLifecycle,
    Message,
    Message\ConnectionStartOk,
    Exception\NoMessage,
};

final class PendingStartOk extends ClientLifecycle
{
    public function actUpon(Message $message, callable $notify): self|AwaitingMessage|Garbage
    {
        if ($message->equals(new ConnectionStartOk)) {
            return $this->awaitingMessage();
        }

        return $this;
    }

    public function toBeGarbageCollected(): bool
    {
        return false;
    }
}
