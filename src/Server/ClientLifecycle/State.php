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
     * @return Either<C, Either<array{Client, self, C}, array{Client, self, C}>> Inner left side of Either means stop the server
     */
    public function actUpon(
        Client $client,
        Message $message,
        callable $notify,
        mixed $carry,
    ): Either {
        /** @var Either<C, Either<array{Client, self, C}, array{Client, self, C}>> */
        return match ($this) {
            self::pendingStartOk => Either::right(Either::right([
                $client,
                $this->ackStartOk($message),
                $carry,
            ])),
            self::awaitingMessage => $this->handleMessage(
                $client,
                $message,
                $notify,
                $carry,
            ),
            self::pendingCloseOk => $this->ackCloseOk($client, $message, $carry),
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
     * @return Either<C, Either<array{Client, self, C}, array{Client, self, C}>>
     */
    private function handleMessage(
        Client $client,
        Message $message,
        callable $notify,
        mixed $carry,
    ): Either {
        if ($message->equals(new ConnectionClose)) {
            /** @var Either<C, Either<array{Client, self, C}, array{Client, self, C}>> */
            return $client
                ->send(new ConnectionCloseOk)
                ->flatMap(static fn($client) => $client->close())
                ->either()
                ->leftMap(static fn() => $carry)
                ->flatMap(static fn() => Either::left($carry));
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
            /** @var Either<C, Either<array{Client, self, C}, array{Client, self, C}>> */
            return Either::right(Either::right([$client, $this, $carry]));
        }

        /** @var Either<C, Either<array{Client, self, C}, array{Client, self, C}>> */
        return $client
            ->send(new MessageReceived)
            ->either()
            ->leftMap(static fn() => $carry)
            ->map(static fn($client) => $notify(
                $message,
                Continuation::start($client, $carry),
            ))
            ->flatMap($this->determineNextState(...));
    }

    /**
     * @template C
     *
     * @param C $carry
     *
     * @return Either<C, Either<array{Client, self, C}, array{Client, self, C}>>
     */
    private function ackCloseOk(
        Client $client,
        Message $message,
        mixed $carry,
    ): Either {
        if ($message->equals(new ConnectionCloseOk)) {
            /** @var Either<C, Either<array{Client, self, C}, array{Client, self, C}>> */
            return $client
                ->close()
                ->either()
                ->leftMap(static fn() => $carry)
                ->flatMap(static fn() => Either::left($carry));
        }

        /** @var Either<C, Either<array{Client, self, C}, array{Client, self, C}>> */
        return Either::right(Either::right([$client, $this, $carry]));
    }

    /**
     * @template C
     *
     * @param Continuation<C> $continuation
     *
     * @return Either<C, Either<array{Client, self, C}, array{Client, self, C}>>
     */
    private function determineNextState(Continuation $continuation): Either
    {
        /** @var Either<C, Either<array{Client, self, C}, array{Client, self, C}>> */
        return $continuation->match(
            fn($client, $message, $carry) => $client
                ->send($message)
                ->either()
                ->map(fn($client) => Either::right([$client, $this, $carry]))
                ->leftMap(static fn(): mixed => $carry),
            static fn($client, $carry) => $client
                ->send(new ConnectionClose)
                ->either()
                ->map(static fn($client) => Either::right([$client, self::pendingCloseOk, $carry]))
                ->leftMap(static fn(): mixed => $carry),
            fn($client, $carry) => Either::right(Either::left([$client, $this, $carry])),
            fn($client, $carry) => Either::right(Either::right([$client, $this, $carry])),
        );
    }
}
