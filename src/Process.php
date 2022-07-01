<?php
declare(strict_types = 1);

namespace Innmind\IPC;

use Innmind\TimeContinuum\ElapsedPeriod;
use Innmind\Immutable\Maybe;

interface Process
{
    public function name(): Process\Name;
    public function send(Message ...$messages): void;

    /**
     * @return Maybe<Message>
     */
    public function wait(ElapsedPeriod $timeout = null): Maybe;
    public function close(): void;
    public function closed(): bool;
}
