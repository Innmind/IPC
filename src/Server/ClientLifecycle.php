<?php
declare(strict_types = 1);

namespace Innmind\IPC\Server;

use Innmind\IPC\{
    Protocol,
    Message,
    Message\ConnectionStart,
    Message\ConnectionStartOk,
    Message\ConnectionClose,
    Message\ConnectionCloseOk,
    Message\MessageReceived,
    Message\Heartbeat,
    Client,
    Exception\NoMessage,
    Exception\MessageNotSent,
};
use Innmind\Socket\{
    Server\Connection,
    Exception\Exception as SocketException,
};
use Innmind\Stream\Exception\Exception as StreamException;
use Innmind\TimeContinuum\{
    Clock,
    ElapsedPeriod,
    PointInTime,
};

final class ClientLifecycle
{
    private Connection $connection;
    private Protocol $protocol;
    private Clock $clock;
    private Client $client;
    private ElapsedPeriod $heartbeat;
    private PointInTime $lastHeartbeat;
    private bool $pendingStartOk = false;
    private bool $pendingCloseOk = false;
    private bool $garbage = false;

    public function __construct(
        Connection $connection,
        Protocol $protocol,
        Clock $clock,
        ElapsedPeriod $heartbeat,
    ) {
        $this->connection = $connection;
        $this->protocol = $protocol;
        $this->clock = $clock;
        $this->heartbeat = $heartbeat;
        $this->client = new Client\Unix($connection, $protocol);
        $this->greet();
    }

    public function notify(callable $notify): void
    {
        if ($this->toBeGarbageCollected()) {
            return;
        }

        try {
            $message = $this->read();
            $this->lastHeartbeat = $this->clock->now();
        } catch (NoMessage $e) {
            return;
        }

        if ($this->pendingStartOk && !$message->equals(new ConnectionStartOk)) {
            return;
        }

        if ($this->pendingStartOk && $message->equals(new ConnectionStartOk)) {
            $this->pendingStartOk = false;

            return;
        }

        if ($message->equals(new ConnectionClose)) {
            try {
                $_ = $this->client->send(new ConnectionCloseOk)->match(
                    static fn() => null,
                    static fn() => throw new MessageNotSent,
                );
                $this->connection->close();
            } catch (MessageNotSent $e) {
                // nothing to do
            } finally {
                $this->garbage = true;

                return;
            }
        }

        if ($this->pendingCloseOk && !$message->equals(new ConnectionCloseOk)) {
            return;
        }

        if ($this->pendingCloseOk && $message->equals(new ConnectionCloseOk)) {
            try {
                $this->connection->close();
            } catch (StreamException | SocketException $e) {
                // nothing to do
            } finally {
                $this->pendingCloseOk = false;
                $this->garbage = true;

                return;
            }
        }

        if (
            $message->equals(new ConnectionStart) ||
            $message->equals(new ConnectionStartOk) ||
            $message->equals(new ConnectionClose) ||
            $message->equals(new ConnectionCloseOk) ||
            $message->equals(new MessageReceived) ||
            $message->equals(new Heartbeat)
        ) {
            // never notify with a protocol message
            return;
        }

        $_ = $this->client->send(new MessageReceived)->match(
            static fn() => null,
            static fn() => throw new MessageNotSent,
        );
        $notify($message, $this->client);

        if ($this->client->closed()) {
            $this->pendingCloseOk = true;
        }
    }

    public function heartbeat(): void
    {
        if ($this->toBeGarbageCollected()) {
            return;
        }

        $trigger = $this
            ->clock
            ->now()
            ->elapsedSince($this->lastHeartbeat)
            ->longerThan($this->heartbeat);

        if ($trigger) {
            // do nothing when failling to send the message as it happens when
            // the client has been forced closed (for example with a `kill -9`
            // on the client process)
            $_ = $this->client->send(new Heartbeat)->match(
                static fn() => null,
                static fn() => null,
            );
        }
    }

    public function shutdown(): void
    {
        if ($this->toBeGarbageCollected()) {
            return;
        }

        /** @psalm-suppress AssignmentToVoid */
        $_ = $this->client->close()->match(
            function() {
                $this->pendingCloseOk = true;
                $this->pendingStartOk = false;
            },
            function() {
                $this->garbage = true;
            },
        );
    }

    public function toBeGarbageCollected(): bool
    {
        return $this->garbage;
    }

    private function greet(): void
    {
        $_ = $this->client->send(new ConnectionStart)->match(
            static fn() => null,
            static fn() => throw new MessageNotSent,
        );
        $this->lastHeartbeat = $this->clock->now();
        $this->pendingStartOk = true;
    }

    private function read(): Message
    {
        return $this->protocol->decode($this->connection)->match(
            static fn($message) => $message,
            function() {
                $this->garbage = true;

                throw new NoMessage;
            },
        );
    }
}
