<?php
declare(strict_types = 1);

namespace Innmind\IPC\Server;

use Innmind\IPC\{
    Server\ClientLifecycle\State,
    Message,
    Message\ConnectionStart,
    Message\ConnectionClose,
    Message\Heartbeat,
    Client,
    Continuation,
};
use Innmind\TimeContinuum\{
    Clock,
    ElapsedPeriod,
    PointInTime,
};
use Innmind\Immutable\Maybe;

final class ClientLifecycle
{
    private Clock $clock;
    private Client $client;
    private ElapsedPeriod $heartbeat;
    private PointInTime $lastHeartbeat;
    private State $state;

    private function __construct(
        Clock $clock,
        ElapsedPeriod $heartbeat,
        Client $client,
        PointInTime $lastHeartbeat,
        State $state,
    ) {
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
        Client $client,
        Clock $clock,
        ElapsedPeriod $heartbeat,
    ): Maybe {
        return $client
            ->send(new ConnectionStart)
            ->map(static fn($client) => new self(
                $clock,
                $heartbeat,
                $client,
                $clock->now(),
                State::pendingStartOk,
            ));
    }

    /**
     * @param callable(Message, Continuation): Continuation $notify
     *
     * @return Maybe<self>
     */
    public function notify(callable $notify): Maybe
    {
        return $this
            ->client
            ->read()
            ->flatMap(fn($message) => $this->state->actUpon(
                $this->client,
                $message,
                $notify,
            ))
            ->map(fn($state) => new self(
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
            ->send(new ConnectionClose)
            ->map(fn() => $this->pendingCloseOk());
    }

    private function pendingCloseOk(): self
    {
        return new self(
            $this->clock,
            $this->heartbeat,
            $this->client,
            $this->lastHeartbeat,
            State::pendingCloseOk,
        );
    }
}
