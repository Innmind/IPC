<?php
declare(strict_types = 1);

namespace Innmind\IPC\IPC;

use Innmind\IPC\{
    IPC,
    Process,
    Receiver,
    Protocol,
    Exception\LogicException,
};
use Innmind\TimeContinuum\ElapsedPeriodInterface;
use Innmind\Filesystem\Adapter;
use Innmind\OperatingSystem\Sockets;
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
    private $protocol;
    private $path;

    public function __construct(
        Sockets $sockets,
        Adapter $filesystem,
        Protocol $protocol,
        PathInterface $path
    ) {
        $this->sockets = $sockets;
        $this->filesystem = $filesystem;
        $this->protocol = $protocol;
        $this->path = \rtrim((string) $path, '/');
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
                        $this->addressOf($name),
                        new Process\Name($name)
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
            $this->addressOf((string) $name),
            $name
        );
    }

    public function exist(Process\Name $name): bool
    {
        return $this->filesystem->has((string) $name);
    }

    public function listen(Process\Name $self, ElapsedPeriodInterface $timeout = null): Receiver
    {
        return new Receiver\UnixServer(
            $this->sockets,
            $this->protocol,
            $this->addressOf((string) $self),
            $self,
            $timeout
        );
    }

    private function addressOf(string $name): Address
    {
        return new Address("{$this->path}/$name");
    }
}
