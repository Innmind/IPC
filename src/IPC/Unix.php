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
    Clock,
    ElapsedPeriod,
    Earth\Period\Millisecond,
};
use Innmind\Filesystem\{
    Adapter,
    File,
    Name as FileName,
};
use Innmind\OperatingSystem\{
    Sockets,
    CurrentProcess,
};
use Innmind\Socket\Address\Unix as Address;
use Innmind\Url\Path;
use Innmind\Immutable\Set;

final class Unix implements IPC
{
    private Sockets $sockets;
    private Adapter $filesystem;
    private Clock $clock;
    private CurrentProcess $process;
    private Protocol $protocol;
    private Path $path;
    private ElapsedPeriod $heartbeat;

    public function __construct(
        Sockets $sockets,
        Adapter $filesystem,
        Clock $clock,
        CurrentProcess $process,
        Protocol $protocol,
        Path $path,
        ElapsedPeriod $heartbeat
    ) {
        if (!$path->directory()) {
            throw new LogicException("Path must be a directory, got '{$path->toString()}'");
        }

        $this->sockets = $sockets;
        $this->filesystem = $filesystem;
        $this->clock = $clock;
        $this->process = $process;
        $this->protocol = $protocol;
        $this->path = $path;
        $this->heartbeat = $heartbeat;
    }

    /**
     * {@inheritdoc}
     */
    public function processes(): Set
    {
        return $this
            ->filesystem
            ->all()
            ->mapTo(
                Process\Name::class,
                static fn(File $file): Process\Name => new Process\Name($file->name()->toString()),
            );
    }

    /**
     * {@inheritdoc}
     */
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
            $this->heartbeat
        );
    }

    public function exist(Process\Name $name): bool
    {
        return $this->filesystem->contains(new FileName("$name.sock"));
    }

    public function wait(Process\Name $name, ElapsedPeriod $timeout = null): void
    {
        $start = $this->clock->now();

        do {
            if ($this->exist($name)) {
                return;
            }

            if (
                $timeout instanceof ElapsedPeriod &&
                $this->clock->now()->elapsedSince($start)->longerThan($timeout)
            ) {
                return;
            }

            $this->process->halt(new Millisecond($this->heartbeat->milliseconds()));
        } while (true);
    }

    public function listen(Process\Name $self, ElapsedPeriod $timeout = null): Server
    {
        return new Server\Unix(
            $this->sockets,
            $this->protocol,
            $this->clock,
            $this->process->signals(),
            $this->addressOf((string) $self),
            $this->heartbeat,
            $timeout
        );
    }

    private function addressOf(string $name): Address
    {
        return new Address(
            $this->path->resolve(Path::of($name)),
        );
    }
}
