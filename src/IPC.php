<?php
declare(strict_types = 1);

namespace Innmind\IPC;

use Innmind\OperatingSystem\{
    Sockets,
    CurrentProcess,
};
use Innmind\Filesystem\{
    Adapter,
    Name as FileName,
};
use Innmind\IO\Sockets\Unix\Address;
use Innmind\Time\{
    Clock,
    Period,
};
use Innmind\Url\Path;
use Innmind\Immutable\{
    Attempt,
    Sequence,
    SideEffect,
};

final class IPC
{
    private function __construct(
        private Sockets $sockets,
        private Adapter $filesystem,
        private Clock $clock,
        private CurrentProcess $process,
        private Protocol $protocol,
        private Path $path,
        private Period $heartbeat,
    ) {
    }

    public static function of(
        Sockets $sockets,
        Adapter $filesystem,
        Clock $clock,
        CurrentProcess $process,
        Path $path,
        Period $heartbeat,
    ): self {
        if (!$path->directory()) {
            throw new \LogicException('The path must represent a directory');
        }

        return new self(
            $sockets,
            $filesystem,
            $clock,
            $process,
            Protocol::binary(),
            $path,
            $heartbeat,
        );
    }

    /**
     * @return Sequence<Process\Name>
     */
    public function processes(): Sequence
    {
        return $this
            ->filesystem
            ->root()
            ->all()
            ->flatMap(
                static fn($file) => Process\Name::attempt($file->name()->toString())
                    ->maybe()
                    ->toSequence(),
            );
    }

    /**
     * @return Attempt<Process>
     */
    public function connectTo(
        Process\Name $name,
        ?Period $timeout = null,
    ): Attempt {
        $file = FileName::of($name->toString());
        $start = $this->clock->now();

        return Sequence::lazy(function() use ($file) {
            while (!$this->filesystem->contains($file)) {
                yield $this->clock->now();
            }
        })
            ->map(
                fn($now) => $this
                    ->process
                    ->halt($this->heartbeat)
                    ->map(static fn() => $now->elapsedSince($start)),
            )
            ->sink(SideEffect::identity)
            ->attempt(fn($_, $halted) => $halted->flatMap(
                static fn($elapsed) => match ($timeout) {
                    null => Attempt::result($_),
                    default => match ($elapsed->longerThan($timeout->asElapsedPeriod())) {
                        true => Attempt::error(new \RuntimeException('Timeout')),
                        false => Attempt::result($_),
                    },
                },
            ))
            ->flatMap(fn() => Process::of(
                $this->sockets,
                $this->protocol,
                $this->clock,
                $this->addressOf($name),
                $this->heartbeat,
            ));
    }

    /**
     * @return Server<SideEffect>
     */
    public function serve(Process\Name $name): Server
    {
        return Server::of();
    }

    private function addressOf(Process\Name $name): Address
    {
        return Address::of(
            $this->path->resolve(Path::of($name->toString())),
        );
    }
}
