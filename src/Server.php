<?php
declare(strict_types = 1);

namespace Innmind\IPC;

use Innmind\Immutable\Either;

interface Server
{
    /**
     * @param callable(Message, Continuation): Continuation $listen
     *
     * @return Either<Server\UnableToStart, null>
     */
    public function __invoke(callable $listen): Either;
}
