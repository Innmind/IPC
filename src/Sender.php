<?php
declare(strict_types = 1);

namespace Innmind\IPC;

interface Sender
{
    public function __invoke(Message ...$messages): void;
}
