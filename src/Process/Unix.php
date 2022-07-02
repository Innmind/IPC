<?php
declare(strict_types = 1);

namespace Innmind\IPC\Process;

use Innmind\IPC\{
    Process,
    Protocol,
    Message,
    Message\ConnectionStart,
    Message\ConnectionStartOk,
    Message\ConnectionClose,
    Message\ConnectionCloseOk,
    Message\Heartbeat,
    Message\MessageReceived,
};
use Innmind\OperatingSystem\Sockets;
use Innmind\Socket\{
    Address\Unix as Address,
    Client,
    Exception\Exception as Socket,
};
use Innmind\Stream\{
    Watch,
    Selectable,
    Exception\Exception as Stream,
};
use Innmind\TimeContinuum\{
    Clock,
    ElapsedPeriod,
    PointInTime,
};
use Innmind\Immutable\{
    Maybe,
    Set,
    SideEffect,
    Sequence,
};

final class Unix implements Process
{
    private Client $socket;
    private Watch $watch;
    private Protocol $protocol;
    private Clock $clock;
    private Name $name;
    private PointInTime $lastReceivedData;
    private bool $closed = false;

    private function __construct(
        Client $socket,
        Watch $watch,
        Protocol $protocol,
        Clock $clock,
        Name $name,
    ) {
        $this->socket = $socket;
        $this->watch = $watch;
        $this->protocol = $protocol;
        $this->clock = $clock;
        $this->name = $name;
        $this->lastReceivedData = $clock->now();
    }

    /**
     * @return Maybe<Process>
     */
    public static function of(
        Sockets $sockets,
        Protocol $protocol,
        Clock $clock,
        Address $address,
        Name $name,
        ElapsedPeriod $watchTimeout,
    ): Maybe {
        /** @var Maybe<Process> */
        return $sockets
            ->connectTo($address)
            ->map(static fn($socket) => new self(
                $socket,
                $sockets->watch($watchTimeout)->forRead($socket),
                $protocol,
                $clock,
                $name,
            ))
            ->flatMap(static fn($self) => $self->open());
    }

    public function name(): Name
    {
        return $this->name;
    }

    /**
     * @no-named-arguments
     */
    public function send(Message ...$messages): Maybe
    {
        /** @var Maybe<Process> */
        return Sequence::of(...$messages)->reduce(
            Maybe::just($this),
            self::maybeSendMessage(...),
        );
    }

    public function wait(ElapsedPeriod $timeout = null): Maybe
    {
        do {
            if ($this->closed()) {
                $this->cut();

                /** @var Maybe<Message> */
                return Maybe::nothing();
            }

            /** @var Set<Selectable> */
            $toRead = ($this->watch)()->match(
                static fn($ready) => $ready->toRead(),
                static fn() => Set::of(),
            );

            $receivedData = $toRead->contains($this->socket);

            if (!$receivedData) {
                $stop = $this
                    ->sendMessage(new Heartbeat)
                    ->filter(fn($self) => !$self->timedout($timeout))
                    ->match(
                        static fn() => false,
                        static fn() => true,
                    );

                if ($stop) {
                    /** @var Maybe<Message> */
                    return Maybe::nothing();
                }
            }
        } while (!$receivedData);

        $this->lastReceivedData = $this->clock->now();

        return $this
            ->protocol
            ->decode($this->socket)
            ->flatMap(function($message) use ($timeout) {
                if ($message->equals(new Heartbeat)) {
                    return $this->wait($timeout);
                }

                return Maybe::just($message);
            })
            ->flatMap(function($message) {
                if ($message->equals(new ConnectionClose)) {
                    $this->sendMessage(new ConnectionCloseOk);
                    $this->cut();

                    /** @var Maybe<Message> */
                    return Maybe::nothing();
                }

                return Maybe::just($message);
            });
    }

    public function close(): Maybe
    {
        if ($this->closed()) {
            return Maybe::just(new SideEffect);
        }

        $this->sendMessage(new ConnectionClose);

        try {
            return $this
                ->wait()
                ->filter(static fn($message) => $message->equals(new ConnectionCloseOk))
                ->map(static fn() => new SideEffect);
        } finally {
            $this->cut();
        }
    }

    public function closed(): bool
    {
        return $this->closed || $this->socket->closed();
    }

    /**
     * @return Maybe<Process>
     */
    private function open(): Maybe
    {
        /** @var Maybe<Process> */
        return $this
            ->wait()
            ->filter(static fn($message) => $message->equals(new ConnectionStart))
            ->map(fn() => $this->sendMessage(new ConnectionStartOk))
            ->map(fn() => $this);
    }

    private function cut(): void
    {
        $this->closed = true;
        $this->socket->close();
    }

    private function timedout(ElapsedPeriod $timeout = null): bool
    {
        if ($timeout === null) {
            return false;
        }

        $iteration = $this->clock->now()->elapsedSince($this->lastReceivedData);

        return $iteration->longerThan($timeout);
    }

    /**
     * @return Maybe<self>
     */
    private function sendMessage(Message $message): Maybe
    {
        if ($this->closed()) {
            /** @var Maybe<self> */
            return Maybe::nothing();
        }

        /** @var Maybe<self> */
        return $this
            ->socket
            ->write($this->protocol->encode($message))
            ->match(
                fn() => Maybe::just($this),
                static fn() => Maybe::nothing(),
            );
    }

    /**
     * @param Maybe<self> $maybe
     *
     * @return Maybe<self>
     */
    private static function maybeSendMessage(Maybe $maybe, Message $message): Maybe
    {
        return $maybe->flatMap(
            static fn($self) => $self
                ->sendMessage($message)
                ->flatMap(
                    static fn($self) => $self
                        ->wait() // for message acknowledgement
                        ->filter(static fn($message) => $message->equals(new MessageReceived))
                        ->map(static fn() => $self),
                ),
        );
    }
}
