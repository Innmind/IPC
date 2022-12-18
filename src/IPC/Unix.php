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
    Name as FileName,
};
use Innmind\OperatingSystem\{
    Sockets,
    CurrentProcess,
};
use Innmind\Socket\Address\Unix as Address;
use Innmind\Url\Path;
use Innmind\Immutable\{
    Set,
    Maybe,
};

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
        ElapsedPeriod $heartbeat,
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

    public function processes(): Set
    {
        /**
         * @psalm-suppress DeprecatedMethod while both major versions are supported
         * @var Set<Process\Name>
         */
        return $this
            ->filesystem
            ->all()
            ->map(static fn($file) => Process\Name::maybe($file->name()->toString())->match(
                static fn($name) => $name,
                static fn() => null,
            ))
            ->filter(static fn($name) => $name instanceof Process\Name);
    }

    public function get(Process\Name $name): Maybe
    {
        if (!$this->exist($name)) {
            /** @var Maybe<Process> */
            return Maybe::nothing();
        }

        return Process\Unix::of(
            $this->sockets,
            $this->protocol,
            $this->clock,
            $this->addressOf($name->toString()),
            $name,
            $this->heartbeat,
        );
    }

    public function exist(Process\Name $name): bool
    {
        return $this->filesystem->contains(new FileName("{$name->toString()}.sock"));
    }

    public function wait(Process\Name $name, ElapsedPeriod $timeout = null): Maybe
    {
        $start = $this->clock->now();

        while (!$this->exist($name)) {
            if (
                $timeout instanceof ElapsedPeriod &&
                $this->clock->now()->elapsedSince($start)->longerThan($timeout)
            ) {
                /** @var Maybe<Process> */
                return Maybe::nothing();
            }

            $this->process->halt(new Millisecond($this->heartbeat->milliseconds()));
        }

        return $this->get($name);
    }

    public function listen(Process\Name $self, ElapsedPeriod $timeout = null): Server
    {
        return new Server\Unix(
            $this->sockets,
            $this->protocol,
            $this->clock,
            $this->process->signals(),
            $this->addressOf($self->toString()),
            $this->heartbeat,
            $timeout,
        );
    }

    private function addressOf(string $name): Address
    {
        return new Address(
            $this->path->resolve(Path::of($name)),
        );
    }
}
