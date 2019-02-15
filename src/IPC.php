<?php
declare(strict_types = 1);

namespace Innmind\IPC;

use Innmind\TimeContinuum\ElapsedPeriodInterface;
use Innmind\Immutable\SetInterface;

interface IPC
{
    /**
     * @return SetInterface<Process> All processes waiting for messages
     */
    public function processes(): SetInterface;
    public function get(Process\Name $name): Process;
    public function listen(Process\Name $self, ElapsedPeriodInterface $timeout = null): Receiver;
}
