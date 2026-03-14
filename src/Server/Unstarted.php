<?php
declare(strict_types = 1);

namespace Innmind\IPC\Server;

use Innmind\OperatingSystem\OperatingSystem;
use Innmind\IO\Sockets\{
    Servers\Server,
    Unix\Address,
};
use Innmind\Time\Period;
use Innmind\Immutable\Attempt;

/**
 * @internal
 */
final class Unstarted
{
    private function __construct(
        private Address $address,
        private Period $timeout,
    ) {
    }

    /**
     * @return Attempt<Server>
     */
    public function __invoke(OperatingSystem $os): Attempt
    {
        return $os
            ->sockets()
            ->open($this->address)
            ->map(fn($server) => $server->timeoutAfter($this->timeout));
    }

    /**
     * @internal
     */
    public static function of(
        Address $address,
        Period $timeout,
    ): self {
        return new self($address, $timeout);
    }
}
