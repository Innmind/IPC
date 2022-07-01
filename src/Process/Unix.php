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
    Exception\Timedout,
    Exception\InvalidConnectionClose,
    Exception\RuntimeException,
    Exception\MessageNotSent,
    Exception\NoMessage,
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

    public function send(Message ...$messages): void
    {
        if ($this->closed()) {
            return;
        }

        foreach ($messages as $message) {
            try {
                $this->sendMessage($message);

                $this->wait(); // for message acknowledgement
            } catch (RuntimeException $e) {
                throw new MessageNotSent('', 0, $e);
            }
        }
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
                $this->sendMessage(new Heartbeat);
                $this->timeout($timeout);
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

    public function close(): void
    {
        if ($this->closed()) {
            return;
        }

        $this->sendMessage(new ConnectionClose);

        try {
            $_ = $this->wait()->match(
                function($message) {
                    if (!$message->equals(new ConnectionCloseOk)) {
                        throw new InvalidConnectionClose($this->name()->toString());
                    }
                },
                static fn() => null,
            );
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

    private function timeout(ElapsedPeriod $timeout = null): void
    {
        if ($timeout === null) {
            return;
        }

        $iteration = $this->clock->now()->elapsedSince($this->lastReceivedData);

        if ($iteration->longerThan($timeout)) {
            throw new Timedout;
        }
    }

    private function sendMessage(Message $message): void
    {
        if ($this->closed()) {
            return;
        }

        try {
            $this->socket->write(
                $this->protocol->encode($message),
            );
        } catch (Stream | Socket $e) {
            throw new RuntimeException('', 0, $e);
        }
    }
}
