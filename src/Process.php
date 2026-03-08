<?php
declare(strict_types = 1);

namespace Innmind\IPC;

use Innmind\OperatingSystem\Sockets;
use Innmind\IO\Sockets\{
    Clients\Client,
    Unix\Address,
};
use Innmind\Time\{
    Clock,
    Point,
    Period,
};
use Innmind\Immutable\{
    Sequence,
    Attempt,
    SideEffect,
};

final class Process
{
    private function __construct(
        private Client $socket,
        private Protocol $protocol,
        private Clock $clock,
    ) {
    }

    /**
     * @return Attempt<self>
     */
    public static function of(
        Sockets $sockets,
        Protocol $protocol,
        Clock $clock,
        Address $address,
        Period $timeout,
    ): Attempt {
        return $sockets
            ->connectTo($address)
            ->map(static fn($client) => new self(
                $client->timeoutAfter($timeout),
                $protocol,
                $clock,
            ))
            ->flatMap(
                static fn($self) => $self
                    ->wait()
                    ->flatMap(static fn($message) => match ($message->equals(Message::connectionStart())) {
                        true => Attempt::result($self),
                        false => Attempt::error(new \RuntimeException('Connection handshake failure')),
                    }),
            )
            ->flatMap(
                static fn($self) => $self
                    ->send(Sequence::of(Message::connectionStartOk()))
                    ->map(static fn() => $self),
            );
    }

    /**
     * @param Sequence<Message> $messages
     *
     * @return Attempt<SideEffect>
     */
    public function send(Sequence $messages): Attempt
    {
        return $messages
            ->sink(SideEffect::identity)
            ->attempt(
                fn($_, $message) => $this
                    ->protocol
                    ->encode($message)
                    ->map(Sequence::of(...))
                    ->flatMap($this->socket->sink(...))
                    ->flatMap(fn() => $this->wait())
                    ->flatMap(static fn($message) => match ($message->equals(Message::ack())) {
                        true => Attempt::result(SideEffect::identity),
                        false => Attempt::error(new \RuntimeException('Was expecting a message acknowledgement')),
                    }),
            );
    }

    /**
     * @return Attempt<Message>
     */
    public function wait(?Period $timeout = null): Attempt
    {
        return $this->doWait($this->clock->now(), $timeout);
    }

    /**
     * @return Attempt<SideEffect>
     */
    public function close(): Attempt
    {
        return $this->socket->close();
    }

    /**
     * @return Attempt<Message>
     */
    private function doWait(
        Point $start,
        ?Period $timeout = null,
    ): Attempt {
        $heartbeat = $this
            ->protocol
            ->encode(Message::heartbeat())
            ->unwrap();

        return $this
            ->socket
            ->heartbeatWith(static fn() => Sequence::of($heartbeat))
            ->abortWhen(function() use ($start, $timeout) {
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
            ->flatMap(function($message) use ($start, $timeout) {
                if ($message->equals(Message::heartbeat())) {
                    return $this->doWait($start, $timeout);
                }

                return Attempt::result($message);
            })
            ->flatMap(function($message) {
                if ($message->equals(Message::connectionClose())) {
                    return $this
                        ->send(Sequence::of(Message::connectionCloseOk()))
                        ->flatMap(fn() => $this->close())
                        ->flatMap(static fn() => Attempt::error(new \RuntimeException(
                            'Connection closed by the server',
                        )));
                }

                return Attempt::result($message);
            });
    }
}
