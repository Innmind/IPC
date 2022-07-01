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
    Exception\ConnectionClosed,
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
    Exception\Exception as Stream,
};
use Innmind\TimeContinuum\{
    Clock,
    ElapsedPeriod,
    PointInTime,
};
use Innmind\Immutable\Maybe;

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

    public function wait(ElapsedPeriod $timeout = null): Message
    {
        do {
            if ($this->closed()) {
                $this->cut();

                throw new ConnectionClosed;
            }

            $ready = ($this->watch)()->match(
                static fn($ready) => $ready,
                static fn() => throw new RuntimeException,
            );

            $receivedData = $ready->toRead()->contains($this->socket);

            if (!$receivedData) {
                $this->sendMessage(new Heartbeat);
                $this->timeout($timeout);
            }
        } while (!$receivedData);

        $this->lastReceivedData = $this->clock->now();

        $message = $this->protocol->decode($this->socket)->match(
            static fn($message) => $message,
            static fn() => throw new NoMessage,
        );

        if ($message->equals(new Heartbeat)) {
            return $this->wait($timeout);
        }

        if ($message->equals(new ConnectionClose)) {
            $this->sendMessage(new ConnectionCloseOk);
            $this->cut();

            throw new ConnectionClosed;
        }

        return $message;
    }

    public function close(): void
    {
        if ($this->closed()) {
            return;
        }

        $this->sendMessage(new ConnectionClose);
        $message = $this->wait();

        if (!$message->equals(new ConnectionCloseOk)) {
            $this->cut();

            throw new InvalidConnectionClose($this->name()->toString());
        }

        $this->cut();
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
        try {
            $message = $this->wait();
        } catch (RuntimeException $e) {
            /** @var Maybe<Process> */
            return Maybe::nothing();
        }

        if (!$message->equals(new ConnectionStart)) {
            /** @var Maybe<Process> */
            return Maybe::nothing();
        }

        try {
            $this->sendMessage(new ConnectionStartOk);
        } catch (RuntimeException) {
            /** @var Maybe<Process> */
            return Maybe::nothing();
        }

        /** @var Maybe<Process> */
        return Maybe::just($this);
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
