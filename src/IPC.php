<?php
declare(strict_types = 1);

namespace Innmind\IPC;

use Innmind\TimeContinuum\ElapsedPeriod;
use Innmind\Immutable\{
    Set,
    Maybe,
};

interface IPC
{
    /**
     * @return Set<Process\Name> All processes waiting for messages
     */
    public function processes(): Set;

    /**
     * @return Maybe<Process>
     */
    public function get(Process\Name $name): Maybe;
    public function exist(Process\Name $name): bool;
    public function wait(Process\Name $name, ElapsedPeriod $timeout = null): void;
    public function listen(Process\Name $self, ElapsedPeriod $timeout = null): Server;
}
