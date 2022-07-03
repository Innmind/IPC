<?php
declare(strict_types = 1);

namespace Innmind\IPC\Server;

use Innmind\IPC\{
    Server\ClientLifecycle\PendingStartOk,
    Server\ClientLifecycle\AwaitingMessage,
    Server\ClientLifecycle\PendingCloseOk,
    Server\ClientLifecycle\Garbage,
    Protocol,
    Message,
    Message\ConnectionStart,
    Message\Heartbeat,
    Client,
    Exception\NoMessage,
    Exception\MessageNotSent,
};
use Innmind\Socket\Server\Connection;
use Innmind\TimeContinuum\{
    Clock,
    ElapsedPeriod,
    PointInTime,
};

abstract class ClientLifecycle
{
    protected Connection $connection;
    protected Client $client;
    private Protocol $protocol;
    private Clock $clock;
    private ElapsedPeriod $heartbeat;
    private PointInTime $lastHeartbeat;

    final private function __construct(
        Connection $connection,
        Protocol $protocol,
        Clock $clock,
        ElapsedPeriod $heartbeat,
        Client $client,
        PointInTime $lastHeartbeat,
    ) {
        $this->connection = $connection;
        $this->protocol = $protocol;
        $this->clock = $clock;
        $this->heartbeat = $heartbeat;
        $this->client = $client;
        $this->lastHeartbeat = $lastHeartbeat;
    }

    final public static function of(
        Connection $connection,
        Protocol $protocol,
        Clock $clock,
        ElapsedPeriod $heartbeat,
    ): self {
        $client = new Client\Unix($connection, $protocol);
        $client = $client->send(new ConnectionStart)->match(
            static fn($client) => $client,
            static fn() => throw new MessageNotSent,
        );

        return new PendingStartOk(
            $connection,
            $protocol,
            $clock,
            $heartbeat,
            $client,
            $clock->now(),
        );
    }

    final public function notify(callable $notify): PendingStartOk|AwaitingMessage|PendingCloseOk|Garbage
    {
        if ($this->toBeGarbageCollected()) {
            return $this;
        }

        try {
            $message = $this->read();
            $this->lastHeartbeat = $this->clock->now();
        } catch (NoMessage $e) {
            return $this->garbage();
        }

        return $this->actUpon($message, $notify);
    }

    abstract public function actUpon(
        Message $message,
        callable $notify,
    ): PendingStartOk|AwaitingMessage|PendingCloseOk|Garbage;

    final public function heartbeat(): PendingStartOk|AwaitingMessage|PendingCloseOk|Garbage
    {
        if ($this->toBeGarbageCollected()) {
            return $this;
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

        return $this;
    }

    final public function shutdown(): PendingStartOk|AwaitingMessage|PendingCloseOk|Garbage
    {
        if ($this->toBeGarbageCollected()) {
            return $this;
        }

        return $this->client->close()->match(
            fn() => $this->pendingCloseOk(),
            fn() => $this->garbage(),
        );
    }

    abstract public function toBeGarbageCollected(): bool;

    protected function awaitingMessage(): AwaitingMessage
    {
        return new AwaitingMessage(
            $this->connection,
            $this->protocol,
            $this->clock,
            $this->heartbeat,
            $this->client,
            $this->lastHeartbeat,
        );
    }

    protected function pendingCloseOk(): PendingCloseOk
    {
        return new PendingCloseOk(
            $this->connection,
            $this->protocol,
            $this->clock,
            $this->heartbeat,
            $this->client,
            $this->lastHeartbeat,
        );
    }

    protected function garbage(): Garbage
    {
        return new Garbage(
            $this->connection,
            $this->protocol,
            $this->clock,
            $this->heartbeat,
            $this->client,
            $this->lastHeartbeat,
        );
    }

    protected function read(): Message
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
