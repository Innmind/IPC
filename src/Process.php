<?php
declare(strict_types = 1);

namespace Innmind\IPC;

interface Process
{
    public function name(): Process\Name;
    public function send(Message $message): void;
    public function listen(callable $listen): void;
}
