<?php
declare(strict_types = 1);

namespace Innmind\IPC;

use Innmind\OperatingSystem\OperatingSystem;
use Innmind\IO\Sockets\{
    Clients\Client,
    Unix\Address,
};
use Innmind\Signals\Signal;
use Innmind\Time\Period;
use Innmind\Immutable\{
    Sequence,
    Attempt,
    SideEffect,
};

final class Process
{
    private function __construct(
        private OperatingSystem $os,
        private Client $socket,
        private Pipe $pipe,
        private Abort $abort,
    ) {
    }

    /**
     * @return Attempt<self>
     */
    public static function of(
        OperatingSystem $os,
        Protocol $protocol,
        Address $address,
        Period $timeout,
    ): Attempt {
        return $os
            ->sockets()
            ->connectTo($address)
            ->map(static fn($client) => $client->timeoutAfter($timeout))
            ->map(static fn($client) => new self(
                $os,
                $client,
                Pipe::of(
                    $client,
                    $protocol,
                    $os->clock(),
                ),
                Abort::disabled(),
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
            $this->abort,
            $timeout,
        );
    }

    /**
     * @return Attempt<SideEffect>
     */
    public function listenSignals(): Attempt
    {
        return $this
            ->os
            ->process()
            ->signals()
            ->listen(Signal::terminate, $this->abort->enable(...));
    }

    /**
     * @return Attempt<SideEffect>
     */
    public function close(): Attempt
    {
        return $this->socket->close();
    }
}
