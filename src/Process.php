<?php
declare(strict_types = 1);

namespace Innmind\IPC;

use Innmind\TimeContinuum\ElapsedPeriodInterface;

interface Process
{
    public function name(): Process\Name;
    public function send(Process\Name $sender, Message ...$messages): void;
    public function listen(ElapsedPeriodInterface $timeout = null): Receiver;
}
