<?php
declare(strict_types = 1);

namespace Innmind\IPC;

interface Receiver
{
    /**
     * Accepts callable(Message, Process): void
     */
    public function __invoke(callable $listen): void;
}
