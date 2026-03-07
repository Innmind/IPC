<?php
declare(strict_types = 1);

namespace Innmind\IPC;

use Innmind\Time\Period;
use Innmind\Immutable\{
    Attempt,
    Sequence,
    SideEffect,
};

final class IPC
{
    private function __construct()
    {
    }

    /**
     * @return Sequence<Process\Name>
     */
    public function processes(): Sequence
    {
        return Sequence::of();
    }

    /**
     * @return Attempt<Process>
     */
    public function connectTo(
        Process\Name $name,
        ?Period $timeout = null,
    ): Attempt {
        return Attempt::error(new \Exception);
    }

    /**
     * @return Server<SideEffect>
     */
    public function serve(Process\Name $name): Server
    {
        return Server::of();
    }
}
