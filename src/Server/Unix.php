<?php
declare(strict_types = 1);

namespace Innmind\IPC\Server;

use Innmind\IPC\{
    Server,
    Protocol,
    Client,
    Exception\Stop,
    Exception\RuntimeException,
};
use Innmind\OperatingSystem\{
    Sockets,
    CurrentProcess\Signals,
};
use Innmind\Signals\Signal;
use Innmind\Socket\{
    Address\Unix as Address,
    Server as ServerSocket,
    Server\Connection,
};
use Innmind\TimeContinuum\{
    Clock,
    ElapsedPeriod,
    PointInTime,
};
use Innmind\Immutable\{
    Map,
    Set,
    Maybe,
};

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

    public function __invoke(callable $listen): void
    {
        $server = $this->sockets->open($this->address)->match(
            static fn($server) => $server,
            static fn() => throw new RuntimeException,
        );
        $connections = Connections::start(
            $this->sockets->watch($this->heartbeat),
            $server,
        );
        $iteration = Unix\Iteration::first(
            $this->protocol,
            $this->clock,
            $connections,
            $this->heartbeat,
            $this->timeout,
        );
        $shutdown = static function() use (&$iteration): void {
            /** @var Unix\Iteration $iteration */
            $iteration->startShutdown();
        };
        $this->registerSignals($shutdown);

        do {
            try {
                /**
                 * @psalm-suppress MixedMethodCall Due to the reference above for the shutdown
                 * @var Unix\Iteration
                 */
                $iteration = $iteration->next($listen)->match(
                    static fn($iteration) => $iteration,
                    static fn() => null,
                );
            } catch (Stop) {
                $this->unregisterSignals($shutdown);

                return;
            } catch (\Throwable $e) {
                $this->unregisterSignals($shutdown);

                throw $e;
            }
        } while (!\is_null($iteration));

        $this->unregisterSignals($shutdown);
    }

    /**
     * @param callable(): void $shutdown
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
     * @param callable(): void $shutdown
     */
    private function unregisterSignals(callable $shutdown): void
    {
        $this->signals->remove($shutdown);
    }
}
