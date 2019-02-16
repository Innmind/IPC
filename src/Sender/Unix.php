<?php
declare(strict_types = 1);

namespace Innmind\IPC\Sender;

use Innmind\IPC\{
    Sender,
    Protocol,
    Message,
    Process\Name,
    Exception\RuntimeException,
};
use Innmind\OperatingSystem\Sockets;
use Innmind\Socket\{
    Address\Unix as Address,
    Client,
    Exception\Exception as Socket,
};
use Innmind\Stream\Exception\Exception as Stream;
use Innmind\Filesystem\MediaType\MediaType;
use Innmind\Immutable\Str;

final class Unix implements Sender
{
    private $sockets;
    private $protocol;
    private $address;
    private $name;
    private $socket;

    public function __construct(
        Sockets $sockets,
        Protocol $protocol,
        Address $address,
        Name $name
    ) {
        $this->sockets = $sockets;
        $this->protocol = $protocol;
        $this->address = $address;
        $this->name = $name;
    }

    public function __invoke(Message ...$messages): void
    {
        try {
            $socket = $this->socket();

            foreach ($messages as $message) {
                $socket->write($this->protocol->encode($message));
            }
        } catch (Stream | Socket $e) {
            throw new RuntimeException('', 0, $e);
        }
    }

    private function socket(): Client
    {
        if (!$this->socket instanceof Client || $this->socket->closed()) {
            $this->socket = $this->sockets->connectTo($this->address);
            $this->socket->write(
                $this->protocol->encode(
                    new Message\Generic(
                        MediaType::fromString('text/plain'),
                        Str::of((string) $this->name)
                    )
                )
            );
        }

        return $this->socket;
    }

    public function __destruct()
    {
        if ($this->socket instanceof Client) {
            $this->socket->close();
        }
    }
}
