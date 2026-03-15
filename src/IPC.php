<?php
declare(strict_types = 1);

namespace Innmind\IPC;

use Innmind\OperatingSystem\OperatingSystem;
use Innmind\Filesystem\{
    Adapter,
    Name as FileName,
    Recover,
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
        ?Path $path = null,
        ?Period $heartbeat = null,
    ): self {
        $path ??= $os->status()->tmp();

        return new self(
            $os,
            $os
                ->filesystem()
                ->mount($path)
                ->recover(Recover::mount(...))
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
            ->map(
                static fn($file) => $file
                    ->name()
                    ->str()
                    ->dropEnd(5)
                    ->toString(),
            )
            ->flatMap(
                static fn($file) => Process\Name::attempt($file)
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
        $file = FileName::of($name->toString().'.sock');
        $name = $this->addressOf($name);
        $start = $this->os->clock()->now();
        $timeout = $timeout?->asElapsedPeriod();

        while (!$this->filesystem->contains($file)) {
            $halted = $this
                ->os
                ->process()
                ->halt($this->heartbeat)
                ->match(
                    static fn($sideEffect) => $sideEffect,
                    static fn($e) => $e,
                );

            if ($halted !== SideEffect::identity) {
                return Attempt::error($halted);
            }

            if (\is_null($timeout)) {
                continue;
            }

            $elapsed = $this
                ->os
                ->clock()
                ->now()
                ->elapsedSince($start);

            if ($elapsed->longerThan($timeout)) {
                return Attempt::error(new \RuntimeException('Timeout'));
            }
        }

        return Process::of(
            $this->os,
            $this->protocol,
            $name,
            $this->heartbeat,
        );
    }

    /**
     * @psalm-mutation-free
     *
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

    /**
     * @psalm-mutation-free
     */
    private function addressOf(Process\Name $name): Address
    {
        return Address::of(
            $this->path->resolve(Path::of($name->toString())),
        );
    }
}
