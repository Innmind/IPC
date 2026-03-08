<?php
declare(strict_types = 1);

namespace Innmind\IPC\Server;

use Innmind\IPC\{
    Protocol,
    Message,
    Continuation,
    Server\Client\Stop,
};
use Innmind\OperatingSystem\OperatingSystem;
use Innmind\Signals\Signal;
use Innmind\IO\Sockets\Clients\Client as Socket;
use Innmind\Immutable\{
    Sequence,
    Attempt,
    SideEffect,
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
    ) {
    }

    /**
     * @return Attempt<T>
     */
    public function __invoke(OperatingSystem $os): Attempt
    {
        $abort = false;
        $identity = $this->monoid->identity();

        $signaled = $os
            ->process()
            ->signals()
            ->listen(Signal::terminate, static function() use (&$abort) {
                $abort = true;
            })
            ->map(static fn() => $identity);
        $frame = $this->protocol->frame();
        // unwrapping is safe as it's internal messages
        $heartbeat = $this->protocol->encode(Message::heartbeat())->unwrap();
        $ack = $this->protocol->encode(Message::ack())->unwrap();

        // Use an infinite sequence to iteractively wait for a message to arrive
        // If the received one is a heartbeat we return a side effect, meaning
        // we restart the loop. Otherwise we send an acknowledgement to the
        // client before handling the message.
        // Since we heartbeat when waiting for a message the ->one() call will
        // always return a message unless there's a network error. This means
        // that by default ->one() will wait forever.
        // And since we use the sink pattern on the sequence, everything will
        // stop as soon any part of the system returns an error.
        return Sequence::lazy(static function() use ($signaled, $identity) {
            yield $signaled;

            while (true) {
                yield $identity;
            }
        })
            ->sink($identity)
            ->attempt(
                fn($identity, $val) => match (true) {
                    $val instanceof Attempt => $val,
                    default => $this
                        ->client
                        ->heartbeatWith(static fn() => Sequence::of($heartbeat))
                        ->abortWhen(static function() use (&$abort) {
                            return $abort;
                        })
                        ->frames($frame)
                        ->one()
                        ->flatMap(
                            fn($message) => match ($message->equals(Message::heartbeat())) {
                                true => Attempt::result($identity),
                                false => $this
                                    ->client
                                    ->sink(Sequence::of($ack))
                                    ->flatMap(
                                        /** @psalm-suppress MixedArgument Don't know why it loses the type */
                                        fn() => $this->handle(
                                            $identity,
                                            $message,
                                        ),
                                    ),
                            },
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
        return new self($client, $protocol, $monoid, $listen);
    }

    /**
     * @param T $identity
     *
     * @return Attempt<T>
     */
    private function handle(mixed $identity, Message $message): Attempt
    {
        /** @psalm-suppress MixedArgument Don't know why it loses the type */
        return ($this->listen)($message, Continuation::new($identity), $identity)->match(
            fn($carry, $messages) => $this->respond($carry, $messages),
            fn($carry, $messages) => $this
                ->respond($carry, $messages)
                ->flatMap(static fn($carry) => Attempt::error(new Stop($carry))),
        );
    }

    /**
     * @param T $carry
     * @param Sequence<Message> $messages
     *
     * @return Attempt<T>
     */
    private function respond(
        mixed $carry,
        Sequence $messages,
    ): Attempt {
        return $messages
            ->sink($carry)
            ->attempt(
                fn($carry, $message) => $this
                    ->protocol
                    ->encode($message)
                    ->map(Sequence::of(...))
                    ->flatMap($this->client->sink(...))
                    // todo wait for acks
                    ->map(static fn(): mixed => $carry),
            );
    }
}
