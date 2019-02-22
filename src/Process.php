<?php
declare(strict_types = 1);

namespace Innmind\IPC;

use Innmind\TimeContinuum\ElapsedPeriodInterface;

interface Process
{
    public function name(): Process\Name;
    public function send(Message ...$messages): void;

    /**
     * @throws Timedout
     */
    public function wait(ElapsedPeriodInterface $timeout = null): Message;
    public function close(): void;
    public function closed(): bool;
}
