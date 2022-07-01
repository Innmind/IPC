<?php
declare(strict_types = 1);

namespace Innmind\IPC;

use Innmind\Stream\Readable;
use Innmind\Immutable\{
    Str,
    Maybe,
};

interface Protocol
{
    public function encode(Message $message): Str;

    /**
     * @return Maybe<Message>
     */
    public function decode(Readable $stream): Maybe;
}
