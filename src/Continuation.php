<?php
declare(strict_types = 1);

namespace Innmind\IPC;

/**
 * @template T
 * @psalm-immutable
 */
final class Continuation
{
    private Client $client;
    /** @var T */
    private mixed $carry;
    private bool $closed;
    private ?Message $response;
    private bool $stop;

    /**
     * @param T $carry
     */
    private function __construct(
        Client $client,
        mixed $carry,
        bool $closed = false,
        Message $response = null,
        bool $stop = false,
    ) {
        $this->client = $client;
        $this->carry = $carry;
        $this->closed = $closed;
        $this->response = $response;
        $this->stop = $stop;
    }

    /**
     * @internal
     * @template A
     *
     * @param A $carry
     *
     * @return self<A>
     */
    public static function start(Client $client, mixed $carry): self
    {
        return new self($client, $carry);
    }

    /**
     * @param T $carry
     *
     * @return self<T>
     */
    public function continue(mixed $carry): self
    {
        return new self($this->client, $carry);
    }

    /**
     * This will send the given message to the client
     *
     * @param T $carry
     *
     * @return self<T>
     */
    public function respond(mixed $carry, Message $message): self
    {
        return new self($this->client, $carry, response: $message);
    }

    /**
     * The client will be closed and then garbage collected
     *
     * @param T $carry
     *
     * @return self<T>
     */
    public function close(mixed $carry): self
    {
        return new self($this->client, $carry, closed: true);
    }

    /**
     * The server will be gracefully shutdown
     *
     * @param T $carry
     *
     * @return self<T>
     */
    public function stop(mixed $carry): self
    {
        return new self($this->client, $carry, stop: true);
    }

    /**
     * @internal
     * @template A
     * @template B
     * @template C
     * @template D
     *
     * @param callable(Client, Message, T): A $onResponse
     * @param callable(Client, T): B $onClose
     * @param callable(Client, T): C $onStop
     * @param callable(Client, T): D $onContinue
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
            return $onResponse($this->client, $this->response, $this->carry);
        }

        if ($this->closed) {
            /** @psalm-suppress ImpureFunctionCall */
            return $onClose($this->client, $this->carry);
        }

        if ($this->stop) {
            /** @psalm-suppress ImpureFunctionCall */
            return $onStop($this->client, $this->carry);
        }

        /** @psalm-suppress ImpureFunctionCall */
        return $onContinue($this->client, $this->carry);
    }
}
