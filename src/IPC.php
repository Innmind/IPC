<?php
declare(strict_types = 1);

namespace Innmind\IPC;

use Innmind\OperatingSystem\OperatingSystem;
use Innmind\Filesystem\{
    Adapter,
    Name as FileName,
};
use Innmind\IO\Sockets\Unix\Address;
use Innmind\Time\Period;
use Innmind\Url\Path;
use Innmind\Immutable\{
    Attempt,
    Sequence,
    SideEffect,
};

final class IPC
{
    private function __construct(
        private OperatingSystem $os,
        private Adapter $filesystem,
        private Protocol $protocol,
        private Path $path,
        private Period $heartbeat,
    ) {
    }

    public static function of(
        OperatingSystem $os,
        Path $path,
        ?Period $heartbeat = null,
    ): self {
        return new self(
            $os,
            $os
                ->filesystem()
                ->mount($path)
                ->unwrap(),
            Protocol::binary(),
            $path,
            $heartbeat ?? Period::second(1),
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
        $start = $this->os->clock()->now();

        return Sequence::lazy(function() use ($file) {
            while (!$this->filesystem->contains($file)) {
                yield $this->os->clock()->now();
            }
        })
            ->map(
                fn($now) => $this
                    ->os
                    ->process()
                    ->halt($this->heartbeat)
                    ->map(static fn() => $now->elapsedSince($start)),
            )
            ->sink(SideEffect::identity)
            ->attempt(static fn($_, $halted) => $halted->flatMap(
                static fn($elapsed) => match ($timeout) {
                    null => Attempt::result($_),
                    default => match ($elapsed->longerThan($timeout->asElapsedPeriod())) {
                        true => Attempt::error(new \RuntimeException('Timeout')),
                        false => Attempt::result($_),
                    },
                },
            ))
            ->flatMap(fn() => Process::of(
                $this->os,
                $this->protocol,
                $this->addressOf($name),
                $this->heartbeat,
            ));
    }

    /**
     * @return Server<SideEffect>
     */
    public function serve(Process\Name $name): Server
    {
        return Server::of(
            $this->os,
            $this->protocol,
            $this->addressOf($name),
            $this->heartbeat,
        );
    }

    private function addressOf(Process\Name $name): Address
    {
        return Address::of(
            $this->path->resolve(Path::of($name->toString())),
        );
    }
}
