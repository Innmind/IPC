<?php
declare(strict_types = 1);

namespace Innmind\IPC\Server;

use Innmind\IPC\Protocol;
use Innmind\Async\Scope\Continuation;
use Innmind\OperatingSystem\OperatingSystem;
use Innmind\IO\Sockets\{
    Servers\Server,
    Unix\Address,
};
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
     */
    private function __construct(
        private Server|Unstarted $server,
        private Protocol $protocol,
        private Period $timeout,
        private Monoid $monoid,
    ) {
    }

    /**
     * @param Attempt<T> $carry
     * @param Continuation<Attempt<T>> $continuation
     *
     * @return Continuation<Attempt<T>>
     */
    public function __invoke(
        Attempt $carry,
        OperatingSystem $os,
        Continuation $continuation,
    ): Continuation {
        if ($this->server instanceof Unstarted) {
            return ($this->server)($os)
                ->map(function($server) {
                    $this->server = $server;

                    return $server;
                })
                ->match(
                    static fn() => $continuation,
                    static fn($e) => $continuation
                        ->carryWith(Attempt::error($e))
                        ->finish(),
                );
        }

        /** @var Sequence<T> */
        $all = Sequence::of();
        /** @var Sequence<Attempt<T>> */
        $results = $continuation->results();
        $carry = $results
            ->prepend(Sequence::of($carry))
            ->sink($all)
            ->attempt(static fn($all, $result) => $result->map($all))
            ->map(fn($results) => $results->fold($this->monoid));
        $continuation = $continuation->carryWith($carry);

        return $this
            ->server
            ->accept()
            ->map(fn($socket) => Client::of(
                $socket->timeoutAfter($this->timeout),
                $this->protocol,
                $this->monoid,
            ))
            ->map(Sequence::of(...))
            ->match(
                static fn($clients) => $continuation->schedule($clients),
                static fn() => $continuation, // restart the loop when no new client within the timeout
            );
    }

    /**
     * @template A
     *
     * @param Monoid<A> $monoid
     *
     * @return self<A>
     */
    public static function of(
        Protocol $protocol,
        Address $address,
        Period $timeout,
        Monoid $monoid,
    ): self {
        return new self(
            Unstarted::of(
                $address,
                $timeout,
            ),
            $protocol,
            $timeout,
            $monoid,
        );
    }
}
