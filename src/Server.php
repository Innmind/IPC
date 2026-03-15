<?php
declare(strict_types = 1);

namespace Innmind\IPC;

use Innmind\Async\Scheduler;
use Innmind\OperatingSystem\OperatingSystem;
use Innmind\IO\Sockets\Unix\Address;
use Innmind\Time\Period;
use Innmind\Immutable\{
    Attempt,
    SideEffect,
    Monoid,
};

/**
 * @template T
 */
final class Server
{
    /**
     * @psalm-mutation-free
     *
     * @param Monoid<T> $monoid
     * @param \Closure(T, Server\Continuation<T>): Server\Continuation<T> $monitor
     */
    private function __construct(
        private OperatingSystem $os,
        private Protocol $protocol,
        private Address $address,
        private Period $timeout,
        private Monoid $monoid,
        private \Closure $monitor,
    ) {
    }

    /**
     * @internal
     * @psalm-pure
     *
     * @return self<SideEffect>
     */
    public static function of(
        OperatingSystem $os,
        Protocol $protocol,
        Address $address,
        Period $timeout,
    ): self {
        return new self(
            $os,
            $protocol,
            $address,
            $timeout,
            namespace\Monoid::sideEffect,
            self::defaultMonitor(namespace\Monoid::sideEffect),
        );
    }

    /**
     * @psalm-mutation-free
     * @template U
     *
     * @param Monoid<U> $monoid
     *
     * @return self<U>
     */
    public function sink(Monoid $monoid): self
    {
        return new self(
            $this->os,
            $this->protocol,
            $this->address,
            $this->timeout,
            $monoid,
            self::defaultMonitor($monoid),
        );
    }

    /**
     * @psalm-mutation-free
     *
     * @param callable(T, Server\Continuation<T>): Server\Continuation<T> $monitor
     *
     * @return self<T>
     */
    public function monitor(callable $monitor): self
    {
        return new self(
            $this->os,
            $this->protocol,
            $this->address,
            $this->timeout,
            $this->monoid,
            \Closure::fromCallable($monitor),
        );
    }

    /**
     * @param callable(Message, Continuation<T>, T): Continuation<T> $listen
     *
     * @return Attempt<T>
     */
    #[\NoDiscard]
    public function with(callable $listen): Attempt
    {
        return Scheduler::of($this->os)
            ->sink(Attempt::result($this->monoid->identity()))
            ->with(Server\Instance::of(
                $this->protocol,
                $this->address,
                $this->timeout,
                $this->monoid,
                $this->monitor,
                \Closure::fromCallable($listen),
            ));
    }

    /**
     * @psalm-pure
     * @template A
     *
     * @param Monoid<A> $monoid
     *
     * @return \Closure(A, Server\Continuation<A>): Server\Continuation<A>
     */
    private static function defaultMonitor(Monoid $monoid): \Closure
    {
        return static fn($_, Server\Continuation $continuation) => $continuation;
    }
}
