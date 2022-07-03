<?php
declare(strict_types = 1);

namespace Innmind\IPC\Server\ClientLifecycle;

use Innmind\IPC\{
    Client,
    Continuation,
    Message,
    Message\ConnectionStart,
    Message\ConnectionStartOk,
    Message\ConnectionClose,
    Message\ConnectionCloseOk,
    Message\MessageReceived,
    Message\Heartbeat,
    Exception\MessageNotSent,
    Exception\Stop,
};
use Innmind\Immutable\Maybe;

enum State
{
    case pendingStartOk;
    case awaitingMessage;
    case pendingCloseOk;

    /**
     * @param callable(Message, Continuation): Continuation $notify
     *
     * @return Maybe<self>
     */
    public function actUpon(
        Client $client,
        Message $message,
        callable $notify,
    ): Maybe {
        return match ($this) {
            self::pendingStartOk => $this->ackStartOk($message),
            self::awaitingMessage => $this->handleMessage(
                $client,
                $message,
                $notify,
            ),
            self::pendingCloseOk => $this->ackCloseOk($client, $message),
        };
    }

    /**
     * @return Maybe<self>
     */
    private function ackStartOk(Message $message): Maybe
    {
        if ($message->equals(new ConnectionStartOk)) {
            /** @var Maybe<self> */
            return Maybe::just(self::awaitingMessage);
        }

        /** @var Maybe<self> */
        return Maybe::just($this);
    }

    /**
     * @param callable(Message, Continuation): Continuation $notify
     *
     * @return Maybe<self>
     */
    private function handleMessage(
        Client $client,
        Message $message,
        callable $notify,
    ): Maybe {
        if ($message->equals(new ConnectionClose)) {
            /** @var Maybe<self> */
            return $client
                ->send(new ConnectionCloseOk)
                ->flatMap(static fn($client) => $client->close())
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
            /** @var Maybe<self> */
            return Maybe::just($this);
        }

        $_ = $client->send(new MessageReceived)->match(
            static fn() => null,
            static fn() => throw new MessageNotSent,
        );
        $continuation = $notify($message, Continuation::start($client));

        /** @var Maybe<self> */
        return $continuation->match(
            fn($client, $message) => $client
                ->send($message)
                ->map(fn() => $this),
            static fn($client) => $client
                ->send(new ConnectionClose)
                ->map(static fn() => self::pendingCloseOk),
            static fn() => throw new Stop,
            fn() => Maybe::just($this),
        );
    }

    /**
     * @return Maybe<self>
     */
    private function ackCloseOk(Client $client, Message $message): Maybe
    {
        if ($message->equals(new ConnectionCloseOk)) {
            /** @var Maybe<self> */
            return $client
                ->close()
                ->filter(static fn() => false); // always return nothing
        }

        /** @var Maybe<self> */
        return Maybe::just($this);
    }
}
