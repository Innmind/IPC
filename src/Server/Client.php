<?php
declare(strict_types = 1);

namespace Innmind\IPC\Server;

use Innmind\IPC\{
    Protocol,
    Message,
    Continuation,
    Server\Client\Stop,
    Pipe,
    Abort,
};
use Innmind\OperatingSystem\OperatingSystem;
use Innmind\Signals\Signal;
use Innmind\IO\Sockets\Clients\Client as Socket;
use Innmind\Immutable\{
    Sequence,
    Attempt,
    Monoid,
};

/**
 * @template T
 */
final class Client
{
    /**
     * @param Monoid<T> $monoid
     * @param \Closure(Message, Continuation<T>, T): Continuation<T> $listen
     */
    private function __construct(
        private Socket $client,
        private Protocol $protocol,
        private Monoid $monoid,
        private \Closure $listen,
        private Abort $abort,
    ) {
    }

    /**
     * @return Attempt<T>
     */
    public function __invoke(OperatingSystem $os): Attempt
    {
        $pipe = Pipe::of(
            $this->client,
            $this->protocol,
            $os->clock(),
        );
        $identity = $this->monoid->identity();
        $abort = $this->abort;
        $listen = $this->listen;

        $handshaked = $os
            ->process()
            ->signals()
            ->listen(Signal::terminate, $abort)
            ->flatMap(static fn() => $pipe->send(
                Sequence::of(Message::connectionStart()),
            ))
            ->flatMap(static fn() => $pipe->wait($abort))
            ->flatMap(static fn($message) => match ($message->equals(Message::connectionStartOk())) {
                true => Attempt::result($identity),
                false => Attempt::error(new \RuntimeException('Connection handshake failure')),
            });

        // Use an infinite sequence to iteractively wait for a message to arrive
        // If the received one is a heartbeat we return a side effect, meaning
        // we restart the loop. Otherwise we send an acknowledgement to the
        // client before handling the message.
        // Since we heartbeat when waiting for a message the ->one() call will
        // always return a message unless there's a network error. This means
        // that by default ->one() will wait forever.
        // And since we use the sink pattern on the sequence, everything will
        // stop as soon any part of the system returns an error.
        return Sequence::lazy(static function() use ($handshaked, $identity) {
            yield $handshaked;

            while (true) {
                yield $identity;
            }
        })
            ->sink($identity)
            ->attempt(
                static fn($identity, $val) => match (true) {
                    $val instanceof Attempt => $val,
                    default => $pipe
                        ->wait($abort)
                        ->flatMap(
                            static fn($message) => $pipe
                                ->send(Sequence::of(Message::ack()))
                                ->flatMap(
                                    /** @psalm-suppress MixedArgument Don't know why it loses the type */
                                    static fn() => self::handle(
                                        $listen,
                                        $pipe,
                                        $identity,
                                        $message,
                                    ),
                                ),
                        ),
                },
            )
            ->recover(
                fn($e) => match (true) {
                    $e instanceof Stop => $this
                        ->client
                        ->close()
                        ->match( // make sure to keep the user provided value
                            static fn() => Attempt::result($e->unwrap()),
                            static fn() => Attempt::result($e->unwrap()),
                        ),
                    default => $this
                        ->client
                        ->close()
                        ->match( // make sure to return the original error
                            static fn() => Attempt::error($e),
                            static fn() => Attempt::error($e),
                        ),
                },
            )
            ->eitherWay(
                static fn($value) => $os
                    ->process()
                    ->signals()
                    ->remove($abort)
                    ->map(static fn(): mixed => $value),
                static fn($e) => $os
                    ->process()
                    ->signals()
                    ->remove($abort)
                    ->flatMap(static fn() => Attempt::error($e)),
            );
    }

    /**
     * @template A
     *
     * @param Monoid<A> $monoid
     * @param \Closure(Message, Continuation<A>, A): Continuation<A> $listen
     *
     * @return self<A>
     */
    public static function of(
        Socket $client,
        Protocol $protocol,
        Monoid $monoid,
        \Closure $listen,
    ): self {
        return new self(
            $client,
            $protocol,
            $monoid,
            $listen,
            Abort::disabled(),
        );
    }

    /**
     * @template A
     *
     * @param \Closure(Message, Continuation<A>, A): Continuation<A> $listen
     * @param A $identity
     *
     * @return Attempt<A>
     */
    private static function handle(
        \Closure $listen,
        Pipe $pipe,
        mixed $identity,
        Message $message,
    ): Attempt {
        return $listen($message, Continuation::new($identity), $identity)->match(
            static fn($carry, $messages) => $pipe
                ->send($messages)
                ->map(static fn(): mixed => $carry),
            static fn($carry, $messages) => $pipe
                ->send($messages)
                ->flatMap(static fn() => Attempt::error(new Stop($carry))),
        );
    }
}
