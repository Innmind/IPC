<?php
declare(strict_types = 1);

namespace Innmind\IPC;

use Innmind\IPC\Continuation\Next;
use Innmind\Immutable\Sequence;

/**
 * @psalm-immutable
 * @template T
 */
final class Continuation
{
    /**
     * @param Sequence<Message> $messages
     * @param T $carry
     */
    private function __construct(
        private Next $next,
        private Sequence $messages,
        private mixed $carry,
    ) {
    }

    /**
     * @internal
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
            Sequence::of(),
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
        return new self(
            $this->next,
            $this->messages,
            $carry,
        );
    }

    /**
     * @param Message|Sequence<Message> $messages
     *
     * @return self<T>
     */
    public function respond(Message|Sequence $messages): self
    {
        if ($messages instanceof Message) {
            $messages = Sequence::of($messages);
        }

        return new self(
            $this->next,
            $messages->prepend($this->messages), // to keep the user provided lazyness
            $this->carry,
        );
    }

    /**
     * @return self<T>
     */
    public function finish(): self
    {
        return new self(
            Next::finish,
            $this->messages,
            $this->carry,
        );
    }

    /**
     * @internal
     * @template R
     *
     * @param callable(T, Sequence<Message>): R $continue
     * @param callable(T, Sequence<Message>): R $finish
     *
     * @return R
     */
    public function match(
        callable $continue,
        callable $finish,
    ): mixed {
        /** @psalm-suppress ImpureFunctionCall */
        return match ($this->next) {
            Next::continue => $continue($this->carry, $this->messages),
            Next::finish => $finish($this->carry, $this->messages),
        };
    }
}
