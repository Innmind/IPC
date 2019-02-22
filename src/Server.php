<?php
declare(strict_types = 1);

namespace Innmind\IPC;

interface Server
{
    /**
     * Accepts callable(Message, Client): void
     */
    public function __invoke(callable $listen): void;
}
