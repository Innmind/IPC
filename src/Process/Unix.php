<?php
declare(strict_types = 1);

namespace Innmind\IPC\Process;

use Innmind\IPC\{
    Process,
    Protocol,
    Receiver,
    Message,
};
use Innmind\OperatingSystem\Sockets;
use Innmind\Socket\Address\Unix as Address;
use Innmind\TimeContinuum\ElapsedPeriodInterface;
use Innmind\Filesystem\MediaType\MediaType;
use Innmind\Immutable\Str;

final class Unix implements Process
{
    private $sockets;
    private $protocol;
    private $address;
    private $name;

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

    public function name(): Name
    {
        return $this->name;
    }

    public function send(Message ...$messages): void
    {
        $socket = $this->sockets->connectTo($this->address);
        $socket->write(
            $this->protocol->encode(
                new Message\Generic(
                    MediaType::fromString('text/plain'),
                    Str::of((string) $this->name)
                )
            )
        );

        try {
            foreach ($messages as $message) {
                $socket->write($this->protocol->encode($message));
            }
        } finally {
            $socket->close();
        }
    }

    public function listen(ElapsedPeriodInterface $timeout = null): Receiver
    {
        return new Receiver\UnixClient(
            $this->sockets,
            $this->protocol,
            $this,
            $this->address,
            $timeout
        );
    }
}
