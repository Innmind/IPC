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
};
use Innmind\Immutable\{
    Maybe,
    Either,
};

enum State
{
    case pendingStartOk;
    case awaitingMessage;
    case pendingCloseOk;

    /**
     * @template C
     *
     * @param callable(Message, Continuation<C>): Continuation<C> $notify
     * @param C $carry
     *
     * @return Maybe<Either<array{Client, self}, array{Client, self}>> Left side of Either means stop th server
     */
    public function actUpon(
        Client $client,
        Message $message,
        callable $notify,
        mixed $carry,
    ): Maybe {
        /** @var Maybe<Either<array{Client, self}, array{Client, self}>> */
        return match ($this) {
            self::pendingStartOk => Maybe::just(Either::right([
                $client,
                $this->ackStartOk($message),
            ])),
            self::awaitingMessage => $this->handleMessage(
                $client,
                $message,
                $notify,
                $carry,
            ),
            self::pendingCloseOk => $this->ackCloseOk($client, $message),
        };
    }

    private function ackStartOk(Message $message): self
    {
        if ($message->equals(new ConnectionStartOk)) {
            return self::awaitingMessage;
        }

        return $this;
    }

    /**
     * @template C
     *
     * @param callable(Message, Continuation<C>): Continuation<C> $notify
     * @param C $carry
     *
     * @return Maybe<Either<array{Client, self}, array{Client, self}>>
     */
    private function handleMessage(
        Client $client,
        Message $message,
        callable $notify,
        mixed $carry,
    ): Maybe {
        if ($message->equals(new ConnectionClose)) {
            /** @var Maybe<Either<array{Client, self}, array{Client, self}>> */
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
            /** @var Maybe<Either<array{Client, self}, array{Client, self}>> */
            return Maybe::just(Either::right([$client, $this]));
        }

        return $client
            ->send(new MessageReceived)
            ->map(static fn($client) => $notify(
                $message,
                Continuation::start($client, $carry),
            ))
            ->flatMap($this->determineNextState(...));
    }

    /**
     * @return Maybe<Either<array{Client, self}, array{Client, self}>>
     */
    private function ackCloseOk(Client $client, Message $message): Maybe
    {
        if ($message->equals(new ConnectionCloseOk)) {
            /** @var Maybe<Either<array{Client, self}, array{Client, self}>> */
            return $client
                ->close()
                ->filter(static fn() => false); // always return nothing
        }

        /** @var Maybe<Either<array{Client, self}, array{Client, self}>> */
        return Maybe::just(Either::right([$client, $this]));
    }

    /**
     * @template C
     *
     * @param Continuation<C> $continuation
     *
     * @return Maybe<Either<array{Client, self}, array{Client, self}>>
     */
    private function determineNextState(Continuation $continuation): Maybe
    {
        /** @var Maybe<Either<array{Client, self}, array{Client, self}>> */
        return $continuation->match(
            fn($client, $message) => $client
                ->send($message)
                ->map(fn($client) => Either::right([$client, $this])),
            static fn($client) => $client
                ->send(new ConnectionClose)
                ->map(static fn($client) => Either::right([$client, self::pendingCloseOk])),
            fn($client) => Maybe::just(Either::left([$client, $this])),
            fn($client) => Maybe::just(Either::right([$client, $this])),
        );
    }
}
