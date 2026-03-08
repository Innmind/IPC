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
    Monoid as Monoid_,
};

/**
 * @template T
 */
final class Server
{
    /**
     * @param Monoid_<T> $monoid
     * @param \Closure(T, Server\Continuation<T>): Server\Continuation<T> $monitor
     */
    private function __construct(
        private OperatingSystem $os,
        private Protocol $protocol,
        private Address $address,
        private Period $timeout,
        private Monoid_ $monoid,
        private \Closure $monitor,
    ) {
    }

    /**
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
            Monoid::sideEffect,
            self::defaultMonitor(Monoid::sideEffect),
        );
    }

    /**
     * @psalm-mutation-free
     * @template U
     *
     * @param Monoid_<U> $carry
     *
     * @return self<U>
     */
    public function sink(Monoid_ $monoid): self
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

    // todo differentiate a reducer for the server loop (aka the scheduler sink)
    // and reducer that will operate on each connection
    // the server loop must transform the initial carry as an initial carry
    // dedicated for the connection reducer
    // and there should be a way to fold the returned carry from all connections
    // to a single value that will in the end be returned by the server

    /**
     * @param callable(Message, Continuation<T>, T): Continuation<T> $listen
     *
     * @return Attempt<T>
     */
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
            ));
    }

    /**
     * @psalm-pure
     * @template A
     *
     * @param Monoid_<A> $monoid
     *
     * @return \Closure(A, Server\Continuation<A>): Server\Continuation<A>
     */
    private static function defaultMonitor(Monoid_ $monoid): \Closure
    {
        return static fn($_, Server\Continuation $continuation) => $continuation;
    }
}
