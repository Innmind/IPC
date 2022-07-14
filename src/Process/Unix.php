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
};
use Innmind\Stream\{
    Watch,
    Selectable,
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

    public function send(Sequence $messages): Maybe
    {
        /** @var Maybe<Process> */
        return $messages->reduce(
            Maybe::just($this),
            self::maybeSendMessage(...),
        );
    }

    public function wait(ElapsedPeriod $timeout = null): Maybe
    {
        do {
            if ($this->closed()) {
                /** @var Maybe<Message> */
                return $this
                    ->cut()
                    ->filter(static fn() => false); // never return anything
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
                    ->filter(static fn($self) => !$self->timedout($timeout))
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
                    /** @var Maybe<Message> */
                    return $this
                        ->sendMessage(new ConnectionCloseOk)
                        ->flatMap(static fn($self) => $self->cut())
                        ->filter(static fn() => false); // never return anything
                }

                return Maybe::just($message);
            });
    }

    public function close(): Maybe
    {
        if ($this->closed()) {
            return Maybe::just(new SideEffect);
        }

        return $this
            ->sendMessage(new ConnectionClose)
            ->flatMap(
                static fn($self) => $self
                    ->wait()
                    ->filter(static fn($message) => $message->equals(new ConnectionCloseOk))
                    ->map(static fn() => $self),
            )
            ->flatMap(static fn($self) => $self->cut())
            ->otherwise(
                fn() => $this
                    ->cut()
                    ->filter(static fn() => false), // never return anything
            );
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
            ->flatMap(fn() => $this->sendMessage(new ConnectionStartOk));
    }

    /**
     * @return Maybe<SideEffect>
     */
    private function cut(): Maybe
    {
        $this->closed = true;

        return $this->socket->close()->maybe();
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
            ->maybe()
            ->map(fn() => $this);
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
