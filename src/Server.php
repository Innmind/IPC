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
};

/**
 * @template T
 */
final class Server
{
    /**
     * @param T $carry
     */
    private function __construct(
        private OperatingSystem $os,
        private Protocol $protocol,
        private Address $address,
        private Period $timeout,
        private mixed $carry,
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
            SideEffect::identity,
        );
    }

    /**
     * @psalm-mutation-free
     * @template U
     *
     * @param U $carry
     *
     * @return self<U>
     */
    public function sink(mixed $carry): self
    {
        return new self(
            $this->os,
            $this->protocol,
            $this->address,
            $this->timeout,
            $carry,
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
            ->sink(Attempt::result($this->carry))
            ->with(Server\Instance::of(
                $this->protocol,
                $this->address,
                $this->timeout,
            ));
    }
}
