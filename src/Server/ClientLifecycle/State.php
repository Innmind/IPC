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
use Innmind\Immutable\Maybe;

enum State
{
    case pendingStartOk;
    case awaitingMessage;
    case pendingCloseOk;

    /**
     * @return Maybe<self>
     */
    public function actUpon(
        Client $client,
        Connection $connection,
        Message $message,
        callable $notify,
    ): Maybe {
        return match ($this) {
            self::pendingStartOk => $this->ackStartOk($message),
            self::awaitingMessage => $this->handleMessage(
                $client,
                $connection,
                $message,
                $notify,
            ),
            self::pendingCloseOk => $this->ackCloseOk($connection, $message),
        };
    }

    /**
     * @return Maybe<self>
     */
    private function ackStartOk(Message $message): Maybe
    {
        if ($message->equals(new ConnectionStartOk)) {
            return Maybe::just(self::awaitingMessage);
        }

        return Maybe::just($this);
    }

    /**
     * @return Maybe<self>
     */
    private function handleMessage(
        Client $client,
        Connection $connection,
        Message $message,
        callable $notify,
    ): Maybe {
        if ($message->equals(new ConnectionClose)) {
            /** @var Maybe<self> */
            return $client
                ->send(new ConnectionCloseOk)
                ->flatMap(static fn() => $connection->close()->maybe())
                ->filter(static fn() => false); // always return nothing
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
            return Maybe::just($this);
        }

        $_ = $client->send(new MessageReceived)->match(
            static fn() => null,
            static fn() => throw new MessageNotSent,
        );
        $notify($message, $client);

        if ($client->closed()) {
            return Maybe::just(self::pendingCloseOk);
        }

        return Maybe::just($this);
    }

    /**
     * @return Maybe<self>
     */
    private function ackCloseOk(Connection $connection, Message $message): Maybe
    {
        if ($message->equals(new ConnectionCloseOk)) {
            /** @var Maybe<self> */
            return $connection
                ->close()
                ->maybe()
                ->filter(static fn() => false); // always return nothing
        }

        return Maybe::just($this);
    }
}
