<?php
declare(strict_types = 1);

namespace Innmind\IPC;

interface Server
{
    /**
     * @param callable(Message, Client, Continuation): Continuation $listen
     */
    public function __invoke(callable $listen): void;
}
