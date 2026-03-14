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
     * @internal
     *
     * @return Attempt<self>
     */
    public static function of(
        OperatingSystem $os,
        Protocol $protocol,
        Address $address,
        Period $timeout,
    ): Attempt {
        $abort = Abort::disabled();

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
                    $abort,
                ),
                $abort,
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
                    ->pipe
                    ->signal(Message::connectionStartOk())
                    ->map(static fn() => $self),
            );
    }

    /**
     * @param Sequence<Message> $messages
     *
     * @return Attempt<SideEffect>
     */
    #[\NoDiscard]
    public function send(Sequence $messages): Attempt
    {
        return $this->pipe->send($messages);
    }

    /**
     * @return Attempt<Message>
     */
    #[\NoDiscard]
    public function wait(?Period $timeout = null): Attempt
    {
        return $this->pipe->wait($timeout);
    }

    /**
     * @return Attempt<SideEffect>
     */
    #[\NoDiscard]
    public function listenSignals(): Attempt
    {
        return $this
            ->os
            ->process()
            ->signals()
            ->listen(Signal::terminate, $this->abort);
    }

    /**
     * @return Attempt<SideEffect>
     */
    #[\NoDiscard]
    public function close(): Attempt
    {
        return $this
            ->pipe
            ->signal(Message::connectionClose())
            ->flatMap(fn() => $this->pipe->wait())
            ->flatMap(static fn($message) => match ($message->equals(Message::connectionCloseOk())) {
                true => Attempt::result(SideEffect::identity),
                false => Attempt::error(new \RuntimeException('Connection handshake failure')),
            })
            ->eitherWay(
                fn() => $this->socket->close(),
                fn($e) => $this
                    ->socket
                    ->close()
                    ->flatMap(static fn() => Attempt::error($e)),
            )
            ->eitherWay(
                fn($value) => $this
                    ->os
                    ->process()
                    ->signals()
                    ->remove($this->abort)
                    ->map(static fn() => $value),
                fn($e) => $this
                    ->os
                    ->process()
                    ->signals()
                    ->remove($this->abort)
                    ->flatMap(static fn() => Attempt::error($e)),
            );
    }
}
