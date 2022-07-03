<?php
declare(strict_types = 1);

namespace Innmind\IPC;

/**
 * @psalm-immutable
 */
final class Continuation
{
    private ?Client $closed;
    private bool $stop;

    private function __construct(
        Client $closed = null,
        bool $stop = false,
    ) {
        $this->closed = $closed;
        $this->stop = $stop;
    }

    public static function start(): self
    {
        return new self;
    }

    public function close(Client $client): self
    {
        return new self($client);
    }

    public function stop(): self
    {
        return new self(null, true);
    }

    /**
     * @internal
     * @template A
     * @template B
     * @template C
     *
     * @param callable(Client): A $onClose
     * @param callable(): B $onContinue
     * @param callable(): C $onStop
     *
     * @return A|B|C
     */
    public function match(
        callable $onClose,
        callable $onStop,
        callable $onContinue,
    ): mixed {
        if ($this->closed instanceof Client) {
            /** @psalm-suppress ImpureFunctionCall */
            return $onClose($this->closed);
        }

        if ($this->stop) {
            /** @psalm-suppress ImpureFunctionCall */
            return $onStop();
        }

        /** @psalm-suppress ImpureFunctionCall */
        return $onContinue();
    }
}
