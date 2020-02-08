<?php
declare(strict_types = 1);

namespace Innmind\IPC;

use Innmind\TimeContinuum\ElapsedPeriod;

interface Process
{
    public function name(): Process\Name;
    public function send(Message ...$messages): void;

    /**
     * @throws Exception\Timedout
     */
    public function wait(ElapsedPeriod $timeout = null): Message;
    public function close(): void;
    public function closed(): bool;
}
