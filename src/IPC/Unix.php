<?php
declare(strict_types = 1);

namespace Innmind\IPC\IPC;

use Innmind\IPC\{
    IPC,
    Process,
    Server,
    Protocol,
    Exception\LogicException,
};
use Innmind\TimeContinuum\{
    TimeContinuumInterface,
    ElapsedPeriodInterface,
    ElapsedPeriod,
    Period\Earth\Millisecond,
};
use Innmind\Filesystem\Adapter;
use Innmind\OperatingSystem\{
    Sockets,
    CurrentProcess,
};
use Innmind\Socket\Address\Unix as Address;
use Innmind\Url\PathInterface;
use Innmind\Immutable\{
    SetInterface,
    Set,
};

final class Unix implements IPC
{
    private $sockets;
    private $filesystem;
    private $clock;
    private $process;
    private $protocol;
    private $path;
    private $selectTimeout;

    public function __construct(
        Sockets $sockets,
        Adapter $filesystem,
        TimeContinuumInterface $clock,
        CurrentProcess $process,
        Protocol $protocol,
        PathInterface $path,
        ElapsedPeriod $selectTimeout
    ) {
        $this->sockets = $sockets;
        $this->filesystem = $filesystem;
        $this->clock = $clock;
        $this->process = $process;
        $this->protocol = $protocol;
        $this->path = \rtrim((string) $path, '/');
        $this->selectTimeout = $selectTimeout;
    }

    /**
     * {@inheritdoc}
     */
    public function processes(): SetInterface
    {
        return $this
            ->filesystem
            ->all()
            ->keys()
            ->reduce(
                Set::of(Process::class),
                function(SetInterface $processes, string $name): SetInterface {
                    return $processes->add(new Process\Unix(
                        $this->sockets,
                        $this->protocol,
                        $this->clock,
                        $this->addressOf($name),
                        new Process\Name($name),
                        $this->selectTimeout
                    ));
                }
            );
    }

    public function get(Process\Name $name): Process
    {
        if (!$this->exist($name)) {
            throw new LogicException((string) $name);
        }

        return new Process\Unix(
            $this->sockets,
            $this->protocol,
            $this->clock,
            $this->addressOf((string) $name),
            $name,
            $this->selectTimeout
        );
    }

    public function exist(Process\Name $name): bool
    {
        return $this->filesystem->has("$name.sock");
    }

    public function wait(Process\Name $name, ElapsedPeriodInterface $timeout = null): void
    {
        $start = $this->clock->now();

        do {
            if ($this->exist($name)) {
                return;
            }

            if (
                $timeout instanceof ElapsedPeriodInterface &&
                $this->clock->now()->elapsedSince($start)->longerThan($timeout)
            ) {
                return;
            }

            $this->process->halt(new Millisecond($this->selectTimeout->milliseconds()));
        } while (true);
    }

    public function listen(Process\Name $self, ElapsedPeriodInterface $timeout = null): Server
    {
        return new Server\Unix(
            $this->sockets,
            $this->protocol,
            $this->clock,
            $this->addressOf((string) $self),
            $this->selectTimeout,
            $timeout
        );
    }

    private function addressOf(string $name): Address
    {
        return new Address("{$this->path}/$name");
    }
}
