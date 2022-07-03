<?php
declare(strict_types = 1);

namespace Innmind\IPC\Server\ClientLifecycle;

use Innmind\IPC\{
    Server\ClientLifecycle,
    Message,
};

final class Garbage extends ClientLifecycle
{
    public function actUpon(Message $message, callable $notify): self
    {
        return $this;
    }

    public function toBeGarbageCollected(): bool
    {
        return true;
    }
}
