<?php
declare(strict_types = 1);

namespace Innmind\IPC;

use Innmind\TimeContinuum\ElapsedPeriodInterface;

interface IPC
{
    /**
     * @return SetInterface<Process> All processes waiting for messages
     */
    public function processes(): SetInterface;
    public function get(Name $name): Process;
    public function listen(Name $self, ElapsedPeriodInterface $timeout = null): Receiver;
}
