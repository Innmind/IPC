<?php
declare(strict_types = 1);

namespace Innmind\IPC;

use Innmind\Immutable\{
    Maybe,
    SideEffect,
};

interface Client
{
    /**
     * @return Maybe<self> Returns nothing when it fails to send the message
     */
    public function send(Message $message): Maybe;

    /**
     * Close the underlying connection
     *
     * @return Maybe<SideEffect> Returns nothing when it fails to close properly
     */
    public function close(): Maybe;
}
