<?php
declare(strict_types = 1);

namespace Innmind\IPC;

use Innmind\Stream\Readable;
use Innmind\Immutable\Str;

interface Protocol
{
    public function encode(Message $message): Str;

    /**
     * @throws Exception\NoMessage When the connection is closed
     */
    public function decode(Readable $stream): Message;
}
