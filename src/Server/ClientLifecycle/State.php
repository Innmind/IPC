<?php
declare(strict_types = 1);

namespace Innmind\IPC\Server\ClientLifecycle;

use Innmind\IPC\{
    Client,
    Message,
    Message\ConnectionStart,
    Message\ConnectionStartOk,
    Message\ConnectionClose,
    Message\ConnectionCloseOk,
    Message\MessageReceived,
    Message\Heartbeat,
    Exception\MessageNotSent,
};
use Innmind\Socket\Server\Connection;

enum State
{
    case pendingStartOk;
    case awaitingMessage;
    case pendingCloseOk;
    case garbage;

    public function toBeGarbageCollected(): bool
    {
        return match ($this) {
            self::garbage => true,
            default => false,
        };
    }

    public function actUpon(
        Client $client,
        Connection $connection,
        Message $message,
        callable $notify,
    ): self {
        return match ($this) {
            self::pendingStartOk => $this->ackStartOk($message),
            self::awaitingMessage => $this->handleMessage(
                $client,
                $connection,
                $message,
                $notify,
            ),
            self::pendingCloseOk => $this->ackCloseOk($connection, $message),
            self::garbage => $this,
        };
    }

    private function ackStartOk(Message $message): self
    {
        if ($message->equals(new ConnectionStartOk)) {
            return self::awaitingMessage;
        }

        return $this;
    }

    private function handleMessage(
        Client $client,
        Connection $connection,
        Message $message,
        callable $notify,
    ): self {
        if ($message->equals(new ConnectionClose)) {
            return $client
                ->send(new ConnectionCloseOk)
                ->flatMap(static fn() => $connection->close()->maybe())
                ->match(
                    static fn() => self::garbage,
                    static fn() => self::garbage,
                );
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

        $_ = $client->send(new MessageReceived)->match(
            static fn() => null,
            static fn() => throw new MessageNotSent,
        );
        $notify($message, $client);

        if ($client->closed()) {
            return self::pendingCloseOk;
        }

        return $this;
    }

    private function ackCloseOk(Connection $connection, Message $message): self
    {
        if ($message->equals(new ConnectionCloseOk)) {
            return $connection->close()->match(
                static fn() => self::garbage,
                static fn() => self::garbage,
            );
        }

        return $this;
    }
}
