<?php
declare(strict_types = 1);

namespace Innmind\IPC;

interface Client
{
    public function send(Message $message): void;
    public function close(): void;
    public function closed(): bool;
}
