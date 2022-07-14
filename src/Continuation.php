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
     * @return T
     */
    public function carried(): mixed
    {
        return $this->carry;
    }

    /**
     * This will send the given message to the client
     *
     * @return self<T>
     */
    public function respond(Message $message): self
    {
        return new self($this->client, $this->carry, response: $message);
    }

    /**
     * The client will be closed and then garbage collected
     *
     * @return self<T>
     */
    public function close(): self
    {
        return new self($this->client, $this->carry, closed: true);
    }

    /**
     * The server will be gracefully shutdown
     *
     * @return self<T>
     */
    public function stop(): self
    {
        return new self($this->client, $this->carry, stop: true);
    }

    /**
     * @internal
     * @template A
     * @template B
     * @template C
     * @template D
     *
     * @param callable(Client, Message): A $onResponse
     * @param callable(Client): B $onClose
     * @param callable(Client): C $onStop
     * @param callable(Client): D $onContinue
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
            return $onResponse($this->client, $this->response);
        }

        if ($this->closed) {
            /** @psalm-suppress ImpureFunctionCall */
            return $onClose($this->client);
        }

        if ($this->stop) {
            /** @psalm-suppress ImpureFunctionCall */
            return $onStop($this->client);
        }

        /** @psalm-suppress ImpureFunctionCall */
        return $onContinue($this->client);
    }
}
