<?php
declare(strict_types = 1);

namespace Innmind\IPC;

use Innmind\IO\Sockets\Clients\Client;
use Innmind\Time\{
    Clock,
    Period,
};
use Innmind\Immutable\{
    Sequence,
    Attempt,
    SideEffect,
    Str,
};

/**
 * @internal
 */
final class Pipe
{
    private function __construct(
        private Client $socket,
        private Protocol $protocol,
        private Clock $clock,
        private Abort $abort,
    ) {
    }

    /**
     * @internal
     */
    public static function of(
        Client $socket,
        Protocol $protocol,
        Clock $clock,
        Abort $abort,
    ): self {
        return new self($socket, $protocol, $clock, $abort);
    }

    /**
     * @return Attempt<Message>
     */
    public function wait(?Period $timeout = null): Attempt
    {
        $abort = $this->abort;
        $start = $this->clock->now();
        // It's safe to unwrap as it's an internal message that never fails
        $heartbeat = $this
            ->protocol
            ->encode(Message::heartbeat())
            ->unwrap();

        do {
            $result = $this
                ->socket
                ->toEncoding(Str\Encoding::ascii)
                ->heartbeatWith(static fn() => Sequence::of($heartbeat))
                ->abortWhen(function() use ($abort, $start, $timeout) {
                    if ($abort->enabled()) {
                        return true;
                    }

                    if (\is_null($timeout)) {
                        return false;
                    }

                    return $this
                        ->clock
                        ->now()
                        ->elapsedSince($start)
                        ->longerThan($timeout->asElapsedPeriod());
                })
                ->frames($this->protocol->frame())
                ->one()
                ->match(
                    static fn($message) => $message,
                    static fn($e) => $e,
                );

            if ($result instanceof \Throwable) {
                return Attempt::error($result);
            }
        } while ($result->equals(Message::heartbeat()));

        if ($result->equals(Message::connectionClose())) {
            return $this
                ->protocol
                ->encode(Message::connectionCloseOk())
                ->map(Sequence::of(...))
                ->flatMap($this->socket->sink(...))
                ->flatMap(fn() => $this->socket->close())
                ->flatMap(static fn() => Attempt::error(new \RuntimeException(
                    'Connection closed by the other side',
                )));
        }

        return Attempt::result($result);
    }

    /**
     * @param Sequence<Message> $messages
     *
     * @return Attempt<SideEffect>
     */
    public function send(Sequence $messages): Attempt
    {
        $socket = $this->socket->abortWhen($this->abort->enabled(...));

        return $messages
            ->sink(SideEffect::identity)
            ->attempt(
                fn($_, $message) => $this
                    ->protocol
                    ->encode($message)
                    ->map(Sequence::of(...))
                    ->flatMap($socket->sink(...))
                    ->flatMap(fn() => $this->wait())
                    ->flatMap(static fn($message) => match ($message->equals(Message::ack())) {
                        true => Attempt::result(SideEffect::identity),
                        false => Attempt::error(new \RuntimeException('Was expecting a message acknowledgement')),
                    }),
            );
    }

    /**
     * @return Attempt<SideEffect>
     */
    public function signal(Message $message): Attempt
    {
        $socket = $this->socket->abortWhen($this->abort->enabled(...));

        return $this
            ->protocol
            ->encode($message)
            ->map(Sequence::of(...))
            ->flatMap($socket->sink(...));
    }
}
