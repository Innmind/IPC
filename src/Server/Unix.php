<?php
declare(strict_types = 1);

namespace Innmind\IPC\Server;

use Innmind\IPC\{
    Server,
    Protocol,
    Message,
    Continuation,
};
use Innmind\OperatingSystem\{
    Sockets,
    CurrentProcess\Signals,
};
use Innmind\Signals\{
    Signal,
    Info,
};
use Innmind\Socket\Address\Unix as Address;
use Innmind\TimeContinuum\{
    Clock,
    ElapsedPeriod,
};
use Innmind\Immutable\Either;

final class Unix implements Server
{
    private Sockets $sockets;
    private Protocol $protocol;
    private Clock $clock;
    private Signals $signals;
    private Address $address;
    private ElapsedPeriod $heartbeat;
    private ?ElapsedPeriod $timeout;

    public function __construct(
        Sockets $sockets,
        Protocol $protocol,
        Clock $clock,
        Signals $signals,
        Address $address,
        ElapsedPeriod $heartbeat,
        ElapsedPeriod $timeout = null,
    ) {
        $this->sockets = $sockets;
        $this->protocol = $protocol;
        $this->clock = $clock;
        $this->signals = $signals;
        $this->address = $address;
        $this->heartbeat = $heartbeat;
        $this->timeout = $timeout;
    }

    /**
     * @template C
     *
     * @param C $carry
     * @param callable(Message, Continuation<C>, C): Continuation<C> $listen
     *
     * @return Either<UnableToStart, C>
     */
    public function __invoke(mixed $carry, callable $listen): Either
    {
        $iteration = $this
            ->sockets
            ->open($this->address)
            ->map(fn($server) => Connections::start(
                $this->sockets->watch($this->heartbeat),
                $server,
            ))
            ->map(fn($connections) => Unix\Iteration::first(
                $this->protocol,
                $this->clock,
                $connections,
                $this->heartbeat,
                $this->timeout,
                $carry,
            ))
            ->match(
                static fn($iteration) => $iteration,
                static fn() => null,
            );

        if (\is_null($iteration)) {
            return Either::left(new UnableToStart);
        }

        $shutdown = static function() use (&$iteration): void {
            /** @var Unix\Iteration $iteration */
            $iteration->startShutdown();
        };
        $this->registerSignals($shutdown);

        // we use a while loop instead of recursion to avoid too deep call stacks
        // for long running servers
        do {
            try {
                /**
                 * @psalm-suppress MixedMethodCall Due to the reference above for the shutdown
                 * @var Unix\Iteration<C>|C
                 */
                $iteration = $iteration->next($listen)->match(
                    static fn(mixed $iteration): mixed => $iteration,
                    static fn(mixed $carry): mixed => $carry,
                );
            } catch (\Throwable $e) {
                $this->unregisterSignals($shutdown);

                throw $e;
            }
        } while ($iteration instanceof Unix\Iteration);

        $this->unregisterSignals($shutdown);

        return Either::right($iteration);
    }

    /**
     * @param callable(Signal, Info): void $shutdown
     */
    private function registerSignals(callable $shutdown): void
    {
        $this->signals->listen(Signal::hangup, $shutdown);
        $this->signals->listen(Signal::interrupt, $shutdown);
        $this->signals->listen(Signal::abort, $shutdown);
        $this->signals->listen(Signal::terminate, $shutdown);
        $this->signals->listen(Signal::terminalStop, $shutdown);
        $this->signals->listen(Signal::alarm, $shutdown);
    }

    /**
     * @param callable(Signal, Info): void $shutdown
     */
    private function unregisterSignals(callable $shutdown): void
    {
        $this->signals->remove($shutdown);
    }
}
