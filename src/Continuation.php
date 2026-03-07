<?php
declare(strict_types = 1);

namespace Innmind\IPC;

/**
 * @template T
 */
final class Continuation
{
    private function __construct(
        private mixed $carry,
    ) {
    }

    /**
     * @template A
     *
     * @param A $carry
     *
     * @return self<A>
     */
    public static function new(mixed $carry): self
    {
        return new self($carry);
    }
}
