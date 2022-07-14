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
use Innmind\Immutable\{
    Maybe,
    Either,
};

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
     * @template C
     *
     * @param callable(Message, Continuation<C>): Continuation<C> $notify
     * @param C $carry
     *
     * @return Maybe<Either<self, self>> Left side of Either means the notify asked the server to stop
     */
    public function notify(callable $notify, mixed $carry): Maybe
    {
        return $this
            ->client
            ->read()
            ->flatMap(fn($tuple) => $this->state->actUpon(
                $tuple[0],
                $tuple[1],
                $notify,
                $carry,
            ))
            ->map(
                fn($either) => $either
                    ->map($this->update(...))
                    ->leftMap($this->update(...)),
            );
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
            $client = $this->client->send(new Heartbeat)->match(
                static fn($client) => $client,
                fn() => $this->client,
            );

            return new self(
                $this->clock,
                $this->heartbeat,
                $client,
                $this->lastHeartbeat,
                $this->state,
            );
        }

        return $this;
    }

    /**
     * @return Maybe<self>
     */
    public function shutdown(): Maybe
    {
        return match ($this->state) {
            State::pendingCloseOk => Maybe::just($this),
            default => $this
                ->client
                ->send(new ConnectionClose)
                ->map($this->pendingCloseOk(...)),
        };
    }

    private function pendingCloseOk(Client $client): self
    {
        return new self(
            $this->clock,
            $this->heartbeat,
            $client,
            $this->lastHeartbeat,
            State::pendingCloseOk,
        );
    }

    /**
     * @param array{Client, State} $tuple
     */
    private function update(array $tuple): self
    {
        return new self(
            $this->clock,
            $this->heartbeat,
            $tuple[0],
            $this->clock->now(),
            $tuple[1],
        );
    }
}
