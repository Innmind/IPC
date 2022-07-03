<?php
declare(strict_types = 1);

namespace Innmind\IPC;

/**
 * @psalm-immutable
 */
final class Continuation
{
    private ?Client $closed;

    private function __construct(
        Client $closed = null,
    ) {
        $this->closed = $closed;
    }

    public static function start(): self
    {
        return new self;
    }

    public function close(Client $client): self
    {
        return new self($client);
    }

    /**
     * @internal
     * @template A
     * @template B
     *
     * @param callable(Client): A $onClose
     * @param callable(): B $onContinue
     *
     * @return A|B
     */
    public function match(
        callable $onClose,
        callable $onContinue,
    ): mixed {
        if ($this->closed instanceof Client) {
            /** @psalm-suppress ImpureFunctionCall */
            return $onClose($this->closed);
        }

        /** @psalm-suppress ImpureFunctionCall */
        return $onContinue();
    }
}
