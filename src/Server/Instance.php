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
};

final class Instance
{
    private function __construct(
        private Server|Unstarted $server,
        private Protocol $protocol,
        private Period $timeout,
    ) {
    }

    /**
     * @template T
     *
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

        return $this
            ->server
            ->accept()
            ->map(fn($socket) => Client::of(
                $socket->timeoutAfter($this->timeout),
                $this->protocol,
            ))
            ->map(Sequence::of(...))
            ->match(
                static fn($clients) => $continuation->schedule($clients),
                static fn() => $continuation, // restart the loop when no new client within the timeout
            );
    }

    public static function of(
        Protocol $protocol,
        Address $address,
        Period $timeout,
    ): self {
        return new self(
            Unstarted::of(
                $address,
                $timeout,
            ),
            $protocol,
            $timeout,
        );
    }
}
