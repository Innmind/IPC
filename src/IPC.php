<?php
declare(strict_types = 1);

namespace Innmind\IPC;

use Innmind\TimeContinuum\ElapsedPeriod;
use Innmind\Immutable\Set;

interface IPC
{
    /**
     * @return Set<Process\Name> All processes waiting for messages
     */
    public function processes(): Set;

    /**
     * @throws Exception\FailedToConnect
     */
    public function get(Process\Name $name): Process;
    public function exist(Process\Name $name): bool;
    public function wait(Process\Name $name, ElapsedPeriod $timeout = null): void;
    public function listen(Process\Name $self, ElapsedPeriod $timeout = null): Server;
}
