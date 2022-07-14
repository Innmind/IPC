<?php
declare(strict_types = 1);

namespace Innmind\IPC\Server\Unix;

use Innmind\IPC\{
    Server\Connections,
    Server\Connections\Active,
    Message,
    Continuation,
    Protocol,
};
use Innmind\TimeContinuum\{
    Clock,
    PointInTime,
    ElapsedPeriod,
};
use Innmind\Socket\Server\Connection;
use Innmind\Immutable\{
    Maybe,
    Set,
    Either,
};

/**
 * @template C
 */
final class Iteration
{
    private Protocol $protocol;
    private Clock $clock;
    private Connections $connections;
    private ElapsedPeriod $heartbeat;
    private ?ElapsedPeriod $timeout;
    private PointInTime $lastActivity;
    private State $state;
    /** @var C */
    private $carry;

    /**
     * @param C $carry
     */
    private function __construct(
        Protocol $protocol,
        Clock $clock,
        Connections $connections,
        ElapsedPeriod $heartbeat,
        ?ElapsedPeriod $timeout,
        PointInTime $lastActivity,
        State $state,
        mixed $carry,
    ) {
        $this->protocol = $protocol;
        $this->clock = $clock;
        $this->connections = $connections;
        $this->heartbeat = $heartbeat;
        $this->timeout = $timeout;
        $this->lastActivity = $lastActivity;
        $this->state = $state;
        $this->carry = $carry;
    }

    /**
     * @template T
     *
     * @param T $carry
     */
    public static function first(
        Protocol $protocol,
        Clock $clock,
        Connections $connections,
        ElapsedPeriod $heartbeat,
        ?ElapsedPeriod $timeout,
        mixed $carry,
    ): self {
        return new self(
            $protocol,
            $clock,
            $connections,
            $heartbeat,
            $timeout,
            $clock->now(),
            State::awaitingConnection,
            $carry,
        );
    }

    /**
     * @param callable(Message, Continuation): Continuation $listen
     *
     * @return Either<C, self>
     */
    public function next(callable $listen): Either
    {
        return $this
            ->state
            ->watch($this->connections)
            ->either()
            ->flatMap(fn($active) => $this->act($active, $listen))
            ->leftMap(fn() => $this->carry);
    }

    /**
     * This method mutates the object instead of returning a new one because it
     * is called when the process is signaled and the callback cannot mutate the
     * variable `$iteration` in `Server\Unix`
     */
    public function startShutdown(): void
    {
        $this->connections = $this->state->shutdown($this->connections);
        $this->lastActivity = $this->clock->now();
        $this->state = State::shuttingDown;
    }

    /**
     * @param callable(Message, Continuation): Continuation $listen
     *
     * @return Either<C, self>
     */
    private function act(Active $active, callable $listen): Either
    {
        $connections = $this->state->acceptConnection(
            $active->server(),
            $this->connections,
            $this->protocol,
            $this->clock,
            $this->heartbeat,
        );
        $connections = $this->heartbeat($connections, $active->clients());

        return $this
            ->notify($active->clients(), $connections, $listen)
            ->leftMap($this->shutdown(...))
            ->flatMap($this->monitorTimeout(...))
            ->map($this->monitorTermination(...))
            ->match(
                fn($connections) => $connections->map(fn($connections) => new self(
                    $this->protocol,
                    $this->clock,
                    $connections,
                    $this->heartbeat,
                    $this->timeout,
                    match ($active->clients()->empty()) {
                        true => $this->lastActivity,
                        false => $this->clock->now(),
                    },
                    $this->state,
                    $this->carry,
                )),
                static fn($shuttingDown) => Either::right($shuttingDown),
            );
    }

    /**
     * @param Set<Connection> $active
     */
    private function heartbeat(
        Connections $connections,
        Set $active,
    ): Connections {
        // send heartbeat message for clients not found in the active sockets
        return $connections->map(
            static fn($connection, $client) => $active
                ->find(static fn($active) => $active === $connection)
                ->match(
                    static fn() => $client,
                    static fn() => $client->heartbeat(),
                ),
        );
    }

    /**
     * @param Set<Connection> $active
     * @param callable(Message, Continuation): Continuation $listen
     *
     * @return Either<Connections, Connections> Left side means the connections must be shutdown
     */
    private function notify(
        Set $active,
        Connections $connections,
        callable $listen,
    ): Either {
        /**
         * @psalm-suppress MixedArgumentTypeCoercion
         * @var Either<Connections, Connections>
         */
        return $active->reduce(
            Either::right($connections),
            fn(Either $either, $connection) => $either->flatMap(
                fn(Connections $connections) => $connections->notify(
                    $connection,
                    $listen,
                    $this->carry,
                ),
            ),
        );
    }

    private function shutdown(Connections $connections): self
    {
        return new self(
            $this->protocol,
            $this->clock,
            $this->state->shutdown($connections),
            $this->heartbeat,
            $this->timeout,
            $this->clock->now(),
            State::shuttingDown,
            $this->carry,
        );
    }

    /**
     * @return Either<self, Connections>
     */
    private function monitorTimeout(Connections $connections): Either
    {
        if (!$this->timeout) {
            return Either::right($connections);
        }

        $iterationDuration = $this->clock->now()->elapsedSince($this->lastActivity);

        return match ($iterationDuration->longerThan($this->timeout)) {
            true => Either::left($this->shutdown($connections)),
            false => Either::right($connections),
        };
    }

    /**
     * @return Either<C, Connections>
     */
    private function monitorTermination(Connections $connections): Either
    {
        return $this
            ->state
            ->terminate($connections)
            ->either()
            ->leftMap(fn() => $this->carry);
    }
}
