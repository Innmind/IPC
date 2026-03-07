<?php
declare(strict_types = 1);

namespace Innmind\IPC;

use Innmind\Time\Period;
use Innmind\Immutable\{
    Sequence,
    Attempt,
    SideEffect,
};

final class Process
{
    private function __construct()
    {
    }

    /**
     * @param Sequence<Message> $messages
     *
     * @return Attempt<SideEffect>
     */
    public function send(Sequence $messages): Attempt
    {
        return Attempt::result(SideEffect::identity);
    }

    /**
     * @return Attempt<Message>
     */
    public function wait(?Period $timeout = null): Attempt
    {
        return Attempt::error(new \RuntimeException);
    }

    /**
     * @return Attempt<SideEffect>
     */
    public function close(): Attempt
    {
        return Attempt::result(SideEffect::identity);
    }
}
