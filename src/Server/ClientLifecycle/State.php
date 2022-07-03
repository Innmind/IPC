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
     * @return Maybe<array{Client, self}>
     */
    public function actUpon(
        Client $client,
        Message $message,
        callable $notify,
    ): Maybe {
        return match ($this) {
            self::pendingStartOk => $this->ackStartOk($client, $message),
            self::awaitingMessage => $this->handleMessage(
                $client,
                $message,
                $notify,
            ),
            self::pendingCloseOk => $this->ackCloseOk($client, $message),
        };
    }

    /**
     * @return Maybe<array{Client, self}>
     */
    private function ackStartOk(Client $client, Message $message): Maybe
    {
        if ($message->equals(new ConnectionStartOk)) {
            /** @var Maybe<array{Client, self}> */
            return Maybe::just([$client, self::awaitingMessage]);
        }

        /** @var Maybe<array{Client, self}> */
        return Maybe::just([$client, $this]);
    }

    /**
     * @param callable(Message, Continuation): Continuation $notify
     *
     * @return Maybe<array{Client, self}>
     */
    private function handleMessage(
        Client $client,
        Message $message,
        callable $notify,
    ): Maybe {
        if ($message->equals(new ConnectionClose)) {
            /** @var Maybe<array{Client, self}> */
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
            /** @var Maybe<array{Client, self}> */
            return Maybe::just([$client, $this]);
        }

        return $client
            ->send(new MessageReceived)
            ->map(static fn($client) => $notify($message, Continuation::start($client)))
            ->flatMap($this->determineNextState(...));
    }

    /**
     * @return Maybe<array{Client, self}>
     */
    private function ackCloseOk(Client $client, Message $message): Maybe
    {
        if ($message->equals(new ConnectionCloseOk)) {
            /** @var Maybe<array{Client, self}> */
            return $client
                ->close()
                ->filter(static fn() => false); // always return nothing
        }

        /** @var Maybe<array{Client, self}> */
        return Maybe::just([$client, $this]);
    }

    /**
     * @return Maybe<array{Client, self}>
     */
    private function determineNextState(Continuation $continuation): Maybe
    {
        /** @var Maybe<array{Client, self}> */
        return $continuation->match(
            fn($client, $message) => $client
                ->send($message)
                ->map(fn($client) => [$client, $this]),
            static fn($client) => $client
                ->send(new ConnectionClose)
                ->map(static fn($client) => [$client, self::pendingCloseOk]),
            static fn() => throw new Stop,
            fn($client) => Maybe::just([$client, $this]),
        );
    }
}
