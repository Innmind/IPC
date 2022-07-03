<?php
declare(strict_types = 1);

namespace Innmind\IPC;

/**
 * @psalm-immutable
 */
final class Continuation
{
    private ?Client $closed;
    private ?Message $response;
    private bool $stop;

    private function __construct(
        Client $closed = null,
        Message $response = null,
        bool $stop = false,
    ) {
        $this->closed = $closed;
        $this->response = $response;
        $this->stop = $stop;
    }

    public static function start(): self
    {
        return new self;
    }

    /**
     * This will send the given message to the client
     */
    public function respond(Message $message): self
    {
        return new self(response: $message);
    }

    /**
     * The client will be closed and then garbage collected
     */
    public function close(Client $client): self
    {
        return new self(closed: $client);
    }

    /**
     * The server will be gracefully shutdown
     */
    public function stop(): self
    {
        return new self(stop: true);
    }

    /**
     * @internal
     * @template A
     * @template B
     * @template C
     * @template D
     *
     * @param callable(Message): A $onResponse
     * @param callable(Client): B $onClose
     * @param callable(): C $onContinue
     * @param callable(): D $onStop
     *
     * @return A|B|C|D
     */
    public function match(
        callable $onResponse,
        callable $onClose,
        callable $onStop,
        callable $onContinue,
    ): mixed {
        if ($this->response instanceof Message) {
            /** @psalm-suppress ImpureFunctionCall */
            return $onResponse($this->response);
        }

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
