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
use Innmind\Socket\Server\Connection;
use Innmind\TimeContinuum\{
    TimeContinuumInterface,
    ElapsedPeriod,
};

final class ClientLifecycle
{
    private $connection;
    private $protocol;
    private $clock;
    private $client;
    private $heartbeat;
    private $lastHeartbeat;
    private $pendingStartOk = false;
    private $pendingCloseOk = false;
    private $garbage = false;

    public function __construct(
        Connection $connection,
        Protocol $protocol,
        TimeContinuumInterface $clock,
        ElapsedPeriod $heartbeat
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
                $this->client->send(new ConnectionCloseOk);
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

        $this->client->send(new MessageReceived);

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
            try {
                $this->client->send(new Heartbeat);
            } catch (MessageNotSent $e) {
                // happens when the client has been forced closed (for example
                // with a `kill -9` on the client process)
            }
        }
    }

    public function shutdown(): void
    {
        if ($this->toBeGarbageCollected()) {
            return;
        }

        try {
            $this->client->close();
            $this->pendingCloseOk = true;
            $this->pendingStartOk = false;
        } catch (MessageNotSent $e) {
            $this->garbage = true;
        }
    }

    public function toBeGarbageCollected(): bool
    {
        return $this->garbage;
    }

    private function greet(): void
    {
        $this->client->send(new ConnectionStart);
        $this->lastHeartbeat = $this->clock->now();
        $this->pendingStartOk = true;
    }

    private function read(): Message
    {
        try {
            return $this->protocol->decode($this->connection);
        } catch (NoMessage $e) {
            $this->garbage = true;
            throw $e;
        }
    }
}
