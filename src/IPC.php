<?php
declare(strict_types = 1);

namespace Innmind\IPC;

use Innmind\TimeContinuum\ElapsedPeriodInterface;
use Innmind\Immutable\SetInterface;

interface IPC
{
    /**
     * @return SetInterface<Process\Name> All processes waiting for messages
     */
    public function processes(): SetInterface;

    /**
     * @throws FailedToConnect
     */
    public function get(Process\Name $name): Process;
    public function exist(Process\Name $name): bool;
    public function wait(Process\Name $name, ElapsedPeriodInterface $timeout = null): void;
    public function listen(Process\Name $self, ElapsedPeriodInterface $timeout = null): Server;
}
