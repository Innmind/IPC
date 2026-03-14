<?php
declare(strict_types = 1);

namespace Innmind\IPC\Server;

use Innmind\IPC\{
    Protocol,
    Continuation as Continuation_,
    Message,
    Abort,
};
use Innmind\Async\Scope;
use Innmind\OperatingSystem\OperatingSystem;
use Innmind\IO\Sockets\{
    Servers\Server,
    Unix\Address,
};
use Innmind\Signals\Signal;
use Innmind\Time\Period;
use Innmind\Immutable\{
    Attempt,
    Sequence,
    Monoid,
};

/**
 * @template T
 */
final class Instance
{
    /**
     * @param Monoid<T> $monoid
     * @param \Closure(T, Continuation<T>): Continuation<T> $monitor
     * @param \Closure(Message, Continuation_<T>, T): Continuation_<T> $listen
     */
    private function __construct(
        private Server|Unstarted $server,
        private Protocol $protocol,
        private Abort $abort,
        private Period $timeout,
        private Monoid $monoid,
        private \Closure $monitor,
        private \Closure $listen,
    ) {
    }

    /**
     * @param Attempt<T> $carry
     * @param Scope\Continuation<Attempt<T>> $continuation
     *
     * @return Scope\Continuation<Attempt<T>>
     */
    public function __invoke(
        Attempt $carry,
        OperatingSystem $os,
        Scope\Continuation $continuation,
    ): Scope\Continuation {
        if ($this->server instanceof Unstarted) {
            return ($this->server)($os)
                ->map(function($server) {
                    $this->server = $server;

                    return $server;
                })
                ->map(
                    fn($server) => $os
                        ->process()
                        ->signals()
                        ->listen(Signal::terminate, $this->abort)
                        ->map(static fn() => $server),
                )
                ->match(
                    static fn() => $continuation,
                    static fn($e) => $continuation
                        ->carryWith(Attempt::error($e))
                        ->finish(),
                );
        }

        if ($this->abort->enabled()) {
            return $continuation
                ->carryWith(Attempt::error(new \RuntimeException('Server signaled to terminate')))
                ->terminate();
        }

        /** @var Sequence<T> */
        $all = Sequence::of();
        /** @var Sequence<Attempt<T>> */
        $results = $continuation->results();
        /** @var Attempt<T> */
        $carry = $results
            ->prepend(Sequence::of($carry))
            ->sink($all)
            ->attempt(static fn($all, $result) => $result->map($all))
            ->map(fn($results) => $results->fold($this->monoid));

        /** @psalm-suppress MixedArgument Don't know why it loses the type */
        return $carry->match(
            fn($carry) => ($this->monitor)($carry, Continuation::new($carry))->match(
                fn($carry) => $this->listen(
                    $carry,
                    $continuation,
                ),
                static fn($carry) => $continuation
                    ->carryWith(Attempt::result($carry))
                    ->finish(),
            ),
            static fn() => $continuation
                ->carryWith($carry)
                ->terminate(),
        );
    }

    /**
     * @template A
     *
     * @param Monoid<A> $monoid
     * @param \Closure(A, Continuation<A>): Continuation<A> $monitor
     * @param \Closure(Message, Continuation_<A>, A): Continuation_<A> $listen
     *
     * @return self<A>
     */
    public static function of(
        Protocol $protocol,
        Address $address,
        Period $timeout,
        Monoid $monoid,
        \Closure $monitor,
        \Closure $listen,
    ): self {
        return new self(
            Unstarted::of(
                $address,
                $timeout,
            ),
            $protocol,
            Abort::disabled(),
            $timeout,
            $monoid,
            $monitor,
            $listen,
        );
    }

    /**
     * @param T $carry
     * @param Scope\Continuation<Attempt<T>> $continuation
     *
     * @return Scope\Continuation<Attempt<T>>
     */
    private function listen(
        mixed $carry,
        Scope\Continuation $continuation,
    ): Scope\Continuation {
        if ($this->server instanceof Unstarted) {
            return $continuation
                ->carryWith(Attempt::error(new \LogicException('Unstarted server')))
                ->terminate();
        }

        return $this
            ->server
            ->accept()
            ->map(fn($socket) => Client::of(
                $socket->timeoutAfter($this->timeout),
                $this->protocol,
                $this->monoid,
                $this->listen,
            ))
            ->map(Sequence::of(...))
            ->match(
                static fn($clients) => $continuation
                    ->carryWith(Attempt::result($carry))
                    ->schedule($clients),
                static fn() => $continuation // restart the loop when no new client within the timeout
                    ->carryWith(Attempt::result($carry)),
            );
    }
}
