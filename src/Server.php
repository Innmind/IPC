<?php
declare(strict_types = 1);

namespace Innmind\IPC;

use Innmind\Immutable\Either;

interface Server
{
    /**
     * @template C
     *
     * @param C $carry
     * @param callable(Message, Continuation): Continuation $listen
     *
     * @return Either<Server\UnableToStart, C>
     */
    public function __invoke(mixed $carry, callable $listen): Either;
}
