<?php
declare(strict_types = 1);

namespace Innmind\IPC\Process;

use Innmind\IPC\{
    Process,
    Protocol,
    Receiver,
    Sender,
};
use Innmind\OperatingSystem\Sockets;
use Innmind\Socket\Address\Unix as Address;
use Innmind\TimeContinuum\{
    TimeContinuumInterface,
    ElapsedPeriodInterface,
    ElapsedPeriod,
};

final class Unix implements Process
{
    private $sockets;
    private $protocol;
    private $clock;
    private $address;
    private $name;
    private $selectTimeout;

    public function __construct(
        Sockets $sockets,
        Protocol $protocol,
        TimeContinuumInterface $clock,
        Address $address,
        Name $name,
        ElapsedPeriod $selectTimeout
    ) {
        $this->sockets = $sockets;
        $this->protocol = $protocol;
        $this->clock = $clock;
        $this->address = $address;
        $this->name = $name;
        $this->selectTimeout = $selectTimeout;
    }

    public function name(): Name
    {
        return $this->name;
    }

    public function send(Name $sender): Sender
    {
        return new Sender\Unix(
            $this->sockets,
            $this->protocol,
            $this->address,
            $sender
        );
    }

    public function listen(ElapsedPeriodInterface $timeout = null): Receiver
    {
        return new Receiver\UnixClient(
            $this->sockets,
            $this->protocol,
            $this->clock,
            $this->name,
            $this->address,
            $this->selectTimeout,
            $timeout
        );
    }
}
