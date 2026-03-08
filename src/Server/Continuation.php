<?php
declare(strict_types = 1);

namespace Innmind\IPC\Server;

use Innmind\IPC\Server\Continuation\Next;

/**
 * @psalm-immutable
 * @template T
 */
final class Continuation
{
    /**
     * @param T $carry
     */
    private function __construct(
        private Next $next,
        private mixed $carry,
    ) {
    }

    /**
     * @psalm-pure
     * @template A
     *
     * @param A $carry
     *
     * @return self<A>
     */
    public static function new(mixed $carry): self
    {
        return new self(
            Next::continue,
            $carry,
        );
    }

    /**
     * @param T $carry
     *
     * @return self<T>
     */
    public function carryWith(mixed $carry): self
    {
        return new self($this->next, $carry);
    }

    /**
     * @return self<T>
     */
    public function finish(): self
    {
        return new self(Next::finish, $this->carry);
    }

    /**
     * @template R
     *
     * @param callable(T): R $continue
     * @param callable(T): R $finish
     *
     * @return R
     */
    public function match(
        callable $continue,
        callable $finish,
    ): mixed {
        /** @psalm-suppress ImpureFunctionCall */
        return match ($this->next) {
            Next::continue => $continue($this->carry),
            Next::finish => $finish($this->carry),
        };
    }
}
