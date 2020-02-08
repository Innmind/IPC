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
    Exception\FailedToConnect,
    Exception\ConnectionClosed,
    Exception\Timedout,
    Exception\InvalidConnectionClose,
    Exception\RuntimeException,
    Exception\MessageNotSent,
};
use Innmind\OperatingSystem\Sockets;
use Innmind\Socket\{
    Address\Unix as Address,
    Client,
    Exception\Exception as Socket,
};
use Innmind\Stream\{
    Select,
    Exception\Exception as Stream,
};
use Innmind\TimeContinuum\{
    TimeContinuumInterface,
    ElapsedPeriodInterface,
    ElapsedPeriod,
    PointInTimeInterface,
};

final class Unix implements Process
{
    private Client $socket;
    private Select $select;
    private Protocol $protocol;
    private TimeContinuumInterface $clock;
    private Name $name;
    private PointInTimeInterface $lastReceivedData;
    private bool $closed = false;

    public function __construct(
        Sockets $sockets,
        Protocol $protocol,
        TimeContinuumInterface $clock,
        Address $address,
        Name $name,
        ElapsedPeriod $selectTimeout
    ) {
        try {
            $this->socket = $sockets->connectTo($address);
        } catch (Stream | Socket $e) {
            throw new FailedToConnect((string) $name, 0, $e);
        }

        $this->select = (new Select($selectTimeout))->forRead($this->socket);
        $this->protocol = $protocol;
        $this->clock = $clock;
        $this->name = $name;
        $this->lastReceivedData = $clock->now();
        $this->open();
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

    /**
     * {@inheritdoc}
     */
    public function wait(ElapsedPeriodInterface $timeout = null): Message
    {
        do {
            if ($this->closed()) {
                $this->cut();

                throw new ConnectionClosed;
            }

            try {
                $sockets = ($this->select)();
            } catch (Stream | Socket $e) {
                throw new RuntimeException('', 0, $e);
            }

            $receivedData = $sockets->get('read')->contains($this->socket);

            if (!$receivedData) {
                $this->sendMessage(new Heartbeat);
                $this->timeout($timeout);
            }
        } while (!$receivedData);

        $this->lastReceivedData = $this->clock->now();

        try {
            $message = $this->protocol->decode($this->socket);
        } catch (Stream | Socket $e) {
            throw new RuntimeException('', 0, $e);
        }

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

            throw new InvalidConnectionClose((string) $this->name());
        }

        $this->cut();
    }

    public function closed(): bool
    {
        return $this->closed || $this->socket->closed();
    }

    private function open(): void
    {
        try {
            $message = $this->wait();
        } catch (RuntimeException $e) {
            throw new FailedToConnect((string) $this->name(), 0, $e);
        }

        if (!$message->equals(new ConnectionStart)) {
            throw new FailedToConnect((string) $this->name());
        }

        $this->sendMessage(new ConnectionStartOk);
    }

    private function cut(): void
    {
        $this->closed = true;
        $this->socket->close();
    }

    private function timeout(ElapsedPeriodInterface $timeout = null): void
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
                $this->protocol->encode($message)
            );
        } catch (Stream | Socket $e) {
            throw new RuntimeException('', 0, $e);
        }
    }
}
