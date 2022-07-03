<?php
declare(strict_types = 1);

namespace Innmind\IPC\Server\ClientLifecycle;

use Innmind\IPC\{
    Server\ClientLifecycle,
    Message,
    Message\ConnectionStart,
    Message\ConnectionStartOk,
    Message\ConnectionClose,
    Message\ConnectionCloseOk,
    Message\MessageReceived,
    Message\Heartbeat,
    Exception\NoMessage,
    Exception\MessageNotSent,
};

final class AwaitingMessage extends ClientLifecycle
{
    public function actUpon(Message $message, callable $notify): self|PendingCloseOk|Garbage
    {
        if ($message->equals(new ConnectionClose)) {
            try {
                $_ = $this->client->send(new ConnectionCloseOk)->match(
                    static fn() => null,
                    static fn() => throw new MessageNotSent,
                );
                $this->connection->close();
            } catch (MessageNotSent $e) {
                // nothing to do
            } finally {
                return $this->garbage();
            }
        }

        if (
            $message->equals(new ConnectionStart) ||
            $message->equals(new ConnectionStartOk) ||
            $message->equals(new ConnectionClose) ||
            $message->equals(new ConnectionCloseOk) ||
            $message->equals(new MessageReceived) ||
            $message->equals(new Heartbeat)
        ) {
            // never notify with a protocol message
            return $this;
        }

        $_ = $this->client->send(new MessageReceived)->match(
            static fn() => null,
            static fn() => throw new MessageNotSent,
        );
        $notify($message, $this->client);

        if ($this->client->closed()) {
            return $this->pendingCloseOk();
        }

        return $this;
    }

    public function toBeGarbageCollected(): bool
    {
        return false;
    }
}
