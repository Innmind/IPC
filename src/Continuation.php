<?php
declare(strict_types = 1);

namespace Innmind\IPC;

/**
 * @psalm-immutable
 */
final class Continuation
{
    private Client $client;
    private bool $closed;
    private ?Message $response;
    private bool $stop;

    private function __construct(
        Client $client,
        bool $closed = false,
        Message $response = null,
        bool $stop = false,
    ) {
        $this->client = $client;
        $this->closed = $closed;
        $this->response = $response;
        $this->stop = $stop;
    }

    public static function start(Client $client): self
    {
        return new self($client);
    }

    /**
     * This will send the given message to the client
     */
    public function respond(Message $message): self
    {
        return new self($this->client, response: $message);
    }

    /**
     * The client will be closed and then garbage collected
     */
    public function close(): self
    {
        return new self($this->client, closed: true);
    }

    /**
     * The server will be gracefully shutdown
     */
    public function stop(): self
    {
        return new self($this->client, stop: true);
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
     * @param callable(Client): C $onContinue
     * @param callable(Client): D $onStop
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
