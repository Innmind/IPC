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
};
use Innmind\Socket\Server\Connection;
use Innmind\TimeContinuum\{
    Clock,
    ElapsedPeriod,
    PointInTime,
};
use Innmind\Immutable\Maybe;

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

    /**
     * @return Maybe<self>
     */
    public static function of(
        Connection $connection,
        Protocol $protocol,
        Clock $clock,
        ElapsedPeriod $heartbeat,
    ): Maybe {
        $client = new Client\Unix($connection, $protocol);

        return $client
            ->send(new ConnectionStart)
            ->map(static fn($client) => new self(
                $connection,
                $protocol,
                $clock,
                $heartbeat,
                $client,
                $clock->now(),
                State::pendingStartOk,
            ));
    }

    /**
     * @return Maybe<self>
     */
    public function notify(callable $notify): Maybe
    {
        return $this
            ->read()
            ->flatMap(fn($message) => $this->state->actUpon(
                $this->client,
                $this->connection,
                $message,
                $notify,
            ))
            ->map(fn($state) => new self(
                $this->connection,
                $this->protocol,
                $this->clock,
                $this->heartbeat,
                $this->client,
                $this->clock->now(),
                $state,
            ));
    }

    public function heartbeat(): self
    {
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

    /**
     * @return Maybe<self>
     */
    public function shutdown(): Maybe
    {
        return $this
            ->client
            ->close()
            ->map(fn() => $this->pendingCloseOk());
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

    /**
     * @return Maybe<Message>
     */
    private function read(): Maybe
    {
        return $this->protocol->decode($this->connection);
    }
}
