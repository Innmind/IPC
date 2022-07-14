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
     * @param callable(Message, Continuation<C>, C): Continuation<C> $listen
     *
     * @return Either<C, self>
     */
    public function next(callable $listen): Either
    {
        return $this
            ->state
            ->watch($this->connections)
            ->either()
            ->flatMap(fn($active) => $this->act($active, $listen));
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
     * @param callable(Message, Continuation<C>, C): Continuation<C> $listen
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
                fn($connections) => $connections->map(fn($tuple) => new self(
                    $this->protocol,
                    $this->clock,
                    $tuple[0],
                    $this->heartbeat,
                    $this->timeout,
                    match ($active->clients()->empty()) {
                        true => $this->lastActivity,
                        false => $this->clock->now(),
                    },
                    $this->state,
                    $tuple[1],
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
     * @param callable(Message, Continuation<C>, C): Continuation<C> $listen
     *
     * @return Either<array{Connections, C}, array{Connections, C}> Left side means the connections must be shutdown
     */
    private function notify(
        Set $active,
        Connections $connections,
        callable $listen,
    ): Either {
        /**
         * Errors are due to the weak typing of the tuples
         * @psalm-suppress MixedArgumentTypeCoercion
         * @psalm-suppress MixedInferredReturnType
         * @psalm-suppress MixedReturnStatement
         * @psalm-suppress MixedMethodCall
         * @var Either<array{Connections, C}, array{Connections, C}>
         */
        return $active->reduce(
            Either::right([$connections, $this->carry]),
            static fn(Either $either, $connection): Either => $either->flatMap(
                static fn(array $tuple): Either => $tuple[0]->notify(
                    $connection,
                    $listen,
                    $tuple[1],
                ),
            ),
        );
    }

    /**
     * @param array{Connections, C} $tuple
     */
    private function shutdown(array $tuple): self
    {
        return new self(
            $this->protocol,
            $this->clock,
            $this->state->shutdown($tuple[0]),
            $this->heartbeat,
            $this->timeout,
            $this->clock->now(),
            State::shuttingDown,
            $tuple[1],
        );
    }

    /**
     * @param array{Connections, C} $tuple
     *
     * @return Either<self, array{Connections, C}>
     */
    private function monitorTimeout(array $tuple): Either
    {
        if (!$this->timeout) {
            return Either::right($tuple);
        }

        $iterationDuration = $this->clock->now()->elapsedSince($this->lastActivity);

        return match ($iterationDuration->longerThan($this->timeout)) {
            true => Either::left($this->shutdown($tuple)),
            false => Either::right($tuple),
        };
    }

    /**
     * @param array{Connections, C} $tuple
     *
     * @return Either<C, array{Connections, C}>
     */
    private function monitorTermination(array $tuple): Either
    {
        return $this
            ->state
            ->terminate($tuple[0])
            ->either()
            ->map(static fn($connections) => [$connections, $tuple[1]])
            ->leftMap(static fn() => $tuple[1]);
    }
}
