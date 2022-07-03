<?php
declare(strict_types = 1);

namespace Innmind\IPC\Server;

use Innmind\IPC\{
    Server\ClientLifecycle\State,
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

final class ClientLifecycle
{
    private Connection $connection;
    private Protocol $protocol;
    private Clock $clock;
    private Client $client;
    private ElapsedPeriod $heartbeat;
    private PointInTime $lastHeartbeat;
    private State $state;

    private function __construct(
        Connection $connection,
        Protocol $protocol,
        Clock $clock,
        ElapsedPeriod $heartbeat,
        Client $client,
        PointInTime $lastHeartbeat,
        State $state,
    ) {
        $this->connection = $connection;
        $this->protocol = $protocol;
        $this->clock = $clock;
        $this->heartbeat = $heartbeat;
        $this->client = $client;
        $this->lastHeartbeat = $lastHeartbeat;
        $this->state = $state;
    }

    public static function of(
        Connection $connection,
        Protocol $protocol,
        Clock $clock,
        ElapsedPeriod $heartbeat,
    ): self {
        $client = new Client\Unix($connection, $protocol);

        return $client->send(new ConnectionStart)->match(
            static fn($client) => new self(
                $connection,
                $protocol,
                $clock,
                $heartbeat,
                $client,
                $clock->now(),
                State::pendingStartOk,
            ),
            static fn() => throw new MessageNotSent,
        );
    }

    public function notify(callable $notify): self
    {
        if ($this->toBeGarbageCollected()) {
            return $this;
        }

        try {
            $message = $this->read();
            $lastHeartbeat = $this->clock->now();
        } catch (NoMessage $e) {
            return $this->garbage();
        }

        return new self(
            $this->connection,
            $this->protocol,
            $this->clock,
            $this->heartbeat,
            $this->client,
            $lastHeartbeat,
            $this->state->actUpon(
                $this->client,
                $this->connection,
                $message,
                $notify,
            ),
        );
    }

    public function heartbeat(): self
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

    public function shutdown(): self
    {
        if ($this->toBeGarbageCollected()) {
            return $this;
        }

        return $this->client->close()->match(
            fn() => $this->pendingCloseOk(),
            fn() => $this->garbage(),
        );
    }

    public function toBeGarbageCollected(): bool
    {
        return $this->state->toBeGarbageCollected();
    }

    private function pendingCloseOk(): self
    {
        return new self(
            $this->connection,
            $this->protocol,
            $this->clock,
            $this->heartbeat,
            $this->client,
            $this->lastHeartbeat,
            State::pendingCloseOk,
        );
    }

    private function garbage(): self
    {
        return new self(
            $this->connection,
            $this->protocol,
            $this->clock,
            $this->heartbeat,
            $this->client,
            $this->lastHeartbeat,
            State::garbage,
        );
    }

    private function read(): Message
    {
        return $this->protocol->decode($this->connection)->match(
            static fn($message) => $message,
            static fn() => throw new NoMessage,
        );
    }
}
