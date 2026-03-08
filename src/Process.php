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
        private Pipe $pipe,
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
            ->map(static fn($client) => $client->timeoutAfter($timeout))
            ->map(static fn($client) => new self(
                $client,
                Pipe::of(
                    $client,
                    $protocol,
                    $clock,
                ),
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
        return $this->pipe->send($messages);
    }

    /**
     * @return Attempt<Message>
     */
    public function wait(?Period $timeout = null): Attempt
    {
        return $this->pipe->wait(
            static fn() => false, // todo handle signals ?
            $timeout,
        );
    }

    /**
     * @return Attempt<SideEffect>
     */
    public function close(): Attempt
    {
        return $this->socket->close();
    }
}
